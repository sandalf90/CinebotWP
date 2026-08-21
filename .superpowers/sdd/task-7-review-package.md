# Task 7 Reviewer Handoff Package

## Scope

Complete Task 7 range from parent `35f5ae162311ea98ffa2a23ea9e43626f7ff9f29` through implementation commit `00942da3c7a7df150a10444bb63f59a3e0bbc7ca` and fix commit `4f03fdcc0356049187ab7aebca9c8b9fd7c6dc7f`. Task 7 adds secure Cinebot API settings and isolated unit coverage; the fix bounds credential processing and hardens cryptographic failure/race contracts.

The updated report records blocked Docker/PHP dynamic gates. Review fixes cover 4096-byte plaintext and encoded/decoded payload bounds, one exact-length raw-HMAC helper, generic failures, WPCS line lengths, boundary/oversize tests, MAC tampering, HMAC-before-decrypt observability, salt autoload/race behavior, distinct KDF outputs, and controlled primitive failures.

## Commit Metadata

```text
commit 00942da3c7a7df150a10444bb63f59a3e0bbc7ca
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 01:40:53 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 01:40:53 2026 +0200

    feat: secure cinebot api settings

commit 4f03fdcc0356049187ab7aebca9c8b9fd7c6dc7f
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 01:54:42 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 01:54:42 2026 +0200

    fix: bound credential processing
```

## Full Stat

Command: `git show --stat --format=fuller 00942da 4f03fdc`

```text
commit 00942da3c7a7df150a10444bb63f59a3e0bbc7ca
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 01:40:53 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 01:40:53 2026 +0200

    feat: secure cinebot api settings

 .superpowers/sdd/task-7-report.md     |  42 ++++
 includes/Services/SettingsService.php | 341 ++++++++++++++++++++++++++++++++
 tests/Unit/SettingsServiceTest.php    | 356 ++++++++++++++++++++++++++++++++++
 3 files changed, 739 insertions(+)

commit 4f03fdcc0356049187ab7aebca9c8b9fd7c6dc7f
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 01:54:42 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 01:54:42 2026 +0200

    fix: bound credential processing

 .superpowers/sdd/task-7-report.md     |  36 ++++
 includes/Services/SettingsService.php | 129 ++++++++++--
 tests/Unit/SettingsServiceTest.php    | 363 +++++++++++++++++++++++++++++++++-
 3 files changed, 505 insertions(+), 23 deletions(-)
```

The committed `.superpowers/sdd/task-7-report.md` is coordination evidence represented in the stat and summarized above; it is excluded from the implementation diff.

## Full Relevant Diff

This cumulative diff shows the final Task 7 implementation and tests after both commits.

Command: `git diff --unified=10 35f5ae1 4f03fdc -- includes/Services/SettingsService.php tests/Unit/SettingsServiceTest.php`

