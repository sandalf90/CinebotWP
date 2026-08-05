<?php
/**
 * Secure API settings unit tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Unit;

use CinebotWp\Services\SettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Verifies settings validation and encrypted credential storage.
 */
final class SettingsServiceTest extends TestCase {
	private const SETTINGS_OPTION = 'cinebot_wp_settings';
	private const SALT_OPTION     = 'cinebot_wp_encryption_salt';

	/**
	 * Removes persisted settings before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		delete_option( self::SETTINGS_OPTION );
		delete_option( self::SALT_OPTION );
	}

	/**
	 * Removes persisted settings after each test.
	 */
	protected function tearDown(): void {
		delete_option( self::SETTINGS_OPTION );
		delete_option( self::SALT_OPTION );
		parent::tearDown();
	}

	/**
	 * Verifies the exact public defaults and accessor types.
	 */
	public function test_defaults_and_accessor_types(): void {
		$service = new SettingsService();

		self::assertSame(
			array(
				'api_username'   => '',
				'api_frontend'   => null,
				'sync_frequency' => 'daily',
				'sync_enabled'   => false,
				'api_base_url'   => 'https://ws.cinebot.it',
				'has_password'   => false,
			),
			$service->get()
		);
		self::assertSame( '', $service->username() );
		self::assertSame( '', $service->password() );
		self::assertNull( $service->frontend() );
		self::assertSame( 'daily', $service->frequency() );
		self::assertFalse( $service->enabled() );
		self::assertSame( 'https://ws.cinebot.it', $service->baseUrl() );
	}

	/**
	 * Provides accepted and rejected synchronization frequencies.
	 *
	 * @return array<string,array{input:mixed,expected:string}>
	 */
	public function frequency_values(): array {
		return array(
			'hourly'       => array( 'input' => 'hourly', 'expected' => 'hourly' ),
			'twice daily'  => array( 'input' => 'twicedaily', 'expected' => 'twicedaily' ),
			'daily'        => array( 'input' => 'daily', 'expected' => 'daily' ),
			'weekly'       => array( 'input' => 'weekly', 'expected' => 'weekly' ),
			'unknown'      => array( 'input' => 'monthly', 'expected' => 'daily' ),
			'wrong casing' => array( 'input' => 'DAILY', 'expected' => 'daily' ),
			'non-string'   => array( 'input' => 1, 'expected' => 'daily' ),
		);
	}

	/**
	 * Verifies the frequency allowlist and fallback.
	 *
	 * @param mixed  $input    Submitted value.
	 * @param string $expected Normalized frequency.
	 *
	 * @dataProvider frequency_values
	 */
	public function test_frequency_allowlist( $input, string $expected ): void {
		$service = new SettingsService();

		self::assertSame( $expected, $service->save( array( 'sync_frequency' => $input ) )['sync_frequency'] );
	}

	/**
	 * Provides strict frontend identifiers.
	 *
	 * @return array<string,array{input:mixed,expected:int|null}>
	 */
	public function frontend_values(): array {
		return array(
			'native integer'     => array( 'input' => 42, 'expected' => 42 ),
			'digit string'       => array( 'input' => '0042', 'expected' => 42 ),
			'maximum integer'    => array( 'input' => (string) PHP_INT_MAX, 'expected' => PHP_INT_MAX ),
			'empty string'       => array( 'input' => '', 'expected' => null ),
			'null'               => array( 'input' => null, 'expected' => null ),
			'zero integer'       => array( 'input' => 0, 'expected' => null ),
			'zero string'        => array( 'input' => '0', 'expected' => null ),
			'negative integer'   => array( 'input' => -1, 'expected' => null ),
			'negative string'    => array( 'input' => '-1', 'expected' => null ),
			'float'              => array( 'input' => 1.0, 'expected' => null ),
			'decimal string'     => array( 'input' => '1.0', 'expected' => null ),
			'boolean true'       => array( 'input' => true, 'expected' => null ),
			'boolean false'      => array( 'input' => false, 'expected' => null ),
			'surrounding spaces' => array( 'input' => ' 42 ', 'expected' => null ),
			'overflow'           => array( 'input' => (string) PHP_INT_MAX . '0', 'expected' => null ),
			'exponent'           => array( 'input' => '1e2', 'expected' => null ),
			'array'              => array( 'input' => array( 42 ), 'expected' => null ),
		);
	}

	/**
	 * Verifies frontend values are never loosely coerced.
	 *
	 * @param mixed    $input    Submitted value.
	 * @param int|null $expected Normalized identifier.
	 *
	 * @dataProvider frontend_values
	 */
	public function test_frontend_strict_normalization( $input, ?int $expected ): void {
		$service = new SettingsService();

		self::assertSame( $expected, $service->save( array( 'api_frontend' => $input ) )['api_frontend'] );
	}

