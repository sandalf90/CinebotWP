<?php
/**
 * Secure API settings storage.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Services;

use RuntimeException;
use Throwable;

/**
 * Validates API settings and protects credentials at rest.
 */
final class SettingsService {
	private const SETTINGS_OPTION           = 'cinebot_wp_settings';
	private const SALT_OPTION               = 'cinebot_wp_encryption_salt';
	private const DEFAULT_BASE_URL          = 'https://ws.cinebot.it';
	private const CIPHER                    = 'aes-256-cbc';
	private const HMAC_LENGTH               = 32;
	private const SALT_LENGTH               = 32;

	/** Maximum accepted plaintext credential size in bytes. */
	private const MAX_PASSWORD_BYTES        = 4096;

	/** Conservative cap checked before decoding stored Base64. */
	private const MAX_ENCODED_PAYLOAD_BYTES = 8192;
	private const SAFE_ERROR                = 'Unable to process API credentials securely.';

	/**
	 * Returns settings safe for public and administrative presentation.
	 *
	 * @return array{
	 *     api_username:string,
	 *     api_frontend:int|null,
	 *     sync_frequency:string,
	 *     sync_enabled:bool,
	 *     api_base_url:string,
	 *     detail_slug:string,
	 *     has_password:bool
	 * }
	 */
	public function get(): array {
		$settings = $this->storedSettings();

		return array(
			'api_username'   => $this->normalizeUsername( $settings['api_username'] ?? '' ),
			'api_frontend'   => $this->normalizeFrontend( $settings['api_frontend'] ?? null ),
			'sync_frequency' => $this->normalizeFrequency( $settings['sync_frequency'] ?? 'daily' ),
			'sync_enabled'   => $this->normalizeEnabled( $settings['sync_enabled'] ?? false ),
			'api_base_url'   => $this->normalizeBaseUrl( $settings['api_base_url'] ?? self::DEFAULT_BASE_URL ),
			'detail_slug'    => $this->normalizeDetailSlug( $settings['detail_slug'] ?? '' ),
			'has_password'   => isset( $settings['api_password'] )
				&& is_string( $settings['api_password'] )
				&& '' !== $settings['api_password'],
		);
	}

	/**
	 * Validates and persists submitted settings.
	 *
	 * @param array<string,mixed> $input Submitted settings.
	 * @return array{
	 *     api_username:string,
	 *     api_frontend:int|null,
	 *     sync_frequency:string,
	 *     sync_enabled:bool,
	 *     api_base_url:string,
	 *     detail_slug:string,
	 *     has_password:bool
	 * }
	 */
	public function save( array $input ): array {
		$existing = $this->storedSettings();
		$settings = array(
			'api_username'   => $this->normalizeUsername( $input['api_username'] ?? '' ),
			'api_frontend'   => $this->normalizeFrontend( $input['api_frontend'] ?? null ),
			'sync_frequency' => $this->normalizeFrequency( $input['sync_frequency'] ?? 'daily' ),
			'sync_enabled'   => $this->normalizeEnabled( $input['sync_enabled'] ?? false ),
			'api_base_url'   => $this->normalizeBaseUrl( $input['api_base_url'] ?? self::DEFAULT_BASE_URL ),
			'detail_slug'    => $this->normalizeDetailSlug( $input['detail_slug'] ?? '' ),
		);

		$password = $input['api_password'] ?? '';
		if ( is_string( $password ) && '' !== $password ) {
			$settings['api_password'] = $this->encrypt( $password );
		} elseif (
			isset( $existing['api_password'] )
			&& is_string( $existing['api_password'] )
			&& '' !== $existing['api_password']
		) {
			$settings['api_password'] = $existing['api_password'];
		}

		update_option( self::SETTINGS_OPTION, $settings );

		return $this->get();
	}

	/**
	 * Returns the API username.
	 */
	public function username(): string {
		return $this->get()['api_username'];
	}

	/**
	 * Decrypts and returns the API password.
	 */
	public function password(): string {
		$settings = $this->storedSettings();
		if (
			! isset( $settings['api_password'] )
			|| ! is_string( $settings['api_password'] )
			|| '' === $settings['api_password']
		) {
			return '';
		}

		return $this->decrypt( $settings['api_password'] );
	}

	/**
	 * Returns the optional API frontend identifier.
	 */
	public function frontend(): ?int {
		return $this->get()['api_frontend'];
	}

	/**
	 * Returns the synchronization frequency.
	 */
	public function frequency(): string {
		return $this->get()['sync_frequency'];
	}

