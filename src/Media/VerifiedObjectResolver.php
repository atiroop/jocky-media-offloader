<?php
/**
 * Maps only current, fully verified attachment resources to the configured CDN.
 *
 * @package Jocky\MediaOffloader\Media
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Media;

use Jocky\MediaOffloader\Config\Configuration;
use Jocky\MediaOffloader\Queue\QueueSchema;
use Jocky\MediaOffloader\Integration\JioDetector;
use Jocky\MediaOffloader\Integration\JioReadiness;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Request-local lookups must see current persisted verification state; names derive from the trusted WP prefix.

/** Request-local bounded cache of exact verified object matches. */
final class VerifiedObjectResolver {
	/** Bounded request-local lookups keyed by attachment and relative path.
	 *
	 * @var array<string, string|null>
	 */
	private array $memo = array();
	/** Verified relative-path map for each attachment, built once per request.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $attachment_maps = array();
	/** Cached attachment-wide eligibility values for admin diagnostics.
	 *
	 * @var array<int, string>
	 */
	private array $eligibility_memo = array();
	/** Memoized current JIO contract state by attachment for this request.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private array $jio_state_memo = array();
	/** Cached current public CDN readiness for this request.
	 *
	 * @var bool|null
	 */
	private ?bool $cdn_ready = null;
	/** Configuration resolver for the allowlisted CDN hostname.
	 *
	 * @var Configuration
	 */
	private Configuration $configuration;

	/** Create the resolver.
	 *
	 * @param Configuration|null $configuration Validated configuration provider.
	 */
	public function __construct( ?Configuration $configuration = null ) {
		$this->configuration = $configuration ?? new Configuration();
	}