	/**
	 * Provides explicit enabled values.
	 *
	 * @return array<string,array{input:mixed,expected:bool}>
	 */
	public function enabled_values(): array {
		return array(
			'true'          => array( 'input' => true, 'expected' => true ),
			'one integer'   => array( 'input' => 1, 'expected' => true ),
			'one string'    => array( 'input' => '1', 'expected' => true ),
			'on'            => array( 'input' => 'on', 'expected' => true ),
			'false'         => array( 'input' => false, 'expected' => false ),
			'zero integer'  => array( 'input' => 0, 'expected' => false ),
			'zero string'   => array( 'input' => '0', 'expected' => false ),
			'true string'   => array( 'input' => 'true', 'expected' => false ),
			'upper case on' => array( 'input' => 'ON', 'expected' => false ),
			'array'         => array( 'input' => array( 'on' ), 'expected' => false ),
		);
	}

	/**
	 * Verifies enabled values use an explicit allowlist.
	 *
	 * @param mixed $input    Submitted value.
	 * @param bool  $expected Normalized state.
	 *
	 * @dataProvider enabled_values
	 */
	public function test_enabled_explicit_normalization( $input, bool $expected ): void {
		$service = new SettingsService();

		self::assertSame( $expected, $service->save( array( 'sync_enabled' => $input ) )['sync_enabled'] );
	}

	/**
	 * Provides normalized and rejected API URLs.
	 *
	 * @return array<string,array{input:mixed,expected:string}>
	 */
	public function base_url_values(): array {
		$default = 'https://ws.cinebot.it';

		return array(
			'host case and slash' => array( 'input' => 'https://EXAMPLE.test/', 'expected' => 'https://example.test' ),
			'test server path'    => array( 'input' => 'https://Example.test/api/v1///', 'expected' => 'https://example.test/api/v1' ),
			'port'                => array( 'input' => 'https://Example.test:8443/api/', 'expected' => 'https://example.test:8443/api' ),
			'http'                => array( 'input' => 'http://example.test', 'expected' => $default ),
			'relative'            => array( 'input' => '/api', 'expected' => $default ),
			'missing host'        => array( 'input' => 'https:///api', 'expected' => $default ),
			'userinfo'            => array( 'input' => 'https://user:pass@example.test', 'expected' => $default ),
			'query'               => array( 'input' => 'https://example.test/api?secret=1', 'expected' => $default ),
			'fragment'            => array( 'input' => 'https://example.test/api#part', 'expected' => $default ),
			'javascript'          => array( 'input' => 'javascript:alert(1)', 'expected' => $default ),
			'empty'               => array( 'input' => '', 'expected' => $default ),
			'non-string'          => array( 'input' => array( 'https://example.test' ), 'expected' => $default ),
		);
	}

	/**
	 * Verifies strict HTTPS base URL validation and normalization.
	 *
	 * @param mixed  $input    Submitted value.
	 * @param string $expected Normalized URL.
	 *
	 * @dataProvider base_url_values
	 */
	public function test_base_url_validation( $input, string $expected ): void {
		$service = new SettingsService();

		self::assertSame( $expected, $service->save( array( 'api_base_url' => $input ) )['api_base_url'] );
	}

	/**
	 * Verifies WordPress username sanitation plus trimming.
	 */
	public function test_username_is_sanitized_and_trimmed(): void {
		$service = new SettingsService();

		$view = $service->save( array( 'api_username' => "  admin<script>alert('x')</script>\n  " ) );

		self::assertSame( 'admin', $view['api_username'] );
	}

	/**
	 * Verifies first-save encryption, public secrecy, and decryption.
	 */
	public function test_password_is_encrypted_at_rest_and_round_trips_privately(): void {
		$service = new SettingsService();
		$view    = $service->save( array( 'api_password' => 'correct horse battery staple' ) );
		$stored  = get_option( self::SETTINGS_OPTION );

		self::assertIsArray( $stored );
		self::assertArrayHasKey( 'api_password', $stored );
		self::assertStringNotContainsString( 'correct horse battery staple', $stored['api_password'] );
		self::assertSame( 'correct horse battery staple', $service->password() );
		self::assertSame( true, $view['has_password'] );
		self::assertSame(
			array( 'api_username', 'api_frontend', 'sync_frequency', 'sync_enabled', 'api_base_url', 'has_password' ),
			array_keys( $view )
		);
		self::assertArrayNotHasKey( 'api_password', $view );
	}