```diff
diff --git a/includes/Services/SettingsService.php b/includes/Services/SettingsService.php
new file mode 100644
index 0000000..d635a31
--- /dev/null
+++ b/includes/Services/SettingsService.php
@@ -0,0 +1,430 @@
+<?php
+/**
+ * Secure API settings storage.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Services;
+
+use RuntimeException;
+use Throwable;
+
+/**
+ * Validates API settings and protects credentials at rest.
+ */
+final class SettingsService {
+	private const SETTINGS_OPTION           = 'cinebot_wp_settings';
+	private const SALT_OPTION               = 'cinebot_wp_encryption_salt';
+	private const DEFAULT_BASE_URL          = 'https://ws.cinebot.it';
+	private const CIPHER                    = 'aes-256-cbc';
+	private const HMAC_LENGTH               = 32;
+	private const SALT_LENGTH               = 32;
+
+	/** Maximum accepted plaintext credential size in bytes. */
+	private const MAX_PASSWORD_BYTES        = 4096;
+
+	/** Conservative cap checked before decoding stored Base64. */
+	private const MAX_ENCODED_PAYLOAD_BYTES = 8192;
+	private const SAFE_ERROR                = 'Unable to process API credentials securely.';
+
+	/**
+	 * Returns settings safe for public and administrative presentation.
+	 *
+	 * @return array{
+	 *     api_username:string,
+	 *     api_frontend:int|null,
+	 *     sync_frequency:string,
+	 *     sync_enabled:bool,
+	 *     api_base_url:string,
+	 *     has_password:bool
+	 * }
+	 */
+	public function get(): array {
+		$settings = $this->storedSettings();
+
+		return array(
+			'api_username'   => $this->normalizeUsername( $settings['api_username'] ?? '' ),
+			'api_frontend'   => $this->normalizeFrontend( $settings['api_frontend'] ?? null ),
+			'sync_frequency' => $this->normalizeFrequency( $settings['sync_frequency'] ?? 'daily' ),
+			'sync_enabled'   => $this->normalizeEnabled( $settings['sync_enabled'] ?? false ),
+			'api_base_url'   => $this->normalizeBaseUrl( $settings['api_base_url'] ?? self::DEFAULT_BASE_URL ),
+			'has_password'   => isset( $settings['api_password'] )
+				&& is_string( $settings['api_password'] )
+				&& '' !== $settings['api_password'],
+		);
+	}
+
+	/**
+	 * Validates and persists submitted settings.
+	 *
+	 * @param array<string,mixed> $input Submitted settings.
+	 * @return array{
+	 *     api_username:string,
+	 *     api_frontend:int|null,
+	 *     sync_frequency:string,
+	 *     sync_enabled:bool,
+	 *     api_base_url:string,
+	 *     has_password:bool
+	 * }
+	 */
+	public function save( array $input ): array {
+		$existing = $this->storedSettings();
+		$settings = array(
+			'api_username'   => $this->normalizeUsername( $input['api_username'] ?? '' ),
+			'api_frontend'   => $this->normalizeFrontend( $input['api_frontend'] ?? null ),
+			'sync_frequency' => $this->normalizeFrequency( $input['sync_frequency'] ?? 'daily' ),
+			'sync_enabled'   => $this->normalizeEnabled( $input['sync_enabled'] ?? false ),
+			'api_base_url'   => $this->normalizeBaseUrl( $input['api_base_url'] ?? self::DEFAULT_BASE_URL ),
+		);
+
+		$password = $input['api_password'] ?? '';
+		if ( is_string( $password ) && '' !== $password ) {
+			$settings['api_password'] = $this->encrypt( $password );
+		} elseif (
+			isset( $existing['api_password'] )
+			&& is_string( $existing['api_password'] )
+			&& '' !== $existing['api_password']
+		) {
+			$settings['api_password'] = $existing['api_password'];
+		}
+
+		update_option( self::SETTINGS_OPTION, $settings );
+
+		return $this->get();
+	}
+
+	/**
+	 * Returns the API username.
+	 */
+	public function username(): string {
+		return $this->get()['api_username'];
+	}
+
+	/**
+	 * Decrypts and returns the API password.
+	 */
+	public function password(): string {
+		$settings = $this->storedSettings();
+		if (
+			! isset( $settings['api_password'] )
+			|| ! is_string( $settings['api_password'] )
+			|| '' === $settings['api_password']
+		) {
+			return '';
+		}
+
+		return $this->decrypt( $settings['api_password'] );
+	}
+
+	/**
+	 * Returns the optional API frontend identifier.
+	 */
+	public function frontend(): ?int {
+		return $this->get()['api_frontend'];
+	}
+
+	/**
+	 * Returns the synchronization frequency.
+	 */
+	public function frequency(): string {
+		return $this->get()['sync_frequency'];
+	}
+
+	/**
+	 * Returns whether scheduled synchronization is enabled.
+	 */
+	public function enabled(): bool {
+		return $this->get()['sync_enabled'];
+	}
+
+	/**
+	 * Returns the API base URL.
+	 */
+	public function baseUrl(): string {
+		return $this->get()['api_base_url'];
+	}
+
+	/**
+	 * Reads the settings option as an array.
+	 *
+	 * @return array<string,mixed>
+	 */
+	private function storedSettings(): array {
+		$settings = get_option( self::SETTINGS_OPTION, array() );
+
+		return is_array( $settings ) ? $settings : array();
+	}
+
+	/**
+	 * Sanitizes a submitted username.
+	 *
+	 * @param mixed $value Submitted value.
+	 */
+	private function normalizeUsername( $value ): string {
+		return is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
+	}
+
+	/**
+	 * Strictly parses a positive integer without numeric coercion.
+	 *
+	 * @param mixed $value Submitted value.
+	 */
+	private function normalizeFrontend( $value ): ?int {
+		if ( is_int( $value ) ) {
+			return $value > 0 ? $value : null;
+		}
+
+		if ( ! is_string( $value ) || '' === $value || 1 !== preg_match( '/^[0-9]+$/D', $value ) ) {
+			return null;
+		}
+
+		$normalized = ltrim( $value, '0' );
+		if ( '' === $normalized ) {
+			return null;
+		}
+
+		$maximum = (string) PHP_INT_MAX;
+		if (
+			strlen( $normalized ) > strlen( $maximum )
+			|| (
+				strlen( $normalized ) === strlen( $maximum )
+				&& strcmp( $normalized, $maximum ) > 0
+			)
+		) {
+			return null;
+		}
+
+		return (int) $normalized;
+	}
+
+	/**
+	 * Restricts synchronization frequency to registered values.
+	 *
+	 * @param mixed $value Submitted value.
+	 */
+	private function normalizeFrequency( $value ): string {
+		$frequencies = array( 'hourly', 'twicedaily', 'daily', 'weekly' );
+
+		return is_string( $value ) && in_array( $value, $frequencies, true ) ? $value : 'daily';
+	}
+
+	/**
+	 * Converts only explicit checked values to true.
+	 *
+	 * @param mixed $value Submitted value.
+	 */
+	private function normalizeEnabled( $value ): bool {
+		return true === $value || 1 === $value || '1' === $value || 'on' === $value;
+	}
+
+	/**
+	 * Validates and normalizes an HTTPS API base URL.
+	 *
+	 * @param mixed $value Submitted value.
+	 */
+	private function normalizeBaseUrl( $value ): string {
+		if ( ! is_string( $value ) || '' === $value ) {
+			return self::DEFAULT_BASE_URL;
+		}
+
+		$url   = esc_url_raw( $value );
+		$parts = wp_parse_url( $url );
+		if (
+			! is_array( $parts ) ||
+			! isset( $parts['scheme'], $parts['host'] ) ||
+			'https' !== strtolower( $parts['scheme'] ) ||
+			'' === $parts['host'] ||
+			isset( $parts['user'] ) ||
+			isset( $parts['pass'] ) ||
+			isset( $parts['query'] ) ||
+			isset( $parts['fragment'] )
+		) {
+			return self::DEFAULT_BASE_URL;
+		}
+
+		$normalized = 'https://' . strtolower( $parts['host'] );
+		if ( isset( $parts['port'] ) ) {
+			$normalized .= ':' . $parts['port'];
+		}
+		if ( isset( $parts['path'] ) ) {
+			$normalized .= rtrim( $parts['path'], '/' );
+		}
+
+		return rtrim( $normalized, '/' );
+	}
+
+	/**
+	 * Encrypts and authenticates a plaintext credential.
+	 */
+	private function encrypt( string $password ): string {
+		if ( strlen( $password ) > self::MAX_PASSWORD_BYTES ) {
+			throw new RuntimeException( self::SAFE_ERROR );
+		}
+
+		$this->requireCrypto();
+
+		try {
+			$iv_length = openssl_cipher_iv_length( self::CIPHER );
+			if ( false === $iv_length || $iv_length < 1 ) {
+				throw new RuntimeException( self::SAFE_ERROR );
+			}
+
+			$iv         = random_bytes( $iv_length );
+			$keys       = $this->deriveKeys();
+			$ciphertext = openssl_encrypt(
+				$password,
+				self::CIPHER,
+				$keys['encryption'],
+				OPENSSL_RAW_DATA,
+				$iv
+			);
+			if ( false === $ciphertext || '' === $ciphertext ) {
+				throw new RuntimeException( self::SAFE_ERROR );
+			}
+
+			$authenticated = $iv . $ciphertext;
+			$mac           = $this->hmac( $authenticated, $keys['authentication'] );
+
+			return base64_encode( $authenticated . $mac );
+		} catch ( Throwable $exception ) {
+			throw new RuntimeException( self::SAFE_ERROR );
+		}
+	}
+
+	/**
+	 * Authenticates and decrypts a stored credential.
+	 */
+	private function decrypt( string $encoded ): string {
+		if ( strlen( $encoded ) > self::MAX_ENCODED_PAYLOAD_BYTES ) {
+			throw new RuntimeException( self::SAFE_ERROR );
+		}
+
+		$this->requireCrypto();
+
+		try {
+			$iv_length = openssl_cipher_iv_length( self::CIPHER );
+			$payload   = base64_decode( $encoded, true );
+			if ( false === $iv_length || $iv_length < 1 || false === $payload ) {
+				throw new RuntimeException( self::SAFE_ERROR );
+			}
+			// AES-CBC may add one full 16-byte PKCS#7 padding block.
+			$maximum_payload = $iv_length
+				+ self::MAX_PASSWORD_BYTES
+				+ 16
+				+ self::HMAC_LENGTH;
+			if ( strlen( $payload ) > $maximum_payload ) {
+				throw new RuntimeException( self::SAFE_ERROR );
+			}
+
+			$ciphertext_length = strlen( $payload ) - $iv_length - self::HMAC_LENGTH;
+			if ( $ciphertext_length < 16 || 0 !== $ciphertext_length % 16 ) {
+				throw new RuntimeException( self::SAFE_ERROR );
+			}
+
+			$authenticated = substr( $payload, 0, $iv_length + $ciphertext_length );
+			$stored_mac    = substr( $payload, -self::HMAC_LENGTH );
+			$keys          = $this->deriveKeys();
+			$expected_mac  = $this->hmac( $authenticated, $keys['authentication'] );
+			if ( ! hash_equals( $expected_mac, $stored_mac ) ) {
+				throw new RuntimeException( self::SAFE_ERROR );
+			}
+
+			$iv         = substr( $authenticated, 0, $iv_length );
+			$ciphertext = substr( $authenticated, $iv_length );
+			$password   = openssl_decrypt(
+				$ciphertext,
+				self::CIPHER,
+				$keys['encryption'],
+				OPENSSL_RAW_DATA,
+				$iv
+			);
+			if ( false === $password ) {
+				throw new RuntimeException( self::SAFE_ERROR );
+			}
+
+			return $password;
+		} catch ( Throwable $exception ) {
+			throw new RuntimeException( self::SAFE_ERROR );
+		}
+	}
+
+	/**
+	 * Derives independent encryption and authentication keys.
+	 *
+	 * @return array{encryption:string,authentication:string}
+	 */
+	private function deriveKeys(): array {
+		if ( ! defined( 'AUTH_SALT' ) || ! is_string( AUTH_SALT ) || '' === AUTH_SALT ) {
+			throw new RuntimeException( self::SAFE_ERROR );
+		}
+
+		$project_salt = $this->projectSalt();
+
+		return array(
+			'encryption'     => $this->hmac(
+				"cinebot-wp\0encryption\0" . $project_salt,
+				AUTH_SALT
+			),
+			'authentication' => $this->hmac(
+				"cinebot-wp\0authentication\0" . $project_salt,
+				AUTH_SALT
+			),
+		);
+	}
+
+	/**
+	 * Computes one raw SHA-256 HMAC and fails closed on primitive failure.
+	 */
+	private function hmac( string $data, string $key ): string {
+		$mac = hash_hmac( 'sha256', $data, $key, true );
+		if ( ! is_string( $mac ) || self::HMAC_LENGTH !== strlen( $mac ) ) {
+			throw new RuntimeException( self::SAFE_ERROR );
+		}
+
+		return $mac;
+	}
+
+	/**
+	 * Returns the stable binary project salt, creating it without autoload.
+	 */
+	private function projectSalt(): string {
+		$encoded = get_option( self::SALT_OPTION, null );
+		if ( null === $encoded ) {
+			$encoded = base64_encode( random_bytes( self::SALT_LENGTH ) );
+			if ( ! add_option( self::SALT_OPTION, $encoded, '', 'no' ) ) {
+				$encoded = get_option( self::SALT_OPTION, null );
+			}
+		}
+
+		if ( ! is_string( $encoded ) ) {
+			throw new RuntimeException( self::SAFE_ERROR );
+		}
+
+		$salt = base64_decode( $encoded, true );
+		if ( false === $salt || self::SALT_LENGTH !== strlen( $salt ) ) {
+			throw new RuntimeException( self::SAFE_ERROR );
+		}
+
+		return $salt;
+	}
+
+	/**
+	 * Fails closed when required cryptographic primitives are unavailable.
+	 */
+	private function requireCrypto(): void {
+		$functions = array(
+			'openssl_encrypt',
+			'openssl_decrypt',
+			'openssl_cipher_iv_length',
+			'random_bytes',
+			'hash_hmac',
+			'hash_equals',
+		);
+		foreach ( $functions as $function ) {
+			if ( ! function_exists( $function ) ) {
+				throw new RuntimeException( self::SAFE_ERROR );
+			}
+		}
+	}
+}
diff --git a/tests/Unit/SettingsServiceTest.php b/tests/Unit/SettingsServiceTest.php
new file mode 100644
index 0000000..5bf49d1
--- /dev/null
+++ b/tests/Unit/SettingsServiceTest.php
@@ -0,0 +1,713 @@
+<?php
+/**
+ * Secure API settings unit tests.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Services {
+
+	use CinebotWp\Tests\Unit\SettingsServiceFunctionControl;
+
+	// Native names are required so PHP namespace resolution supplies test doubles.
+	// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
+	/**
+	 * Delegate normally while allowing deterministic primitive failures.
+	 *
+	 * @param string $algorithm Hash algorithm.
+	 * @param string $data      Data to authenticate.
+	 * @param string $key       Authentication key.
+	 * @param bool   $binary    Whether to return raw bytes.
+	 * @return string|false
+	 */
+	function hash_hmac( $algorithm, $data, $key, $binary = false ) {
+		++SettingsServiceFunctionControl::$hash_calls;
+		if ( SettingsServiceFunctionControl::$hash_failure_at === SettingsServiceFunctionControl::$hash_calls ) {
+			return false;
+		}
+
+		$result = \hash_hmac( $algorithm, $data, $key, $binary );
+		if ( 0 === strpos( $data, "cinebot-wp\0" ) ) {
+			SettingsServiceFunctionControl::$derived_keys[] = $result;
+		}
+
+		return $result;
+	}
+
+	/**
+	 * Delegate encryption while allowing a primitive failure result.
+	 *
+	 * @param string $data       Plaintext.
+	 * @param string $cipher     Cipher name.
+	 * @param string $passphrase Encryption key.
+	 * @param int    $options    OpenSSL options.
+	 * @param string $iv         Initialization vector.
+	 * @return string|false
+	 */
+	function openssl_encrypt( $data, $cipher, $passphrase, $options = 0, $iv = '' ) {
+		if ( SettingsServiceFunctionControl::$encrypt_failure ) {
+			return false;
+		}
+
+		return \openssl_encrypt( $data, $cipher, $passphrase, $options, $iv );
+	}
+
+	/**
+	 * Record whether authenticated decryption was reached.
+	 *
+	 * @param string $data       Ciphertext.
+	 * @param string $cipher     Cipher name.
+	 * @param string $passphrase Encryption key.
+	 * @param int    $options    OpenSSL options.
+	 * @param string $iv         Initialization vector.
+	 * @return string|false
+	 */
+	function openssl_decrypt( $data, $cipher, $passphrase, $options = 0, $iv = '' ) {
+		++SettingsServiceFunctionControl::$decrypt_calls;
+
+		return \openssl_decrypt( $data, $cipher, $passphrase, $options, $iv );
+	}
+
+	/**
+	 * Record whether encoded input reached Base64 decoding.
+	 *
+	 * @param string $data   Encoded data.
+	 * @param bool   $strict Whether invalid characters must fail.
+	 * @return string|false
+	 */
+	function base64_decode( $data, $strict = false ) {
+		++SettingsServiceFunctionControl::$base64_decode_calls;
+
+		return \base64_decode( $data, $strict );
+	}
+
+	/**
+	 * Simulate a salt creation race while delegating normal option writes.
+	 *
+	 * @param string $option     Option name.
+	 * @param mixed  $value      Option value.
+	 * @param string $deprecated Unused legacy argument.
+	 * @param mixed  $autoload   Autoload setting.
+	 * @return bool
+	 */
+	function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
+		if ( null !== SettingsServiceFunctionControl::$race_salt && 'cinebot_wp_encryption_salt' === $option ) {
+			\add_option( $option, SettingsServiceFunctionControl::$race_salt, $deprecated, $autoload );
+
+			return false;
+		}
+
+		return \add_option( $option, $value, $deprecated, $autoload );
+	}
+
+	/**
+	 * Simulate an unavailable required primitive.
+	 *
+	 * @param string $function Function name.
+	 * @return bool
+	 */
+	function function_exists( $function ) {
+		if ( SettingsServiceFunctionControl::$unavailable_function === $function ) {
+			return false;
+		}
+
+		return \function_exists( $function );
+	}
+	// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
+}
+
+namespace CinebotWp\Tests\Unit {
+
+use CinebotWp\Services\SettingsService;
+use PHPUnit\Framework\TestCase;
+use RuntimeException;
+
+/**
+ * Controls namespaced function doubles used by SettingsService.
+ */
+final class SettingsServiceFunctionControl {
+	/** @var string */
+	public static $unavailable_function = '';
+
+	/** @var int */
+	public static $hash_failure_at = 0;
+
+	/** @var int */
+	public static $hash_calls = 0;
+
+	/** @var int */
+	public static $decrypt_calls = 0;
+
+	/** @var bool */
+	public static $encrypt_failure = false;
+
+	/** @var int */
+	public static $base64_decode_calls = 0;
+
+	/** @var string|null */
+	public static $race_salt;
+
+	/** @var array<int,string> */
+	public static $derived_keys = array();
+
+	/** Restore normal delegation before and after each test. */
+	public static function reset(): void {
+		self::$unavailable_function = '';
+		self::$hash_failure_at       = 0;
+		self::$hash_calls            = 0;
+		self::$decrypt_calls         = 0;
+		self::$encrypt_failure       = false;
+		self::$base64_decode_calls   = 0;
+		self::$race_salt             = null;
+		self::$derived_keys          = array();
+	}
+}
+
+/**
+ * Verifies settings validation and encrypted credential storage.
+ */
+final class SettingsServiceTest extends TestCase {
+	private const SETTINGS_OPTION = 'cinebot_wp_settings';
+	private const SALT_OPTION     = 'cinebot_wp_encryption_salt';
+
+	/**
+	 * Removes persisted settings before each test.
+	 */
+	protected function setUp(): void {
+		parent::setUp();
+		SettingsServiceFunctionControl::reset();
+		delete_option( self::SETTINGS_OPTION );
+		delete_option( self::SALT_OPTION );
+	}
+
+	/**
+	 * Removes persisted settings after each test.
+	 */
+	protected function tearDown(): void {
+		delete_option( self::SETTINGS_OPTION );
+		delete_option( self::SALT_OPTION );
+		SettingsServiceFunctionControl::reset();
+		parent::tearDown();
+	}
+
+	/**
+	 * Verifies the exact public defaults and accessor types.
+	 */
+	public function test_defaults_and_accessor_types(): void {
+		$service = new SettingsService();
+
+		self::assertSame(
+			array(
+				'api_username'   => '',
+				'api_frontend'   => null,
+				'sync_frequency' => 'daily',
+				'sync_enabled'   => false,
+				'api_base_url'   => 'https://ws.cinebot.it',
+				'has_password'   => false,
+			),
+			$service->get()
+		);
+		self::assertSame( '', $service->username() );
+		self::assertSame( '', $service->password() );
+		self::assertNull( $service->frontend() );
+		self::assertSame( 'daily', $service->frequency() );
+		self::assertFalse( $service->enabled() );
+		self::assertSame( 'https://ws.cinebot.it', $service->baseUrl() );
+	}
+
+	/**
+	 * Provides accepted and rejected synchronization frequencies.
+	 *
+	 * @return array<string,array{input:mixed,expected:string}>
+	 */
+	public function frequency_values(): array {
+		return array(
+			'hourly'       => array( 'input' => 'hourly', 'expected' => 'hourly' ),
+			'twice daily'  => array( 'input' => 'twicedaily', 'expected' => 'twicedaily' ),
+			'daily'        => array( 'input' => 'daily', 'expected' => 'daily' ),
+			'weekly'       => array( 'input' => 'weekly', 'expected' => 'weekly' ),
+			'unknown'      => array( 'input' => 'monthly', 'expected' => 'daily' ),
+			'wrong casing' => array( 'input' => 'DAILY', 'expected' => 'daily' ),
+			'non-string'   => array( 'input' => 1, 'expected' => 'daily' ),
+		);
+	}
+
+	/**
+	 * Verifies the frequency allowlist and fallback.
+	 *
+	 * @param mixed  $input    Submitted value.
+	 * @param string $expected Normalized frequency.
+	 *
+	 * @dataProvider frequency_values
+	 */
+	public function test_frequency_allowlist( $input, string $expected ): void {
+		$service = new SettingsService();
+
+		self::assertSame( $expected, $service->save( array( 'sync_frequency' => $input ) )['sync_frequency'] );
+	}
+
+	/**
+	 * Provides strict frontend identifiers.
+	 *
+	 * @return array<string,array{input:mixed,expected:int|null}>
+	 */
+	public function frontend_values(): array {
+		return array(
+			'native integer'     => array( 'input' => 42, 'expected' => 42 ),
+			'digit string'       => array( 'input' => '0042', 'expected' => 42 ),
+			'maximum integer'    => array( 'input' => (string) PHP_INT_MAX, 'expected' => PHP_INT_MAX ),
+			'empty string'       => array( 'input' => '', 'expected' => null ),
+			'null'               => array( 'input' => null, 'expected' => null ),
+			'zero integer'       => array( 'input' => 0, 'expected' => null ),
+			'zero string'        => array( 'input' => '0', 'expected' => null ),
+			'negative integer'   => array( 'input' => -1, 'expected' => null ),
+			'negative string'    => array( 'input' => '-1', 'expected' => null ),
+			'float'              => array( 'input' => 1.0, 'expected' => null ),
+			'decimal string'     => array( 'input' => '1.0', 'expected' => null ),
+			'boolean true'       => array( 'input' => true, 'expected' => null ),
+			'boolean false'      => array( 'input' => false, 'expected' => null ),
+			'surrounding spaces' => array( 'input' => ' 42 ', 'expected' => null ),
+			'overflow'           => array( 'input' => (string) PHP_INT_MAX . '0', 'expected' => null ),
+			'exponent'           => array( 'input' => '1e2', 'expected' => null ),
+			'array'              => array( 'input' => array( 42 ), 'expected' => null ),
+		);
+	}
+
+	/**
+	 * Verifies frontend values are never loosely coerced.
+	 *
+	 * @param mixed    $input    Submitted value.
+	 * @param int|null $expected Normalized identifier.
+	 *
+	 * @dataProvider frontend_values
+	 */
+	public function test_frontend_strict_normalization( $input, ?int $expected ): void {
+		$service = new SettingsService();
+
+		self::assertSame( $expected, $service->save( array( 'api_frontend' => $input ) )['api_frontend'] );
+	}
+
+	/**
+	 * Provides explicit enabled values.
+	 *
+	 * @return array<string,array{input:mixed,expected:bool}>
+	 */
+	public function enabled_values(): array {
+		return array(
+			'true'          => array( 'input' => true, 'expected' => true ),
+			'one integer'   => array( 'input' => 1, 'expected' => true ),
+			'one string'    => array( 'input' => '1', 'expected' => true ),
+			'on'            => array( 'input' => 'on', 'expected' => true ),
+			'false'         => array( 'input' => false, 'expected' => false ),
+			'zero integer'  => array( 'input' => 0, 'expected' => false ),
+			'zero string'   => array( 'input' => '0', 'expected' => false ),
+			'true string'   => array( 'input' => 'true', 'expected' => false ),
+			'upper case on' => array( 'input' => 'ON', 'expected' => false ),
+			'array'         => array( 'input' => array( 'on' ), 'expected' => false ),
+		);
+	}
+
+	/**
+	 * Verifies enabled values use an explicit allowlist.
+	 *
+	 * @param mixed $input    Submitted value.
+	 * @param bool  $expected Normalized state.
+	 *
+	 * @dataProvider enabled_values
+	 */
+	public function test_enabled_explicit_normalization( $input, bool $expected ): void {
+		$service = new SettingsService();
+
+		self::assertSame( $expected, $service->save( array( 'sync_enabled' => $input ) )['sync_enabled'] );
+	}
+
+	/**
+	 * Provides normalized and rejected API URLs.
+	 *
+	 * @return array<string,array{input:mixed,expected:string}>
+	 */
+	public function base_url_values(): array {
+		$default = 'https://ws.cinebot.it';
+
+		return array(
+			'host case and slash' => array( 'input' => 'https://EXAMPLE.test/', 'expected' => 'https://example.test' ),
+			'test server path'    => array(
+				'input'    => 'https://Example.test/api/v1///',
+				'expected' => 'https://example.test/api/v1',
+			),
+			'port'                => array(
+				'input'    => 'https://Example.test:8443/api/',
+				'expected' => 'https://example.test:8443/api',
+			),
+			'http'                => array( 'input' => 'http://example.test', 'expected' => $default ),
+			'relative'            => array( 'input' => '/api', 'expected' => $default ),
+			'missing host'        => array( 'input' => 'https:///api', 'expected' => $default ),
+			'userinfo'            => array( 'input' => 'https://user:pass@example.test', 'expected' => $default ),
+			'query'               => array( 'input' => 'https://example.test/api?secret=1', 'expected' => $default ),
+			'fragment'            => array( 'input' => 'https://example.test/api#part', 'expected' => $default ),
+			'javascript'          => array( 'input' => 'javascript:alert(1)', 'expected' => $default ),
+			'empty'               => array( 'input' => '', 'expected' => $default ),
+			'non-string'          => array( 'input' => array( 'https://example.test' ), 'expected' => $default ),
+		);
+	}
+
+	/**
+	 * Verifies strict HTTPS base URL validation and normalization.
+	 *
+	 * @param mixed  $input    Submitted value.
+	 * @param string $expected Normalized URL.
+	 *
+	 * @dataProvider base_url_values
+	 */
+	public function test_base_url_validation( $input, string $expected ): void {
+		$service = new SettingsService();
+
+		self::assertSame( $expected, $service->save( array( 'api_base_url' => $input ) )['api_base_url'] );
+	}
+
+	/**
+	 * Verifies WordPress username sanitation plus trimming.
+	 */
+	public function test_username_is_sanitized_and_trimmed(): void {
+		$service = new SettingsService();
+
+		$view = $service->save( array( 'api_username' => "  admin<script>alert('x')</script>\n  " ) );
+
+		self::assertSame( 'admin', $view['api_username'] );
+	}
+
+	/**
+	 * Verifies first-save encryption, public secrecy, and decryption.
+	 */
+	public function test_password_is_encrypted_at_rest_and_round_trips_privately(): void {
+		$service = new SettingsService();
+		$view    = $service->save( array( 'api_password' => 'correct horse battery staple' ) );
+		$stored  = get_option( self::SETTINGS_OPTION );
+
+		self::assertIsArray( $stored );
+		self::assertArrayHasKey( 'api_password', $stored );
+		self::assertStringNotContainsString( 'correct horse battery staple', $stored['api_password'] );
+		self::assertSame( 'correct horse battery staple', $service->password() );
+		self::assertSame( true, $view['has_password'] );
+		self::assertSame(
+			array( 'api_username', 'api_frontend', 'sync_frequency', 'sync_enabled', 'api_base_url', 'has_password' ),
+			array_keys( $view )
+		);
+		self::assertArrayNotHasKey( 'api_password', $view );
+	}
+
+	/**
+	 * Verifies an empty initial password remains absent.
+	 */
+	public function test_empty_first_password_remains_absent(): void {
+		$service = new SettingsService();
+		$view    = $service->save( array( 'api_password' => '' ) );
+		$stored  = get_option( self::SETTINGS_OPTION );
+
+		self::assertFalse( $view['has_password'] );
+		self::assertSame( '', $service->password() );
+		self::assertIsArray( $stored );
+		self::assertArrayNotHasKey( 'api_password', $stored );
+	}
+
+	/**
+	 * Verifies empty submissions preserve and non-empty submissions replace credentials.
+	 */
+	public function test_empty_password_preserves_existing_and_non_empty_replaces_it(): void {
+		$service = new SettingsService();
+		$service->save( array( 'api_password' => 'first secret' ) );
+		$encrypted = get_option( self::SETTINGS_OPTION )['api_password'];
+
+		$service->save( array( 'api_username' => 'updated', 'api_password' => '' ) );
+		self::assertSame( $encrypted, get_option( self::SETTINGS_OPTION )['api_password'] );
+		self::assertSame( 'first secret', $service->password() );
+
+		$service->save( array( 'api_password' => 'second secret' ) );
+		self::assertNotSame( $encrypted, get_option( self::SETTINGS_OPTION )['api_password'] );
+		self::assertSame( 'second secret', $service->password() );
+	}
+
+	/**
+	 * Verifies the project salt is stable and IVs randomize ciphertext.
+	 */
+	public function test_salt_is_stable_and_same_password_produces_distinct_ciphertext(): void {
+		$service = new SettingsService();
+		$service->save( array( 'api_password' => 'repeated secret' ) );
+		$salt       = get_option( self::SALT_OPTION );
+		$ciphertext = get_option( self::SETTINGS_OPTION )['api_password'];
+
+		$service->save( array( 'api_password' => 'repeated secret' ) );
+
+		self::assertSame( $salt, get_option( self::SALT_OPTION ) );
+		self::assertNotSame( $ciphertext, get_option( self::SETTINGS_OPTION )['api_password'] );
+		self::assertSame( 32, strlen( base64_decode( $salt, true ) ) );
+	}
+
+	/**
+	 * Verifies the maximum password byte length is accepted.
+	 */
+	public function test_password_at_byte_limit_round_trips(): void {
+		$password = str_repeat( 'p', 4096 );
+		$service  = new SettingsService();
+
+		$service->save( array( 'api_password' => $password ) );
+
+		self::assertSame( $password, $service->password() );
+	}
+
+	/**
+	 * Verifies oversized plaintext is rejected before option storage.
+	 */
+	public function test_password_over_byte_limit_is_rejected_without_storage(): void {
+		$service = new SettingsService();
+
+		$this->expectException( RuntimeException::class );
+		$this->expectExceptionMessage( 'Unable to process API credentials securely.' );
+		try {
+			$service->save( array( 'api_password' => str_repeat( 'p', 4097 ) ) );
+		} finally {
+			self::assertFalse( get_option( self::SETTINGS_OPTION, false ) );
+		}
+	}
+
+	/**
+	 * Verifies the encoded boundary is decoded before structural rejection.
+	 */
+	public function test_encoded_payload_at_limit_reaches_strict_decoder(): void {
+		$payload = str_repeat( 'A', 8192 );
+		update_option( self::SETTINGS_OPTION, array( 'api_password' => $payload ) );
+
+		$this->assert_safe_credential_failure( new SettingsService(), $payload );
+
+		self::assertSame( 1, SettingsServiceFunctionControl::$base64_decode_calls );
+	}
+
+	/**
+	 * Verifies oversized encoded input is rejected before Base64 decoding.
+	 */
+	public function test_oversized_encoded_payload_is_rejected_before_decoding(): void {
+		$payload = str_repeat( 'A', 8193 );
+		update_option( self::SETTINGS_OPTION, array( 'api_password' => $payload ) );
+
+		$this->assert_safe_credential_failure( new SettingsService(), $payload );
+
+		self::assertSame( 0, SettingsServiceFunctionControl::$base64_decode_calls );
+	}
+
+	/**
+	 * Verifies the salt option is explicitly excluded from autoload.
+	 */
+	public function test_salt_option_uses_no_autoload(): void {
+		global $wpdb;
+
+		$service = new SettingsService();
+		$service->save( array( 'api_password' => 'autoload secret' ) );
+		// The option table is fixed and the option name is prepared.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
+		$autoload = $wpdb->get_var(
+			$wpdb->prepare(
+				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
+				self::SALT_OPTION
+			)
+		);
+
+		self::assertSame( 'no', $autoload );
+	}
+
+	/**
+	 * Verifies a concurrent salt winner is reread after add_option loses.
+	 */
+	public function test_salt_creation_race_uses_winning_option(): void {
+		$winning_salt = base64_encode( str_repeat( 'w', 32 ) );
+		SettingsServiceFunctionControl::$race_salt = $winning_salt;
+		$service = new SettingsService();
+
+		$service->save( array( 'api_password' => 'race secret' ) );
+
+		self::assertSame( $winning_salt, get_option( self::SALT_OPTION ) );
+		self::assertSame( 'race secret', $service->password() );
+	}
+
+	/**
+	 * Verifies KDF contexts produce separate raw keys.
+	 */
+	public function test_kdf_derives_distinct_encryption_and_authentication_keys(): void {
+		$service = new SettingsService();
+		$service->save( array( 'api_password' => 'key separation secret' ) );
+
+		self::assertCount( 2, SettingsServiceFunctionControl::$derived_keys );
+		self::assertNotSame(
+			SettingsServiceFunctionControl::$derived_keys[0],
+			SettingsServiceFunctionControl::$derived_keys[1]
+		);
+		self::assertSame( 32, strlen( SettingsServiceFunctionControl::$derived_keys[0] ) );
+		self::assertSame( 32, strlen( SettingsServiceFunctionControl::$derived_keys[1] ) );
+	}
+
+	/**
+	 * Verifies unavailable required primitives produce only the safe error.
+	 */
+	public function test_unavailable_primitive_throws_safe_exception(): void {
+		SettingsServiceFunctionControl::$unavailable_function = 'openssl_encrypt';
+
+		try {
+			( new SettingsService() )->save( array( 'api_password' => 'primitive secret' ) );
+			self::fail( 'Expected secure credential failure.' );
+		} catch ( RuntimeException $exception ) {
+			self::assertSame( 'Unable to process API credentials securely.', $exception->getMessage() );
+			self::assertStringNotContainsString( 'primitive secret', $exception->getMessage() );
+		}
+	}
+
+	/**
+	 * Verifies a required primitive failure result produces only the safe error.
+	 */
+	public function test_failing_primitive_throws_safe_exception(): void {
+		SettingsServiceFunctionControl::$encrypt_failure = true;
+
+		try {
+			( new SettingsService() )->save( array( 'api_password' => 'failed primitive secret' ) );
+			self::fail( 'Expected secure credential failure.' );
+		} catch ( RuntimeException $exception ) {
+			self::assertSame( 'Unable to process API credentials securely.', $exception->getMessage() );
+			self::assertStringNotContainsString( 'failed primitive secret', $exception->getMessage() );
+			self::assertFalse( get_option( self::SETTINGS_OPTION, false ) );
+		}
+	}
+
+	/**
+	 * Provides every HMAC call made while encrypting.
+	 *
+	 * @return array<string,array{0:int}>
+	 */
+	public function encryption_hmac_calls(): array {
+		return array(
+			'encryption KDF'     => array( 1 ),
+			'authentication KDF' => array( 2 ),
+			'payload MAC'        => array( 3 ),
+		);
+	}
+
+	/**
+	 * Verifies every encryption-side HMAC failure is fail-closed.
+	 *
+	 * @dataProvider encryption_hmac_calls
+	 */
+	public function test_encryption_hmac_failure_throws_safe_exception( int $failure_call ): void {
+		SettingsServiceFunctionControl::$hash_failure_at = $failure_call;
+
+		try {
+			( new SettingsService() )->save( array( 'api_password' => 'hmac secret' ) );
+			self::fail( 'Expected secure credential failure.' );
+		} catch ( RuntimeException $exception ) {
+			self::assertSame( 'Unable to process API credentials securely.', $exception->getMessage() );
+			self::assertFalse( get_option( self::SETTINGS_OPTION, false ) );
+		}
+	}
+
+	/**
+	 * Provides malformed encrypted credential representations.
+	 *
+	 * @return array<string,array{0:string}>
+	 */
+	public function malformed_passwords(): array {
+		return array(
+			'invalid base64'   => array( '%%%not-base64%%%' ),
+			'truncated'        => array( base64_encode( str_repeat( 'x', 47 ) ) ),
+			'block misaligned' => array( base64_encode( str_repeat( 'x', 49 ) ) ),
+		);
+	}
+
+	/**
+	 * Verifies malformed payloads fail with one safe error.
+	 *
+	 * @param string $payload Stored payload.
+	 *
+	 * @dataProvider malformed_passwords
+	 */
+	public function test_malformed_password_payload_throws_safe_exception( string $payload ): void {
+		update_option( self::SETTINGS_OPTION, array( 'api_password' => $payload ) );
+		$service = new SettingsService();
+
+		$this->assert_safe_credential_failure( $service, $payload );
+	}
+
+	/**
+	 * Verifies HMAC tampering is rejected before plaintext is returned.
+	 */
+	public function test_tampered_payload_throws_safe_exception(): void {
+		$service = new SettingsService();
+		$service->save( array( 'api_password' => 'do not disclose me' ) );
+		$stored                 = get_option( self::SETTINGS_OPTION );
+		$payload                = base64_decode( $stored['api_password'], true );
+		$payload[20]            = chr( ord( $payload[20] ) ^ 1 );
+		$stored['api_password'] = base64_encode( $payload );
+		update_option( self::SETTINGS_OPTION, $stored );
+
+		$this->assert_safe_credential_failure( $service, $stored['api_password'] );
+	}
+
+	/**
+	 * Verifies direct MAC tampering fails before OpenSSL decryption.
+	 */
+	public function test_tampered_mac_is_rejected_before_decryption(): void {
+		$service = new SettingsService();
+		$service->save( array( 'api_password' => 'do not disclose me' ) );
+		$stored                 = get_option( self::SETTINGS_OPTION );
+		$payload                = base64_decode( $stored['api_password'], true );
+		$last                   = strlen( $payload ) - 1;
+		$payload[ $last ]       = chr( ord( $payload[ $last ] ) ^ 1 );
+		$stored['api_password'] = base64_encode( $payload );
+		update_option( self::SETTINGS_OPTION, $stored );
+		SettingsServiceFunctionControl::$decrypt_calls = 0;
+
+		$this->assert_safe_credential_failure( $service, $stored['api_password'] );
+
+		self::assertSame( 0, SettingsServiceFunctionControl::$decrypt_calls );
+	}
+
+	/**
+	 * Verifies decryption-side MAC generation failure is fail-closed.
+	 */
+	public function test_decryption_hmac_failure_throws_safe_exception_before_decrypt(): void {
+		$service = new SettingsService();
+		$service->save( array( 'api_password' => 'decryption hmac secret' ) );
+		$ciphertext = get_option( self::SETTINGS_OPTION )['api_password'];
+		SettingsServiceFunctionControl::reset();
+		SettingsServiceFunctionControl::$hash_failure_at = 3;
+
+		$this->assert_safe_credential_failure( $service, $ciphertext );
+
+		self::assertSame( 0, SettingsServiceFunctionControl::$decrypt_calls );
+	}
+
+	/**
+	 * Verifies malformed project salt cannot silently change keys.
+	 */
+	public function test_malformed_salt_throws_safe_exception(): void {
+		$service = new SettingsService();
+		$service->save( array( 'api_password' => 'do not disclose me' ) );
+		$ciphertext = get_option( self::SETTINGS_OPTION )['api_password'];
+		update_option( self::SALT_OPTION, 'not strict base64!' );
+
+		$this->assert_safe_credential_failure( $service, $ciphertext );
+	}
+
+	/**
+	 * Asserts credential failures reveal neither secrets nor stored payloads.
+	 *
+	 * @param SettingsService $service    Service under test.
+	 * @param string          $ciphertext Stored credential representation.
+	 */
+	private function assert_safe_credential_failure( SettingsService $service, string $ciphertext ): void {
+		try {
+			$service->password();
+			self::fail( 'Expected secure credential failure.' );
+		} catch ( RuntimeException $exception ) {
+			self::assertSame( 'Unable to process API credentials securely.', $exception->getMessage() );
+			self::assertStringNotContainsString( 'do not disclose me', $exception->getMessage() );
+			self::assertStringNotContainsString( $ciphertext, $exception->getMessage() );
+		}
+	}
+}
+}
```

