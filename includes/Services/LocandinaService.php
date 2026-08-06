<?php
/**
 * Cinebot poster URL builder.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Services;

use InvalidArgumentException;

/**
 * Builds deterministic HTTPS URLs from validated API poster fields.
 */
final class LocandinaService {
	private const SAFE_ERROR = 'Unable to build poster URL.';

	/**
	 * Builds a poster URL when the API flag enables one.
	 */
	public function build( string $host, string $path, int $titleId, int $flag ): ?string {
		if ( $flag <= 0 ) {
			return null;
		}
		if ( $titleId <= 0 ) {
			throw new InvalidArgumentException( self::SAFE_ERROR );
		}

		$host = strtolower( $host );
		if ( ! $this->isValidHost( $host ) ) {
			throw new InvalidArgumentException( self::SAFE_ERROR );
		}

		$path = trim( $path, '/' );
		if ( '' === $path ) {
			throw new InvalidArgumentException( self::SAFE_ERROR );
		}

		$segments = explode( '/', $path );
		foreach ( $segments as &$segment ) {
			if ( ! $this->isValidSegment( $segment ) ) {
				throw new InvalidArgumentException( self::SAFE_ERROR );
			}
			$segment = rawurlencode( $segment );
		}
		unset( $segment );

		return 'https://' . $host . '/' . implode( '/', $segments ) . '/titolo/' . $titleId . '/locandina';
	}

	/**
	 * Checks a DNS hostname without accepting ports, IPs, or localhost.
	 */
	private function isValidHost( string $host ): bool {
		if (
			'' === $host
			|| strlen( $host ) > 253
			|| false === strpos( $host, '.' )
			|| false !== filter_var( $host, FILTER_VALIDATE_IP )
		) {
			return false;
		}

		$labels = explode( '.', $host );
		foreach ( $labels as $label ) {
			if (
				'' === $label
				|| strlen( $label ) > 63
				|| 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label )
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Checks one relative path segment before URL encoding.
	 */
	private function isValidSegment( string $segment ): bool {
		return '' !== $segment
			&& '.' !== $segment
			&& '..' !== $segment
			&& false === strpos( $segment, '\\' )
			&& false === strpos( $segment, '?' )
			&& false === strpos( $segment, '#' )
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $segment )
			&& 1 !== preg_match( '/[a-z][a-z0-9+.-]*:/i', $segment )
			&& 1 !== preg_match( '/%(?:2f|3f|23|5c|0[0-9a-f]|1[0-9a-f]|7f)/i', $segment );
	}
}
