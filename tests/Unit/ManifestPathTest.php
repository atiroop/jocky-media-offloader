<?php
declare(strict_types=1);

namespace Jocky\MediaOffloader\Tests\Unit;

use Jocky\MediaOffloader\Media\ManifestBuilder;
use PHPUnit\Framework\TestCase;

final class ManifestPathTest extends TestCase {
	private string $root;
	private string $outside;

	protected function setUp(): void {
		$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'jmo-path-' . bin2hex( random_bytes( 6 ) );
		$this->root = $base . DIRECTORY_SEPARATOR . 'uploads';
		mkdir( $this->root, 0777, true );
		$this->outside = $base . DIRECTORY_SEPARATOR . 'outside.pdf';
		file_put_contents( $this->root . DIRECTORY_SEPARATOR . 'inside.pdf', 'inside' );
		file_put_contents( $this->outside, 'outside' );
	}

	protected function tearDown(): void {
		foreach ( array( $this->root . DIRECTORY_SEPARATOR . 'inside.pdf', $this->root . DIRECTORY_SEPARATOR . 'escape.pdf', $this->outside ) as $path ) {
			if ( is_file( $path ) || is_link( $path ) ) {
				unlink( $path );
			}
		}
		rmdir( $this->root );
		rmdir( dirname( $this->root ) );
	}

	public function test_confines_regular_file_to_upload_root(): void {
		$builder = new ManifestBuilder();
		self::assertSame( realpath( $this->root . DIRECTORY_SEPARATOR . 'inside.pdf' ), $builder->confined_file( $this->root, $this->root . DIRECTORY_SEPARATOR . 'inside.pdf' ) );
		self::assertNull( $builder->confined_file( $this->root, $this->root . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'outside.pdf' ) );
	}

	public function test_rejects_symlink_escape_when_platform_allows_symlink_creation(): void {
		$link = $this->root . DIRECTORY_SEPARATOR . 'escape.pdf';
		if ( ! @symlink( $this->outside, $link ) ) {
			self::markTestSkipped( 'The current Windows account cannot create symlinks.' );
		}
		self::assertNull( ( new ManifestBuilder() )->confined_file( $this->root, $link ) );
	}
}
