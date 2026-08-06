<?php
/**
 * Synchronization lock integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

use CinebotWp\Services\SyncLock;
use WP_UnitTestCase;

/** Verifies atomic lock ownership and expiry recovery. */
final class SyncLockTest extends WP_UnitTestCase {
	/** Remove the lock before each test. */
	public function set_up(): void {
		parent::set_up();
		delete_option( 'cinebot_wp_sync_lock' );
	}

	/** The first owner wins and non-owners cannot release the lock. */
	public function test_acquire_is_exclusive_and_release_requires_owner_token(): void {
		$lock = new SyncLock();
		$token = $lock->acquire();

		self::assertIsString( $token );
		self::assertSame( 64, strlen( $token ) );
		self::assertNull( ( new SyncLock() )->acquire() );
		self::assertFalse( $lock->release( str_repeat( 'a', 64 ) ) );
		self::assertTrue( $lock->release( $token ) );
		self::assertFalse( $lock->release( $token ) );
	}

	/** An expired exact stored value may be reclaimed at the expiry boundary. */
	public function test_expired_lock_is_reclaimed_at_or_after_expiry(): void {
		$now = 1700000000;
		add_option(
			'cinebot_wp_sync_lock',
			wp_json_encode( array( 'token' => str_repeat( 'b', 64 ), 'expires_at' => $now ) ),
			'',
			false
		);

		$lock = new SyncLock(
			null,
			static function () use ( $now ): int {
				return $now;
			}
		);
		$token = $lock->acquire( 1 );
		self::assertIsString( $token );
		self::assertNotSame( str_repeat( 'b', 64 ), $token );
		self::assertTrue( $lock->release( $token ) );
	}

	/** Invalid TTL values fail before an option is created. */
	public function test_invalid_ttl_is_rejected(): void {
		$lock = new SyncLock();
		$this->expectException( \InvalidArgumentException::class );
		$lock->acquire( 0 );
	}
}