## Current Uncommitted Status

Command: `git status --short --branch --untracked-files=all`

```text
## feat/cinebot-wp
 M specs/execution-status.yaml
 M specs/state.yaml
?? .superpowers/sdd/progress.md
?? .superpowers/sdd/task-1-review-package.md
?? .superpowers/sdd/task-1-review.md
?? .superpowers/sdd/task-2-brief.md
?? .superpowers/sdd/task-2-review-package.md
?? .superpowers/sdd/task-2-review.md
?? .superpowers/sdd/task-3-brief.md
?? .superpowers/sdd/task-3-review-package.md
?? .superpowers/sdd/task-3-review.md
?? .superpowers/sdd/task-4-brief.md
?? .superpowers/sdd/task-4-review-package.md
?? .superpowers/sdd/task-4-review.md
?? .superpowers/sdd/task-5-brief.md
?? .superpowers/sdd/task-5-review-package.md
?? .superpowers/sdd/task-5-review.md
?? .superpowers/sdd/task-6-brief.md
?? .superpowers/sdd/task-6-review-package.md
?? .superpowers/sdd/task-6-review.md
?? .superpowers/sdd/task-7-brief.md
?? .superpowers/sdd/task-7-review-package.md
?? .superpowers/sdd/task-7-review.md
```

The modified `specs/` files and untracked coordinator/review artifacts are outside the Task 7 commits. No Task 7 implementation file is currently modified or untracked.