	/**
	 * Returns whether scheduled synchronization is enabled.
	 */
	public function enabled(): bool {
		return $this->get()['sync_enabled'];
	}

	/**
	 * Returns the API base URL.
	 */
	public function baseUrl(): string {
		return $this->get()['api_base_url'];
	}

	/**
	 * Returns the detail page slug.
	 */
	public function detailSlug(): string {
		return $this->get()['detail_slug'];
	}

	/**
	 * Reads the settings option as an array.
	 *
	 * @return array<string,mixed>
	 */
	private function storedSettings(): array {
		$settings = get_option( self::SETTINGS_OPTION, array() );

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Sanitizes a submitted username.
	 *
	 * @param mixed $value Submitted value.
	 */
	private function normalizeUsername( $value ): string {
		return is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
	}

	/**
	 * Validates and normalizes the detail slug.
	 *
	 * @param mixed $value Submitted value.
	 */
	private function normalizeDetailSlug( $value ): string {
		return is_string( $value ) ? trim( sanitize_title( $value ) ) : '';
	}

	/**
	 * Strictly parses a positive integer without numeric coercion.
	 *
	 * @param mixed $value Submitted value.
	 */
	private function normalizeFrontend( $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( ! is_string( $value ) || '' === $value || 1 !== preg_match( '/^[0-9]+$/D', $value ) ) {
			return null;
		}

		$normalized = ltrim( $value, '0' );
		if ( '' === $normalized ) {
			return null;
		}

		$maximum = (string) PHP_INT_MAX;
		if (
			strlen( $normalized ) > strlen( $maximum )
			|| (
				strlen( $normalized ) === strlen( $maximum )
				&& strcmp( $normalized, $maximum ) > 0
			)
		) {
			return null;
		}

		return (int) $normalized;
	}

	/**
	 * Restricts synchronization frequency to registered values.
	 *
	 * @param mixed $value Submitted value.
	 */
	private function normalizeFrequency( $value ): string {
		$frequencies = array( 'hourly', 'twicedaily', 'daily', 'weekly' );

		return is_string( $value ) && in_array( $value, $frequencies, true ) ? $value : 'daily';
	}

	/**
	 * Converts only explicit checked values to true.
	 *
	 * @param mixed $value Submitted value.
	 */
	private function normalizeEnabled( $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'on' === $value;
	}

	/**
	 * Validates and normalizes an HTTPS API base URL.
	 *
	 * @param mixed $value Submitted value.
	 */
	private function normalizeBaseUrl( $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return self::DEFAULT_BASE_URL;
		}

		$url   = esc_url_raw( $value );
		$parts = wp_parse_url( $url );
		if (
			! is_array( $parts ) ||
			! isset( $parts['scheme'], $parts['host'] ) ||
			'https' !== strtolower( $parts['scheme'] ) ||
			'' === $parts['host'] ||
			isset( $parts['user'] ) ||
			isset( $parts['pass'] ) ||
			isset( $parts['query'] ) ||
			isset( $parts['fragment'] )
		) {
			return self::DEFAULT_BASE_URL;
		}

		$normalized = 'https://' . strtolower( $parts['host'] );
		if ( isset( $parts['port'] ) ) {
			$normalized .= ':' . $parts['port'];
		}
		if ( isset( $parts['path'] ) ) {
			$normalized .= rtrim( $parts['path'], '/' );
		}

		return rtrim( $normalized, '/' );
	}

	/**
	 * Encrypts and authenticates a plaintext credential.
	 */
	private function encrypt( string $password ): string {
		if ( strlen( $password ) > self::MAX_PASSWORD_BYTES ) {
			throw new RuntimeException( self::SAFE_ERROR );
		}

		$this->requireCrypto();

			$iv_length = openssl_cipher_iv_length( self::CIPHER );
			if ( false === $iv_length || $iv_length < 1 ) {
				throw new RuntimeException( self::SAFE_ERROR );
			}

			$iv         = random_bytes( $iv_length );
			$keys       = $this->deriveKeys();
			$ciphertext = openssl_encrypt(
				$password,
				self::CIPHER,
				$keys['encryption'],
				OPENSSL_RAW_DATA,
				$iv
			);
			if ( false === $ciphertext || '' === $ciphertext ) {
				throw new RuntimeException( self::SAFE_ERROR );
			}

			$authenticated = $iv . $ciphertext;
			$mac           = $this->hmac( $authenticated, $keys['authentication'] );

			return base64_encode( $authenticated . $mac );
	}

