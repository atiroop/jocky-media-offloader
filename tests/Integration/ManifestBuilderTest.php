<?php
declare(strict_types=1);

namespace Jocky\MediaOffloader\Tests\Integration;

use Jocky\MediaOffloader\Media\ManifestBuilder;
use Jocky\MediaOffloader\Media\VerifiedObjectResolver;
use Jocky\MediaOffloader\Queue\JobRepository;
use Jocky\MediaOffloader\Queue\QueueSchema;
use Jocky\MediaOffloader\Storage\CdnReadiness;
use Jocky\MediaOffloader\Storage\ConnectionResult;
use WP_UnitTestCase;

final class ManifestBuilderTest extends WP_UnitTestCase {
	private array $attachment_ids = array();
	private array $created_paths   = array();
	private array $created_dirs    = array();
	private array $saved_options   = array();

	protected function set_up(): void {
		parent::set_up();
		foreach ( array( 'jmo_settings', 'jmo_cdn_delivery', 'jmo_cdn_readiness' ) as $option ) {
			$this->saved_options[ $option ] = get_option( $option, null );
		}
	}

	public function tear_down(): void {
		global $wpdb;
		foreach ( $this->attachment_ids as $attachment_id ) {
			$jobs = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . QueueSchema::jobs_table() . ' WHERE attachment_id = %d', $attachment_id ) );
			foreach ( $jobs as $job_id ) {
				$wpdb->delete( QueueSchema::objects_table(), array( 'job_id' => (int) $job_id ), array( '%d' ) );
			}
			$wpdb->delete( QueueSchema::jobs_table(), array( 'attachment_id' => $attachment_id ), array( '%d' ) );
			update_post_meta( $attachment_id, '_wp_attachment_metadata', array() );
			delete_post_meta( $attachment_id, '_wp_attachment_backup_sizes' );
			wp_delete_attachment( $attachment_id, true );
		}
		foreach ( $this->created_paths as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
		foreach ( array_reverse( $this->created_dirs ) as $directory ) {
			if ( is_dir( $directory ) ) {
				rmdir( $directory );
			}
		}
		foreach ( $this->saved_options as $option => $value ) {
			if ( null === $value ) {
				delete_option( $option );
			} else {
				update_option( $option, $value );
			}
		}
		parent::tear_down();
	}

	public function test_only_current_fully_verified_exact_object_is_mapped_and_local_file_is_retained(): void {
		global $wpdb;
		$attachment_id = $this->attachment( 'delivery.pdf', 'application/pdf', '%PDF-current' );
		$path = get_attached_file( $attachment_id, true );
		$manifest = ( new ManifestBuilder() )->build( $attachment_id );
		self::assertNotNull( $manifest );
		$job_id = ( new JobRepository() )->enqueue( $attachment_id, $manifest );
		self::assertGreaterThan( 0, $job_id );
		$wpdb->update( QueueSchema::objects_table(), array( 'status' => 'verified' ), array( 'job_id' => $job_id ), array( '%s' ), array( '%d' ) );
		$wpdb->update( QueueSchema::jobs_table(), array( 'status' => 'verified', 'verified_count' => count( $manifest['files'] ) ), array( 'id' => $job_id ), array( '%s', '%d' ), array( '%d' ) );
		update_option( 'jmo_cdn_delivery', true );
		update_option( 'jmo_settings', array( 'account_id' => str_repeat( 'a', 32 ), 'bucket' => 'jocky-cdn-storage', 'cdn_domain' => 'cdn.example.com' ) );
		$readiness = new CdnReadiness(
			null,
			null,
			static fn (): ConnectionResult => new ConnectionResult( 'connected', 'Connected.', true ),
			null,
			static fn ( string $url, array $args ): array => array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-length' => (string) $manifest['files'][0]['size'], 'content-type' => $manifest['files'][0]['mime'] ) )
		);
		self::assertSame( 'ready', $readiness->test()['status'] );
		$local = wp_get_attachment_url( $attachment_id );
		$resolved = ( new VerifiedObjectResolver() )->resolve( $local, $attachment_id );
		$encoded_key = implode( '/', array_map( 'rawurlencode', explode( '/', $manifest['files'][0]['object_key'] ) ) );
		self::assertSame( 'https://cdn.example.com/' . $encoded_key, $resolved );
		self::assertFileExists( $path );
		update_post_meta( $attachment_id, '_wp_attachment_metadata', array( 'file' => get_post_meta( $attachment_id, '_wp_attached_file', true ), 'revision' => 2 ) );
		self::assertSame( $local, ( new VerifiedObjectResolver() )->resolve( $local, $attachment_id ), 'changed metadata invalidates old object eligibility' );
		self::assertFileExists( $path );
	}

	public function test_pdf_manifest_includes_original_sizes_and_backups_but_rejects_escape(): void {
		$attachment_id = $this->attachment( 'report.pdf', 'application/pdf', '%PDF-original' );
		$original      = get_attached_file( $attachment_id, true );
		$directory     = dirname( $original );
		$initial       = ( new ManifestBuilder() )->build( $attachment_id );
		$this->write( $directory . DIRECTORY_SEPARATOR . 'report-thumb.pdf', '%PDF-thumb' );
		$this->write( $directory . DIRECTORY_SEPARATOR . 'report-backup.pdf', '%PDF-backup' );
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'file'  => get_post_meta( $attachment_id, '_wp_attached_file', true ),
				'sizes' => array( 'thumbnail' => array( 'file' => 'report-thumb.pdf' ), 'unsafe' => array( 'file' => '../../../../outside.pdf' ) ),
			)
		);
		update_post_meta( $attachment_id, '_wp_attachment_backup_sizes', array( 'full-orig' => array( 'file' => 'report-backup.pdf' ) ) );
		$manifest = ( new ManifestBuilder() )->build( $attachment_id );
		$paths    = array_column( $manifest['files'], 'relative_path' );
		self::assertNotSame( $initial['hash'], $manifest['hash'] );
		self::assertCount( 3, $paths );
		self::assertContains( ltrim( substr( wp_normalize_path( $original ), strlen( rtrim( wp_normalize_path( wp_upload_dir()['basedir'] ), '/' ) ) ), '/' ), $paths );
		self::assertContains( 'report-thumb.pdf', array_map( 'basename', $paths ) );
		self::assertContains( 'report-backup.pdf', array_map( 'basename', $paths ) );
		self::assertNotContains( '../../../../outside.pdf', $paths );
	}

	public function test_svg_and_hash_matching_jio_output_are_manifested_without_mime_allowlist_changes(): void {
		$attachment_id = $this->attachment( 'vector.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>' );
		$manifest      = ( new ManifestBuilder() )->build( $attachment_id );
		self::assertSame( 1, count( $manifest['files'] ) );
		self::assertSame( 'image/svg+xml', get_post_mime_type( $attachment_id ) );

		$image_id = $this->attachment( 'photo.jpg', 'image/jpeg', 'test jpeg bytes' );
		$uploads  = wp_upload_dir();
		$relative = 'jmo-test-' . wp_generate_password( 8, false ) . '/photo.jio.webp';
		$jio_path = trailingslashit( $uploads['basedir'] ) . str_replace( '/', DIRECTORY_SEPARATOR, $relative );
		$this->write( $jio_path, 'webp derivative bytes' );
		update_post_meta(
			$image_id,
			'_jio_derivative_' . str_repeat( 'a', 32 ),
			array( 'derivative_relative_path' => $relative, 'derivative_sha256' => hash_file( 'sha256', $jio_path ), 'output_format' => 'image/webp' )
		);
		$jio_manifest = ( new ManifestBuilder() )->build( $image_id );
		self::assertContains( $relative, array_column( $jio_manifest['files'], 'relative_path' ) );
	}

	private function attachment( string $filename, string $mime, string $contents ): int {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( $uploads['basedir'] ) . 'jmo-test-' . wp_generate_password( 8, false );
		wp_mkdir_p( $dir );
		$this->created_dirs[] = $dir;
		$path                  = $dir . DIRECTORY_SEPARATOR . $filename;
		$this->write( $path, $contents );
		$attachment_id = wp_insert_attachment( array( 'post_mime_type' => $mime, 'post_status' => 'inherit', 'post_title' => $filename ), $path );
		$this->attachment_ids[] = $attachment_id;
		return (int) $attachment_id;
	}

	private function write( string $path, string $contents ): void {
		wp_mkdir_p( dirname( $path ) );
		file_put_contents( $path, $contents );
		$this->created_paths[] = $path;
		$directory = dirname( $path );
		if ( str_contains( $directory, 'jmo-test-' ) && ! in_array( $directory, $this->created_dirs, true ) ) {
			$this->created_dirs[] = $directory;
		}
	}
}
