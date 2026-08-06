<?php
/**
 * Atomic option-backed synchronization lock.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Services;

use InvalidArgumentException;
use wpdb;

/** Coordinates one schedule synchronization owner at a time. */
final class SyncLock {
	private const OPTION = 'cinebot_wp_sync_lock';

	/** @var wpdb */
	private $db;

	/** @var callable():int */
	private $clock;

	/** Create the lock using the site database connection. */
	public function __construct( ?wpdb $db = null, ?callable $clock = null ) {
		global $wpdb;
		$this->db    = $db ?? $wpdb;
		$this->clock = $clock ?? static function (): int {
			return time();
		};
	}

	/** Acquire ownership or return null when another valid owner holds it. */
	public function acquire( int $ttl = 300 ): ?string {
		if ( $ttl < 1 || $ttl > 3600 ) {
			throw new InvalidArgumentException( 'Synchronization lock TTL must be between 1 and 3600 seconds.' );
		}

		$token = bin2hex( random_bytes( 32 ) );
		$value = wp_json_encode( array( 'token' => $token, 'expires_at' => $this->now() + $ttl ) );
		if ( is_string( $value ) && add_option( self::OPTION, $value, '', false ) ) {
			return $token;
		}

		$stored = $this->stored_value();
		if ( null === $stored || ! $this->expired( $stored ) || ! $this->delete_exact( $stored ) ) {
			return null;
		}

		return is_string( $value ) && add_option( self::OPTION, $value, '', false ) ? $token : null;
	}

	/** Release ownership only when the exact stored lock belongs to this caller. */
	public function release( string $token ): bool {
		$stored = $this->stored_value();
		if ( null === $stored ) {
			return false;
		}
		$data = json_decode( $stored, true );
		if ( ! is_array( $data ) || ! isset( $data['token'] ) || ! is_string( $data['token'] ) || ! hash_equals( $data['token'], $token ) ) {
			return false;
		}

		return $this->delete_exact( $stored );
	}

	/** Read the unfiltered option value so compare-and-delete is race safe. */
	private function stored_value(): ?string {
		// The table and option name are fixed; the option name is still prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $this->db->get_var( $this->db->prepare( "SELECT option_value FROM {$this->db->options} WHERE option_name = %s", self::OPTION ) );
		return is_string( $value ) ? $value : null;
	}

	/** Return whether a correctly formed lock has expired. */
	private function expired( string $stored ): bool {
		$data = json_decode( $stored, true );
		return is_array( $data )
			&& isset( $data['token'], $data['expires_at'] )
			&& is_string( $data['token'] )
			&& is_int( $data['expires_at'] )
			&& $data['expires_at'] <= $this->now();
	}

	/** Return the injected UTC epoch for deterministic expiry checks. */
	private function now(): int {
		return (int) call_user_func( $this->clock );
	}

	/** Delete a lock only if no owner changed its exact serialized value. */
	private function delete_exact( string $stored ): bool {
		// The table is trusted and both option values are prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return 1 === $this->db->query(
			$this->db->prepare(
				"DELETE FROM {$this->db->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION,
				$stored
			)
		);
	}
}