	/**
	 * Authenticates and decrypts a stored credential.
	 */
	private function decrypt( string $encoded ): string {
		if ( strlen( $encoded ) > self::MAX_ENCODED_PAYLOAD_BYTES ) {
			throw new RuntimeException( self::SAFE_ERROR );
		}

		$this->requireCrypto();

		try {
			$iv_length = openssl_cipher_iv_length( self::CIPHER );
			$payload   = base64_decode( $encoded, true );
			if ( false === $iv_length || $iv_length < 1 || false === $payload ) {
				throw new RuntimeException( self::SAFE_ERROR );
			}
			// AES-CBC may add one full 16-byte PKCS#7 padding block.
			$maximum_payload = $iv_length
				+ self::MAX_PASSWORD_BYTES
				+ 16
				+ self::HMAC_LENGTH;
			if ( strlen( $payload ) > $maximum_payload ) {
				throw new RuntimeException( self::SAFE_ERROR );
			}

			$ciphertext_length = strlen( $payload ) - $iv_length - self::HMAC_LENGTH;
			if ( $ciphertext_length < 16 || 0 !== $ciphertext_length % 16 ) {
				throw new RuntimeException( self::SAFE_ERROR );
			}

			$authenticated = substr( $payload, 0, $iv_length + $ciphertext_length );
			$stored_mac    = substr( $payload, -self::HMAC_LENGTH );
			$keys          = $this->deriveKeys();
			$expected_mac  = $this->hmac( $authenticated, $keys['authentication'] );
			if ( ! hash_equals( $expected_mac, $stored_mac ) ) {
				throw new RuntimeException( self::SAFE_ERROR );
			}

			$iv         = substr( $authenticated, 0, $iv_length );
			$ciphertext = substr( $authenticated, $iv_length );
			$password   = openssl_decrypt(
				$ciphertext,
				self::CIPHER,
				$keys['encryption'],
				OPENSSL_RAW_DATA,
				$iv
			);
			if ( false === $password ) {
				throw new RuntimeException( self::SAFE_ERROR );
			}

			return $password;
		} catch ( Throwable $exception ) {
			throw new RuntimeException( self::SAFE_ERROR );
		}
	}

	/**
	 * Derives independent encryption and authentication keys.
	 *
	 * @return array{encryption:string,authentication:string}
	 */
	private function deriveKeys(): array {
		if ( ! defined( 'AUTH_SALT' ) || ! is_string( AUTH_SALT ) || '' === AUTH_SALT ) {
			throw new RuntimeException( self::SAFE_ERROR );
		}

		$project_salt = $this->projectSalt();

		return array(
			'encryption'     => $this->hmac(
				"cinebot-wp\0encryption\0" . $project_salt,
				AUTH_SALT
			),
			'authentication' => $this->hmac(
				"cinebot-wp\0authentication\0" . $project_salt,
				AUTH_SALT
			),
		);
	}

	/**
	 * Computes one raw SHA-256 HMAC and fails closed on primitive failure.
	 */
	private function hmac( string $data, string $key ): string {
		$mac = hash_hmac( 'sha256', $data, $key, true );
		if ( ! is_string( $mac ) || self::HMAC_LENGTH !== strlen( $mac ) ) {
			throw new RuntimeException( self::SAFE_ERROR );
		}

		return $mac;
	}

	/**
	 * Returns the stable binary project salt, creating it without autoload.
	 */
	private function projectSalt(): string {
		$encoded = get_option( self::SALT_OPTION, null );
		if ( null === $encoded ) {
			$encoded = base64_encode( random_bytes( self::SALT_LENGTH ) );
			if ( ! add_option( self::SALT_OPTION, $encoded, '', 'no' ) ) {
				$encoded = get_option( self::SALT_OPTION, null );
			}
		}

		if ( ! is_string( $encoded ) ) {
			throw new RuntimeException( self::SAFE_ERROR );
		}

		$salt = base64_decode( $encoded, true );
		if ( false === $salt || self::SALT_LENGTH !== strlen( $salt ) ) {
			throw new RuntimeException( self::SAFE_ERROR );
		}

		return $salt;
	}

	/**
	 * Fails closed when required cryptographic primitives are unavailable.
	 */
	private function requireCrypto(): void {
		$functions = array(
			'openssl_encrypt',
			'openssl_decrypt',
			'openssl_cipher_iv_length',
			'random_bytes',
			'hash_hmac',
			'hash_equals',
		);
		foreach ( $functions as $function ) {
			if ( ! function_exists( $function ) ) {
				throw new RuntimeException( self::SAFE_ERROR );
			}
		}
	}
}
