<?php
/**
 * Builds confined, content-addressed manifests from persisted WordPress media.
 *
 * @package Jocky\MediaOffloader\Media
 */

declare(strict_types=1);

namespace Jocky\MediaOffloader\Media;

use Jocky\MediaOffloader\Integration\JioReadiness;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_read_finfo_open, WordPress.WP.AlternativeFunctions.file_system_read_finfo_file -- finfo is required to detect the MIME type of an existing file without altering WordPress's upload allowlist.

/** Resolves original, generated and backup media files without changing them. */
final class ManifestBuilder {
	public const VERSION   = 1;
	public const MAX_FILES = 250;

	/** Create the manifest builder.
	 *
	 * @param JioReadiness $jio_readiness Optional JIO contract reader.
	 */
	public function __construct( private readonly JioReadiness $jio_readiness = new JioReadiness() ) {}

	/** Build a stable manifest for one existing attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{version:int,attachment_id:int,attachment_fingerprint:string,jio_contract_version:int|null,jio_generation:string,jio_revision:string,jio_outcome:string,files:array<int,array{relative_path:string,size:int,mime:string,sha256:string,object_key:string}>,hash:string}|null Manifest, or null when no safe files exist.
	 */
	public function build( int $attachment_id ): ?array {
		if ( $attachment_id < 1 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return null;
		}
		$jio_state = $this->jio_readiness->state( $attachment_id );
		if ( ( $this->jio_readiness->has_compatible_contract( $attachment_id ) && null === $jio_state ) || ( null !== $jio_state && ! JioReadiness::is_terminal_eligible( $jio_state ) ) ) {
			return null;
		}
		$uploads  = wp_upload_dir();
		$root     = isset( $uploads['basedir'] ) && empty( $uploads['error'] ) ? realpath( $uploads['basedir'] ) : false;
		$attached = get_attached_file( $attachment_id, true );
		if ( false === $root || ! is_string( $attached ) ) {
			return null;
		}
		$canonical = $this->confined_file( $root, $attached );
		if ( null === $canonical ) {
			return null;
		}
		$metadata = wp_get_attachment_metadata( $attachment_id, true );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$paths    = array( $canonical );
		$dir      = dirname( $canonical );
		if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
			$paths[] = $dir . DIRECTORY_SEPARATOR . $metadata['original_image'];
		}
		foreach ( is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array() as $size ) {
			if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) ) {
				$paths[] = $dir . DIRECTORY_SEPARATOR . $size['file'];
			}
		}
		$backups = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		foreach ( is_array( $backups ) ? $backups : array() as $backup ) {
			if ( ! is_array( $backup ) ) {
				continue;
			}
			if ( isset( $backup['file'] ) && is_string( $backup['file'] ) ) {
				$paths[] = $dir . DIRECTORY_SEPARATOR . $backup['file'];
			}
			foreach ( is_array( $backup['sizes'] ?? null ) ? $backup['sizes'] : array() as $size ) {
				if ( is_array( $size ) && isset( $size['file'] ) && is_string( $size['file'] ) ) {
					$paths[] = $dir . DIRECTORY_SEPARATOR . $size['file'];
				}
			}
		}
		$jio_hashes = array();
		$jio_mime   = (string) get_post_mime_type( $attachment_id );
		$jio_keys   = in_array( $jio_mime, array( 'image/jpeg', 'image/png' ), true ) ? get_post_custom_keys( $attachment_id ) : array();
		foreach ( is_array( $jio_keys ) ? $jio_keys : array() as $meta_key ) {
			if ( ! is_string( $meta_key ) || ! str_starts_with( $meta_key, '_jio_derivative_' ) ) {
				continue;
			}
			$record = get_post_meta( $attachment_id, $meta_key, true );
			if ( ! is_array( $record ) || ! isset( $record['derivative_relative_path'], $record['derivative_sha256'], $record['output_format'] ) || ! in_array( $record['output_format'], array( 'image/webp', 'image/avif' ), true ) || 1 !== preg_match( '/\A[a-f0-9]{64}\z/', (string) $record['derivative_sha256'] ) ) {
				continue;
			}
			$relative = (string) $record['derivative_relative_path'];
			if ( str_contains( $relative, '\\' ) || str_starts_with( $relative, '/' ) ) {
				continue;
			}
			$jio_path                                     = rtrim( $root, '/\\' ) . DIRECTORY_SEPARATOR . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
			$paths[]                                      = $jio_path;
			$jio_hashes[ wp_normalize_path( $jio_path ) ] = (string) $record['derivative_sha256'];
		}
		$files        = array();
		$unique_paths = array_values( array_unique( $paths ) );
		$overflow     = false;
		foreach ( $unique_paths as $path ) {
			$file = $this->confined_file( $root, $path );
			if ( null === $file ) {
				continue;
			}
			$relative          = ltrim( substr( wp_normalize_path( $file ), strlen( rtrim( wp_normalize_path( $root ), '/' ) ) ), '/' );
			$size              = filesize( $file );
			$sha256            = hash_file( 'sha256', $file );
			$expected_jio_hash = $jio_hashes[ wp_normalize_path( $path ) ] ?? null;
			if ( false === $size || false === $sha256 || ( null !== $expected_jio_hash && ! hash_equals( $expected_jio_hash, $sha256 ) ) ) {
				continue;
			}
			$mime = $this->mime_type( $file );
			$stat = stat( $file );
			if ( false === $stat ) {
				continue;
			}
			$identity = implode( ':', array( (int) $stat['dev'], (int) $stat['ino'], (int) $stat['size'], (int) $stat['mtime'], (int) $stat['ctime'] ) );
			if ( strlen( $relative ) > 512 || strlen( $identity ) > 160 ) {
				return null;
			}
			$files[] = array(
				'relative_path' => $relative,
				'size'          => (int) $size,
				'mime'          => $mime,
				'sha256'        => $sha256,
				'file_identity' => $identity,
				'object_key'    => '',
			);
			if ( count( $files ) >= self::MAX_FILES ) {
				$overflow = count( $unique_paths ) > count( $files );
				break;
			}
		}
		if ( array() === $files || $overflow ) {
			return null;
		}
		usort( $files, static fn ( array $left, array $right ): int => strcmp( $left['relative_path'], $right['relative_path'] ) );
		$site_key = get_option( 'jmo_site_key', '' );
		if ( ! is_string( $site_key ) || 1 !== preg_match( '/\A[a-f0-9]{32}\z/', $site_key ) ) {
			$site_key = strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
			update_option( 'jmo_site_key', $site_key, false );
		}
		$attachment_fingerprint = hash( 'sha256', (string) wp_json_encode( array( get_post_meta( $attachment_id, '_wp_attached_file', true ), $metadata, get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true ) ) ) );
		$canonical_data         = array_map( static fn ( array $file ): array => array( $file['relative_path'], $file['size'], $file['mime'], $file['sha256'], $file['file_identity'] ), $files );
		$jio_contract_version   = is_array( $jio_state ) ? (int) $jio_state['contract_version'] : null;
		$jio_generation         = is_array( $jio_state ) ? (string) $jio_state['generation'] : '';
		$jio_revision           = is_array( $jio_state ) ? (string) $jio_state['revision'] : '';
		$jio_outcome            = is_array( $jio_state ) ? (string) $jio_state['outcome'] : '';
		$hash                   = hash( 'sha256', (string) wp_json_encode( array( self::VERSION, $attachment_id, $attachment_fingerprint, $canonical_data, $jio_contract_version, $jio_generation, $jio_revision, $jio_outcome ) ) );
		foreach ( $files as $index => &$file ) {
			$file['object_key'] = 'jmo/v1/' . $site_key . '/' . $attachment_id . '/' . $hash . '/' . sprintf( '%03d', $index ) . '-' . $file['sha256'] . '-' . rawurlencode( basename( $file['relative_path'] ) );
			if ( strlen( $file['object_key'] ) > 1024 ) {
				return null;
			}
		}
		unset( $file );
		if ( ! $this->jio_readiness->snapshot_still_current( $attachment_id, $jio_state ) ) {
			return null;
		}
		return array(
			'version'                => self::VERSION,
			'attachment_id'          => $attachment_id,
			'attachment_fingerprint' => $attachment_fingerprint,
			'jio_contract_version'   => $jio_contract_version,
			'jio_generation'         => $jio_generation,
			'jio_revision'           => $jio_revision,
			'jio_outcome'            => $jio_outcome,
			'files'                  => $files,
			'hash'                   => $hash,
		);
	}

	/** Resolve a regular file beneath uploads, rejecting traversal and symlink escapes.
	 *
	 * @param string $root Upload root.
	 * @param string $path Candidate path.
	 * @return string|null Canonical file path.
	 */
	public function confined_file( string $root, string $path ): ?string {
		$root_real = realpath( $root );
		$file_real = realpath( $path );
		if ( false === $root_real || false === $file_real || ! is_file( $file_real ) || is_link( $path ) ) {
			return null;
		}
		$root_norm = rtrim( wp_normalize_path( $root_real ), '/' );
		$file_norm = wp_normalize_path( $file_real );
		$matches   = 'Windows' === PHP_OS_FAMILY ? str_starts_with( strtolower( $file_norm ), strtolower( $root_norm . '/' ) ) : str_starts_with( $file_norm, $root_norm . '/' );
		return $matches ? $file_real : null;
	}

	/** Detect an existing file's MIME type without changing the upload allowlist.
	 *
	 * @param string $path Confined local file path.
	 */
	private function mime_type( string $path ): string {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( false !== $finfo ) {
				$mime = finfo_file( $finfo, $path );
				unset( $finfo );
				if ( is_string( $mime ) && '' !== $mime ) {
					return sanitize_mime_type( $mime );
				}
			}
		}
		$detected = wp_check_filetype( $path );
		return isset( $detected['type'] ) && is_string( $detected['type'] ) ? sanitize_mime_type( $detected['type'] ) : 'application/octet-stream';
	}
}
