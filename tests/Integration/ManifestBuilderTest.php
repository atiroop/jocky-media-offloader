<?php
declare(strict_types=1);

namespace Jocky\MediaOffloader\Tests\Integration;

use Jocky\MediaOffloader\Media\ManifestBuilder;
use WP_UnitTestCase;

final class ManifestBuilderTest extends WP_UnitTestCase {
	private array $attachment_ids = array();
	private array $created_paths   = array();
	private array $created_dirs    = array();

	public function tear_down(): void {
		foreach ( $this->attachment_ids as $attachment_id ) {
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
		parent::tear_down();
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