	/** Register narrow attachment-aware URL and markup filters. */
	public function register(): void {
		add_filter( 'wp_get_attachment_url', array( $this, 'attachment_url' ), 99, 2 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'image_src' ), 99, 4 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'image_srcset' ), 99, 5 );
		add_filter( 'wp_get_attachment_image', array( $this, 'image_markup' ), 99, 4 );
		add_filter( 'wp_content_img_tag', array( $this, 'content_markup' ), 99, 3 );
		add_filter( 'elementor/widget/render_content', array( $this, 'elementor_markup' ), 99, 2 );
		add_filter( 'jio_delivery_resource_url_v1', array( $this, 'jio_resource' ), 99, 5 );
	}

	/** Resolve an attachment original URL.
	 *
	 * @param string $url Original URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string Resolved CDN URL or original URL.
	 */
	public function attachment_url( string $url, int $attachment_id ): string {
		return $this->resolve( $url, $attachment_id );
	}

	/** Resolve an image source selected by core.
	 *
	 * @param array|false  $image Core image source tuple.
	 * @param int          $attachment_id Attachment ID.
	 * @param string|int[] $size Requested image size.
	 * @param bool         $icon Whether this is a mime icon.
	 * @return array|false Original or resolved source tuple.
	 */
	public function image_src( $image, int $attachment_id, $size, bool $icon ) {
		if ( ! $icon && is_array( $image ) && isset( $image[0] ) && is_string( $image[0] ) ) {
			$image[0] = $this->resolve( $image[0], $attachment_id );
		}
		return $image;
	}

	/** Resolve only core-generated responsive candidates for this attachment.
	 *
	 * @param array  $sources Core candidate source map.
	 * @param array  $size_array Requested dimensions.
	 * @param string $image_src Selected image source.
	 * @param array  $image_meta Current image metadata.
	 * @param int    $attachment_id Attachment ID.
	 * @return array Candidate source map with verified URLs.
	 */
	public function image_srcset( array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ): array {
		foreach ( $sources as &$source ) {
			if ( isset( $source['url'] ) && is_string( $source['url'] ) ) {
				$source['url'] = $this->resolve( $source['url'], $attachment_id );
			}
		}
		unset( $source );
		return $sources;
	}

	/** Rewrite URL attributes on already-rendered media markup without changing its structure.
	 *
	 * @param string       $html Existing media markup.
	 * @param int          $attachment_id Attachment ID.
	 * @param string|int[] $size Requested size.
	 * @param bool         $icon Whether this is a mime icon.
	 * @return string Updated markup.
	 */
	public function image_markup( string $html, int $attachment_id, $size, bool $icon ): string {
		return $icon ? $html : $this->rewrite_markup( $html, $attachment_id );
	}

	/** Rewrite a core-recognized content image with its explicit attachment identity.
	 *
	 * @param string $html Existing image markup.
	 * @param string $context Core content context.
	 * @param int    $attachment_id Matched attachment ID.
	 * @return string Updated markup.
	 */
	public function content_markup( string $html, string $context, int $attachment_id ): string {
		return '' === $context ? $html : $this->rewrite_markup( $html, $attachment_id );
	}

	/** Elementor final widget output path for Image and Image Box widgets only.
	 *
	 * @param string $html Rendered widget HTML.
	 * @param mixed  $widget Elementor widget instance.
	 * @return string Updated widget HTML.
	 */
	public function elementor_markup( string $html, $widget ): string {
		if ( is_admin() || ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || ! in_array( $widget->get_name(), array( 'image', 'image-box' ), true ) || ! method_exists( $widget, 'get_settings_for_display' ) ) {
			return $html;
		}
		$settings = $widget->get_settings_for_display();
		$id       = is_array( $settings ) && is_array( $settings['image'] ?? null ) ? (int) ( $settings['image']['id'] ?? 0 ) : 0;
		return $id > 0 ? $this->rewrite_markup( $html, $id ) : $html;
	}

	/** Parse markup using WordPress's HTML API; any parser error falls back unchanged.
	 *
	 * @param string $html Existing markup.
	 * @param int    $attachment_id Attachment ID.
	 * @return string Updated or original markup.
	 */
	private function rewrite_markup( string $html, int $attachment_id ): string {
		if ( $attachment_id < 1 || $this->is_admin_request() || ! get_option( 'jmo_cdn_delivery', false ) || ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}
		try {
			$processor = new \WP_HTML_Tag_Processor( $html );
			while ( $processor->next_tag() ) {
				$tag = $processor->get_tag();
				if ( ! in_array( $tag, array( 'IMG', 'SOURCE' ), true ) ) {
					continue;
				}
				$src = $processor->get_attribute( 'src' );
				if ( is_string( $src ) ) {
					$processor->set_attribute( 'src', $this->resolve( $src, $attachment_id ) );
				}
				$srcset = $processor->get_attribute( 'srcset' );
				if ( is_string( $srcset ) ) {
					$processor->set_attribute( 'srcset', $this->rewrite_srcset( $srcset, $attachment_id ) );
				}
			}
			$updated = $processor->get_updated_html();
			return is_string( $updated ) ? $updated : $html;
		} catch ( \Throwable ) {
			return $html;
		}
	}

	/** Resolve existing srcset candidates without creating any new candidates.
	 *
	 * @param string $srcset Existing responsive candidates.
	 * @param int    $attachment_id Attachment ID.
	 * @return string Updated candidate list.
	 */
	private function rewrite_srcset( string $srcset, int $attachment_id ): string {
		$items = explode( ',', $srcset );
		foreach ( $items as &$item ) {
			if ( 1 === preg_match( '/^\s*(\S+)(\s+.*)?$/', $item, $matches ) ) {
				$item = $this->resolve( $matches[1], $attachment_id ) . ( $matches[2] ?? '' );
			}
		}
		unset( $item );
		return implode( ',', $items );
	}

	/** Resolve a WordPress URL only when it identifies an exact attachment resource.
	 *
	 * @param string $url Candidate URL.
	 * @param int    $attachment_id Attachment ID.
	 * @return string CDN URL or unchanged original URL.
	 */
	public function resolve( string $url, int $attachment_id ): string {
		if ( $attachment_id < 1 || 'attachment' !== get_post_type( $attachment_id ) || $this->is_admin_request() || ! get_option( 'jmo_cdn_delivery', false ) ) {
			return $url;
		}
		$domain = $this->configuration->all()['cdn_domain'];
		if ( '' === $domain ) {
			return $url;
		}
		if ( ! $this->delivery_ready() ) {
			return $url;
		}
		$relative = $this->relative_path( $url );
		if ( null === $relative ) {
			return $url;
		}
		$key = $attachment_id . '|' . $relative;
		if ( ! array_key_exists( $key, $this->memo ) ) {
			$this->memo[ $key ] = $this->lookup( $attachment_id, $relative );
		}
		$object_key = $this->memo[ $key ];
		return is_string( $object_key ) ? $this->public_url( $domain, $object_key, $url ) : $url;
	}

	/** Require a fresh successful public CDN test before rewriting any URL. */
	private function delivery_ready(): bool {
		if ( null === $this->cdn_ready ) {
			$result          = ( new \Jocky\MediaOffloader\Storage\CdnReadiness() )->current();
			$this->cdn_ready = is_array( $result ) && empty( $result['stale'] ) && 'ready' === ( $result['status'] ?? '' );
		}
		return $this->cdn_ready;
	}

	/** Exclude dashboard, AJAX, and REST contexts used for media editing. */
	private function is_admin_request(): bool {
		return is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() );
	}

	/** Map a JIO-selected picture resource under JIO's versioned public contract.
	 *
	 * @param string $url Original JIO resource URL.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $variant JIO variant key.
	 * @param string $mime Resource MIME type.
	 * @param string $generation JIO generation digest.
	 * @return string Verified CDN URL or original URL.
	 */
	public function jio_resource( string $url, int $attachment_id, string $variant, string $mime, string $generation = '' ): string {
		if ( '' !== $generation && 1 !== preg_match( '/\A[a-f0-9]{64}\z/', $generation ) ) {
			return $url;
		}
		return $this->resolve( $url, $attachment_id );
	}

	/** Describe an attachment's delivery eligibility without changing its URL.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $job_status Current JMO job state.
	 * @return string Safe administrator status.
	 */
	public function eligibility( int $attachment_id, string $job_status ): string {
		if ( 'pending_optimization' === $job_status ) {
			return __( 'Waiting for JIO', 'jocky-media-offloader' );
		}
		if ( in_array( $job_status, array( 'pending', 'processing' ), true ) ) {
			return __( 'Queued', 'jocky-media-offloader' );
		}
		if ( 'uploaded' === $job_status ) {
			return __( 'Uploaded but unverified', 'jocky-media-offloader' );
		}
		if ( isset( $this->eligibility_memo[ $attachment_id ] ) ) {
			return $this->eligibility_memo[ $attachment_id ];
		}
		if ( 'verified' !== $job_status || 'attachment' !== get_post_type( $attachment_id ) ) {
			return __( 'Local fallback only', 'jocky-media-offloader' );
		}
		$uploads   = wp_upload_dir();
		$root      = realpath( (string) ( $uploads['basedir'] ?? '' ) );
		$file      = get_attached_file( $attachment_id, true );
		$real_file = is_string( $file ) ? realpath( $file ) : false;
		if ( false === $root || false === $real_file || ! str_starts_with( wp_normalize_path( $real_file ), rtrim( wp_normalize_path( $root ), '/' ) . '/' ) ) {
			$this->eligibility_memo[ $attachment_id ] = __( 'Stale / needs re-offload', 'jocky-media-offloader' );
			return $this->eligibility_memo[ $attachment_id ];
		}
		$relative = ltrim( substr( wp_normalize_path( $real_file ), strlen( rtrim( wp_normalize_path( $root ), '/' ) ) ), '/' );
		$key      = $this->lookup( $attachment_id, $relative );
		if ( null === $key ) {
			$this->eligibility_memo[ $attachment_id ] = __( 'Stale / needs re-offload', 'jocky-media-offloader' );
			return $this->eligibility_memo[ $attachment_id ];
		}
		$readiness                                = ( new \Jocky\MediaOffloader\Storage\CdnReadiness() )->current();
		$eligible                                 = is_array( $readiness ) && empty( $readiness['stale'] ) && 'ready' === $readiness['status'];
		$this->eligibility_memo[ $attachment_id ] = $eligible ? __( 'Verified and CDN-eligible', 'jocky-media-offloader' ) : __( 'Local fallback only', 'jocky-media-offloader' );
		return $this->eligibility_memo[ $attachment_id ];
	}

	/** Resolve an URL path beneath the configured local uploads URL.
	 *
	 * @param string $url Candidate local URL.
	 * @return string|null Confined relative path.
	 */
	private function relative_path( string $url ): ?string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) ) {
			return null;
		}
		$base = wp_parse_url( (string) $uploads['baseurl'] );
		$part = wp_parse_url( $url );
		if ( ! is_array( $base ) || ! is_array( $part ) || empty( $base['host'] ) || empty( $part['host'] ) || strtolower( (string) $base['host'] ) !== strtolower( (string) $part['host'] ) ) {
			return null;
		}
		$base_path = untrailingslashit( rawurldecode( (string) ( $base['path'] ?? '' ) ) );
		$path      = rawurldecode( (string) ( $part['path'] ?? '' ) );
		if ( ! str_starts_with( $path, $base_path . '/' ) ) {
			return null;
		}
		$relative = substr( $path, strlen( $base_path ) + 1 );
		if ( '' === $relative || str_contains( $relative, '\\' ) || in_array( '..', explode( '/', $relative ), true ) ) {
			return null;
		}
		return $relative;
	}

	/** Read the latest verified manifest and confirm every child remains verified/current.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $relative Requested uploads-relative path.
	 * @return string|null Persisted object key, when eligible.
	 */
	private function lookup( int $attachment_id, string $relative ): ?string {
		if ( array_key_exists( $attachment_id, $this->attachment_maps ) ) {
			return $this->attachment_maps[ $attachment_id ][ $relative ] ?? null;
		}
		global $wpdb;
		$job = $wpdb->get_row( $wpdb->prepare( 'SELECT id,manifest_json,manifest_hash FROM ' . QueueSchema::jobs_table() . " WHERE attachment_id = %d AND status = 'verified' ORDER BY id DESC LIMIT 1", $attachment_id ), ARRAY_A );
		if ( ! is_array( $job ) || ! is_string( $job['manifest_json'] ) ) {
			$this->attachment_maps[ $attachment_id ] = array();
			return null;
		}
		$manifest = json_decode( $job['manifest_json'], true );
		if ( ! is_array( $manifest ) || ! hash_equals( (string) $job['manifest_hash'], (string) ( $manifest['hash'] ?? '' ) ) || ! $this->current_fingerprint( $attachment_id, $manifest ) || ! $this->current_jio_generation( $attachment_id, $manifest ) ) {
			$this->attachment_maps[ $attachment_id ] = array();
			return null;
		}
		$files = $manifest['files'] ?? array();
		if ( ! is_array( $files ) || array() === $files || count( $files ) > 250 ) {
			$this->attachment_maps[ $attachment_id ] = array();
			return null;
		}
		$objects = $wpdb->get_results( $wpdb->prepare( 'SELECT object_index,object_key,relative_path,size_bytes,mime_type,sha256,file_identity,status FROM ' . QueueSchema::objects_table() . ' WHERE job_id = %d ORDER BY object_index ASC LIMIT 250', (int) $job['id'] ), ARRAY_A );
		if ( ! is_array( $objects ) || count( $objects ) !== count( $files ) ) {
			$this->attachment_maps[ $attachment_id ] = array();
			return null;
		}
		$root    = wp_upload_dir();
		$basedir = realpath( (string) ( $root['basedir'] ?? '' ) );
		if ( false === $basedir ) {
			$this->attachment_maps[ $attachment_id ] = array();
			return null;
		}
		$matched = null;
		$map     = array();
		foreach ( $files as $index => $file ) {
			$object = $objects[ $index ] ?? null;
			// phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- This compares two dynamic values, not a literal to a variable.
			if ( ! is_array( $file ) || ! is_array( $object ) || 'verified' !== $object['status'] || (int) $object['object_index'] !== $index || (string) $object['relative_path'] !== (string) ( $file['relative_path'] ?? '' ) || (string) $object['sha256'] !== (string) ( $file['sha256'] ?? '' ) || (int) $object['size_bytes'] !== (int) ( $file['size'] ?? -1 ) || (string) $object['mime_type'] !== (string) ( $file['mime'] ?? '' ) || ! hash_equals( hash( 'sha256', (string) $object['object_key'] ), hash( 'sha256', (string) ( $file['object_key'] ?? '' ) ) ) ) {
				return null;
			}
			$path = realpath( $basedir . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, (string) $file['relative_path'] ) );
			if ( false === $path || ! str_starts_with( wp_normalize_path( $path ), rtrim( wp_normalize_path( $basedir ), '/' ) . '/' ) ) {
				$this->attachment_maps[ $attachment_id ] = array();
				return null;
			}
			$stat     = stat( $path );
			$identity = is_array( $stat ) ? implode( ':', array( (int) $stat['dev'], (int) $stat['ino'], (int) $stat['size'], (int) $stat['mtime'], (int) $stat['ctime'] ) ) : '';
			if ( '' === $identity || ! hash_equals( (string) ( $file['file_identity'] ?? '' ), $identity ) ) {
				$this->attachment_maps[ $attachment_id ] = array();
				return null;
			}
			$map[ (string) $file['relative_path'] ] = (string) $object['object_key'];
			if ( $relative === (string) $file['relative_path'] ) {
				$matched = (string) $object['object_key'];
			}
		}
		$this->attachment_maps[ $attachment_id ] = $map;
		return $matched;
	}

	/** Verify the persisted attachment generation is still the current WordPress metadata generation.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $manifest Persisted manifest data.
	 * @return bool Whether the attachment metadata fingerprint is current.
	 */
	private function current_fingerprint( int $attachment_id, array $manifest ): bool {
		$current_metadata = wp_get_attachment_metadata( $attachment_id, true );
		$current_metadata = is_array( $current_metadata ) ? $current_metadata : array();
		$current          = hash( 'sha256', (string) wp_json_encode( array( get_post_meta( $attachment_id, '_wp_attached_file', true ), $current_metadata, get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true ) ) ) );
		return isset( $manifest['attachment_fingerprint'] ) && hash_equals( (string) $manifest['attachment_fingerprint'], $current );
	}

	/** Require the same terminal JIO generation when the versioned contract is active.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $manifest Persisted manifest.
	 * @return bool Whether the manifest is current for JIO-managed images.
	 */
	private function current_jio_generation( int $attachment_id, array $manifest ): bool {
		if ( ! in_array( (string) get_post_mime_type( $attachment_id ), array( 'image/jpeg', 'image/png' ), true ) ) {
			return true;
		}
		$jio = ( new JioDetector() )->detect();
		if ( ! $jio['active'] || version_compare( $jio['version'], '0.6.2', '<' ) ) {
			return true;
		}
		if ( ! array_key_exists( $attachment_id, $this->jio_state_memo ) ) {
			$this->jio_state_memo[ $attachment_id ] = ( new JioReadiness() )->state( $attachment_id );
		}
		$state = $this->jio_state_memo[ $attachment_id ];
		return is_array( $state ) && JioReadiness::manifest_matches_state( $manifest, $state );
	}

	/** Build a URL only from the validated configured hostname and persisted key.
	 *
	 * @param string $domain Validated CDN hostname.
	 * @param string $object_key Persisted R2 key.
	 * @param string $original Original URL for suffix preservation/fallback.
	 * @return string Public URL or the unchanged original URL.
	 */
	private function public_url( string $domain, string $object_key, string $original ): string {
		if ( '' === $object_key || str_starts_with( $object_key, '/' ) || str_contains( $object_key, '..' ) || str_contains( $object_key, '\\' ) ) {
			return $original;
		}
		$parts  = wp_parse_url( $original );
		$suffix = is_array( $parts ) ? ( ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' ) . ( isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '' ) ) : '';
		return 'https://' . $domain . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $object_key ) ) ) . $suffix;
	}
}