	/**
	 * Verifies an empty initial password remains absent.
	 */
	public function test_empty_first_password_remains_absent(): void {
		$service = new SettingsService();
		$view    = $service->save( array( 'api_password' => '' ) );
		$stored  = get_option( self::SETTINGS_OPTION );

		self::assertFalse( $view['has_password'] );
		self::assertSame( '', $service->password() );
		self::assertIsArray( $stored );
		self::assertArrayNotHasKey( 'api_password', $stored );
	}

	/**
	 * Verifies empty submissions preserve and non-empty submissions replace credentials.
	 */
	public function test_empty_password_preserves_existing_and_non_empty_replaces_it(): void {
		$service = new SettingsService();
		$service->save( array( 'api_password' => 'first secret' ) );
		$encrypted = get_option( self::SETTINGS_OPTION )['api_password'];

		$service->save( array( 'api_username' => 'updated', 'api_password' => '' ) );
		self::assertSame( $encrypted, get_option( self::SETTINGS_OPTION )['api_password'] );
		self::assertSame( 'first secret', $service->password() );

		$service->save( array( 'api_password' => 'second secret' ) );
		self::assertNotSame( $encrypted, get_option( self::SETTINGS_OPTION )['api_password'] );
		self::assertSame( 'second secret', $service->password() );
	}

	/**
	 * Verifies the project salt is stable and IVs randomize ciphertext.
	 */
	public function test_salt_is_stable_and_same_password_produces_distinct_ciphertext(): void {
		$service = new SettingsService();
		$service->save( array( 'api_password' => 'repeated secret' ) );
		$salt       = get_option( self::SALT_OPTION );
		$ciphertext = get_option( self::SETTINGS_OPTION )['api_password'];

		$service->save( array( 'api_password' => 'repeated secret' ) );

		self::assertSame( $salt, get_option( self::SALT_OPTION ) );
		self::assertNotSame( $ciphertext, get_option( self::SETTINGS_OPTION )['api_password'] );
		self::assertSame( 32, strlen( base64_decode( $salt, true ) ) );
	}

	/**
	 * Provides malformed encrypted credential representations.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function malformed_passwords(): array {
		return array(
			'invalid base64'   => array( '%%%not-base64%%%' ),
			'truncated'        => array( base64_encode( str_repeat( 'x', 47 ) ) ),
			'block misaligned' => array( base64_encode( str_repeat( 'x', 49 ) ) ),
		);
	}

	/**
	 * Verifies malformed payloads fail with one safe error.
	 *
	 * @param string $payload Stored payload.
	 *
	 * @dataProvider malformed_passwords
	 */
	public function test_malformed_password_payload_throws_safe_exception( string $payload ): void {
		update_option( self::SETTINGS_OPTION, array( 'api_password' => $payload ) );
		$service = new SettingsService();

		$this->assert_safe_credential_failure( $service, $payload );
	}

	/**
	 * Verifies HMAC tampering is rejected before plaintext is returned.
	 */
	public function test_tampered_payload_throws_safe_exception(): void {
		$service = new SettingsService();
		$service->save( array( 'api_password' => 'do not disclose me' ) );
		$stored                 = get_option( self::SETTINGS_OPTION );
		$payload                = base64_decode( $stored['api_password'], true );
		$payload[20]            = chr( ord( $payload[20] ) ^ 1 );
		$stored['api_password'] = base64_encode( $payload );
		update_option( self::SETTINGS_OPTION, $stored );

		$this->assert_safe_credential_failure( $service, $stored['api_password'] );
	}

	/**
	 * Verifies malformed project salt cannot silently change keys.
	 */
	public function test_malformed_salt_throws_safe_exception(): void {
		$service = new SettingsService();
		$service->save( array( 'api_password' => 'do not disclose me' ) );
		$ciphertext = get_option( self::SETTINGS_OPTION )['api_password'];
		update_option( self::SALT_OPTION, 'not strict base64!' );

		$this->assert_safe_credential_failure( $service, $ciphertext );
	}

	/**
	 * Asserts credential failures reveal neither secrets nor stored payloads.
	 *
	 * @param SettingsService $service    Service under test.
	 * @param string          $ciphertext Stored credential representation.
	 */
	private function assert_safe_credential_failure( SettingsService $service, string $ciphertext ): void {
		try {
			$service->password();
			self::fail( 'Expected secure credential failure.' );
		} catch ( RuntimeException $exception ) {
			self::assertSame( 'Unable to process API credentials securely.', $exception->getMessage() );
			self::assertStringNotContainsString( 'do not disclose me', $exception->getMessage() );
			self::assertStringNotContainsString( $ciphertext, $exception->getMessage() );
		}
	}
}
