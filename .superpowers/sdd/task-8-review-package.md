# Task 8 Reviewer Handoff Package

## Scope

Complete Task 8 range from parent `4f03fdcc0356049187ab7aebca9c8b9fd7c6dc7f` through implementation commit `8015d924e0a6b728a2520dfbfdad041fecfd83ad` and fix commit `d583f53ca3e96359f518fc53c505c6762bc41bc6`. Task 8 adds the programming API client, safe exception, poster URL builder, and focused unit coverage; the fix normalizes API-client failures and expands boundary validation.

The updated report records blocked Docker/PHP dynamic gates. Follow-up coverage includes corrupted credentials, throwing transports, exact 10 MiB response boundaries, native-array validation for `programmazione`, DNS length limits, and encoded control-byte poster paths.

## Commit Metadata

```text
commit 8015d924e0a6b728a2520dfbfdad041fecfd83ad
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 02:05:13 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 02:05:13 2026 +0200

    feat: fetch cinebot programming api

commit d583f53ca3e96359f518fc53c505c6762bc41bc6
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 17:52:14 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 17:52:14 2026 +0200

    fix: normalize api client failures
```

## Full Stat

Command: `git show --stat --format=fuller 8015d92 d583f53`

```text
commit 8015d924e0a6b728a2520dfbfdad041fecfd83ad
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 02:05:13 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 02:05:13 2026 +0200

    feat: fetch cinebot programming api

 .superpowers/sdd/task-8-report.md      |  34 +++
 includes/Services/ApiClient.php        | 113 ++++++++++
 includes/Services/ApiException.php     |  33 +++
 includes/Services/LocandinaService.php |  92 ++++++++
 tests/Unit/ApiClientTest.php           | 393 +++++++++++++++++++++++++++++++++
 tests/Unit/LocandinaServiceTest.php    | 199 +++++++++++++++++
 6 files changed, 864 insertions(+)

commit d583f53ca3e96359f518fc53c505c6762bc41bc6
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 17:52:14 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 17:52:14 2026 +0200

    fix: normalize api client failures

 .superpowers/sdd/task-8-report.md   |   4 +-
 includes/Services/ApiClient.php     |  49 ++++++++-----
 tests/Unit/ApiClientTest.php        | 134 ++++++++++++++++++++++++++++++++++--
 tests/Unit/LocandinaServiceTest.php |  38 ++++++++++
 4 files changed, 202 insertions(+), 23 deletions(-)
```

The committed Task 8 report is coordination evidence represented in the stat and summarized above; it is excluded from the implementation diff.

## Full Relevant Diff

This cumulative diff shows the final Task 8 implementation and tests after both commits.

Command: `git diff --unified=10 4f03fdc d583f53 -- includes/Services/ApiClient.php includes/Services/ApiException.php includes/Services/LocandinaService.php tests/Unit/ApiClientTest.php tests/Unit/LocandinaServiceTest.php`

```diff
diff --git a/includes/Services/ApiClient.php b/includes/Services/ApiClient.php
new file mode 100644
index 0000000..673ba12
--- /dev/null
+++ b/includes/Services/ApiClient.php
@@ -0,0 +1,128 @@
+<?php
+/**
+ * Authenticated Cinebot API client.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Services;
+
+use Throwable;
+use WP_Error;
+
+/**
+ * Fetches and validates the Cinebot programming response.
+ */
+final class ApiClient {
+	private const MAX_BODY_BYTES = 10485760;
+
+	/** @var SettingsService */
+	private $settings;
+
+	/** @var callable */
+	private $http_get;
+
+	/**
+	 * Creates an API client with an optional test transport.
+	 */
+	public function __construct( SettingsService $settings, ?callable $httpGet = null ) {
+		$this->settings = $settings;
+		$this->http_get = $httpGet ?? static function ( string $url, array $args ) {
+			return wp_remote_get( $url, $args );
+		};
+	}
+
+	/**
+	 * Fetches one validated programming payload.
+	 *
+	 * @return array<string,mixed>
+	 *
+	 * @throws ApiException When credentials, transport, or response data are invalid.
+	 */
+	public function fetchProgrammazione(): array {
+		try {
+			$username = $this->settings->username();
+			$password = $this->settings->password();
+			$base_url = $this->settings->baseUrl();
+			$frontend = $this->settings->frontend();
+		} catch ( Throwable $exception ) {
+			throw new ApiException( 'Unable to prepare the Cinebot API request.' );
+		}
+		if ( '' === $username || '' === $password ) {
+			throw new ApiException( 'API credentials are not configured.' );
+		}
+
+		$url = rtrim( $base_url, '/' ) . '/v1/programmazione';
+		if ( null !== $frontend ) {
+			$url .= '/' . $frontend;
+		}
+
+		try {
+			$response = call_user_func(
+				$this->http_get,
+				$url,
+				array(
+					'headers'            => array(
+						'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
+						'Accept'        => 'application/json',
+					),
+					'timeout'            => 60,
+					'redirection'        => 3,
+					'reject_unsafe_urls' => true,
+				)
+			);
+		} catch ( ApiException $exception ) {
+			throw $exception;
+		} catch ( Throwable $exception ) {
+			throw new ApiException( 'Unable to connect to the Cinebot API.' );
+		}
+
+		if ( $response instanceof WP_Error ) {
+			throw new ApiException( 'Unable to connect to the Cinebot API.' );
+		}
+		if ( ! is_array( $response ) ) {
+			throw new ApiException( 'Cinebot API response is invalid.' );
+		}
+
+		$status = (int) wp_remote_retrieve_response_code( $response );
+		if ( 401 === $status ) {
+			throw new ApiException( 'Cinebot API authentication failed.', 401 );
+		}
+		if ( 200 !== $status ) {
+			throw new ApiException( 'Cinebot API returned HTTP status ' . $status . '.', $status );
+		}
+
+		$body = wp_remote_retrieve_body( $response );
+		if ( ! is_string( $body ) || strlen( $body ) > self::MAX_BODY_BYTES ) {
+			throw new ApiException( 'Cinebot API response is invalid.' );
+		}
+
+		$object = json_decode( $body );
+		if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $object ) ) {
+			throw new ApiException( 'Cinebot API response is invalid.' );
+		}
+		if ( ! isset( $object->programmazione ) || ! is_array( $object->programmazione ) ) {
+			throw new ApiException( 'Cinebot API programming data is invalid.' );
+		}
+
+		$payload = json_decode( $body, true );
+		if ( ! is_array( $payload ) ) {
+			throw new ApiException( 'Cinebot API response is invalid.' );
+		}
+		if ( array_key_exists( 'status', $payload ) && 200 !== $payload['status'] ) {
+			throw new ApiException( 'Cinebot API response status is invalid.' );
+		}
+		if (
+			array_key_exists( 'error', $payload )
+			&& null !== $payload['error']
+			&& '' !== $payload['error']
+		) {
+			throw new ApiException( 'Cinebot API reported an error.' );
+		}
+		if ( ! isset( $payload['programmazione'] ) || ! is_array( $payload['programmazione'] ) ) {
+			throw new ApiException( 'Cinebot API programming data is invalid.' );
+		}
+
+		return $payload;
+	}
+}
diff --git a/includes/Services/ApiException.php b/includes/Services/ApiException.php
new file mode 100644
index 0000000..cdbcb61
--- /dev/null
+++ b/includes/Services/ApiException.php
@@ -0,0 +1,33 @@
+<?php
+/**
+ * Safe Cinebot API failure.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Services;
+
+use RuntimeException;
+
+/**
+ * Represents an API failure without retaining upstream response content.
+ */
+final class ApiException extends RuntimeException {
+	/** @var int|null */
+	private $status;
+
+	/**
+	 * Creates a safe API exception.
+	 */
+	public function __construct( string $message, ?int $status = null ) {
+		parent::__construct( $message );
+		$this->status = $status;
+	}
+
+	/**
+	 * Returns the HTTP status when one is available.
+	 */
+	public function status(): ?int {
+		return $this->status;
+	}
+}
diff --git a/includes/Services/LocandinaService.php b/includes/Services/LocandinaService.php
new file mode 100644
index 0000000..9894b1a
--- /dev/null
+++ b/includes/Services/LocandinaService.php
@@ -0,0 +1,92 @@
+<?php
+/**
+ * Cinebot poster URL builder.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Services;
+
+use InvalidArgumentException;
+
+/**
+ * Builds deterministic HTTPS URLs from validated API poster fields.
+ */
+final class LocandinaService {
+	private const SAFE_ERROR = 'Unable to build poster URL.';
+
+	/**
+	 * Builds a poster URL when the API flag enables one.
+	 */
+	public function build( string $host, string $path, int $titleId, int $flag ): ?string {
+		if ( $flag <= 0 ) {
+			return null;
+		}
+		if ( $titleId <= 0 ) {
+			throw new InvalidArgumentException( self::SAFE_ERROR );
+		}
+
+		$host = strtolower( $host );
+		if ( ! $this->isValidHost( $host ) ) {
+			throw new InvalidArgumentException( self::SAFE_ERROR );
+		}
+
+		$path = trim( $path, '/' );
+		if ( '' === $path ) {
+			throw new InvalidArgumentException( self::SAFE_ERROR );
+		}
+
+		$segments = explode( '/', $path );
+		foreach ( $segments as &$segment ) {
+			if ( ! $this->isValidSegment( $segment ) ) {
+				throw new InvalidArgumentException( self::SAFE_ERROR );
+			}
+			$segment = rawurlencode( $segment );
+		}
+		unset( $segment );
+
+		return 'https://' . $host . '/' . implode( '/', $segments ) . '/titolo/' . $titleId . '/locandina';
+	}
+
+	/**
+	 * Checks a DNS hostname without accepting ports, IPs, or localhost.
+	 */
+	private function isValidHost( string $host ): bool {
+		if (
+			'' === $host
+			|| strlen( $host ) > 253
+			|| false === strpos( $host, '.' )
+			|| false !== filter_var( $host, FILTER_VALIDATE_IP )
+		) {
+			return false;
+		}
+
+		$labels = explode( '.', $host );
+		foreach ( $labels as $label ) {
+			if (
+				'' === $label
+				|| strlen( $label ) > 63
+				|| 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label )
+			) {
+				return false;
+			}
+		}
+
+		return true;
+	}
+
+	/**
+	 * Checks one relative path segment before URL encoding.
+	 */
+	private function isValidSegment( string $segment ): bool {
+		return '' !== $segment
+			&& '.' !== $segment
+			&& '..' !== $segment
+			&& false === strpos( $segment, '\\' )
+			&& false === strpos( $segment, '?' )
+			&& false === strpos( $segment, '#' )
+			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $segment )
+			&& 1 !== preg_match( '/[a-z][a-z0-9+.-]*:/i', $segment )
+			&& 1 !== preg_match( '/%(?:2f|3f|23|5c|0[0-9a-f]|1[0-9a-f]|7f)/i', $segment );
+	}
+}
diff --git a/tests/Unit/ApiClientTest.php b/tests/Unit/ApiClientTest.php
new file mode 100644
index 0000000..2ff4523
--- /dev/null
+++ b/tests/Unit/ApiClientTest.php
@@ -0,0 +1,517 @@
+<?php
+/**
+ * Cinebot API client unit tests.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Tests\Unit;
+
+use CinebotWp\Services\ApiClient;
+use CinebotWp\Services\ApiException;
+use CinebotWp\Services\SettingsService;
+use PHPUnit\Framework\TestCase;
+use RuntimeException;
+use WP_Error;
+
+/**
+ * Verifies authenticated requests and strict API response validation.
+ */
+final class ApiClientTest extends TestCase {
+	private const SETTINGS_OPTION = 'cinebot_wp_settings';
+	private const SALT_OPTION     = 'cinebot_wp_encryption_salt';
+	private const USERNAME        = 'api-user';
+	private const PASSWORD        = 'do-not-disclose-password';
+
+	/** Remove API settings before each test. */
+	protected function setUp(): void {
+		parent::setUp();
+		delete_option( self::SETTINGS_OPTION );
+		delete_option( self::SALT_OPTION );
+	}
+
+	/** Remove API settings after each test. */
+	protected function tearDown(): void {
+		delete_option( self::SETTINGS_OPTION );
+		delete_option( self::SALT_OPTION );
+		parent::tearDown();
+	}
+
+	/**
+	 * Verifies the frontend URL and exact safe transport arguments.
+	 */
+	public function test_fetch_uses_frontend_url_and_exact_transport_arguments(): void {
+		$captured_url  = '';
+		$captured_args = array();
+		$client        = new ApiClient(
+			$this->settings( 50, 'https://API.EXAMPLE.test/root/' ),
+			static function ( string $url, array $args ) use ( &$captured_url, &$captured_args ): array {
+				$captured_url  = $url;
+				$captured_args = $args;
+
+				return self::response( 200, '{"programmazione":[]}' );
+			}
+		);
+
+		$client->fetchProgrammazione();
+
+		self::assertSame( 'https://api.example.test/root/v1/programmazione/50', $captured_url );
+		self::assertSame(
+			array(
+				'headers'            => array(
+					'Authorization' => 'Basic ' . base64_encode( self::USERNAME . ':' . self::PASSWORD ),
+					'Accept'        => 'application/json',
+				),
+				'timeout'            => 60,
+				'redirection'        => 3,
+				'reject_unsafe_urls' => true,
+			),
+			$captured_args
+		);
+	}
+
+	/**
+	 * Verifies no frontend segment or double slash is added when none is set.
+	 */
+	public function test_fetch_uses_unscoped_url_without_double_slashes(): void {
+		$captured_url = '';
+		$client       = new ApiClient(
+			$this->settings( null, 'https://api.example.test/' ),
+			static function ( string $url, array $args ) use ( &$captured_url ): array {
+				$captured_url = $url;
+
+				return self::response( 200, '{"programmazione":[]}' );
+			}
+		);
+
+		$client->fetchProgrammazione();
+
+		self::assertSame( 'https://api.example.test/v1/programmazione', $captured_url );
+	}
+
+	/**
+	 * Provides missing credential combinations.
+	 *
+	 * @return array<string,array{0:string,1:string}>
+	 */
+	public function missing_credentials(): array {
+		return array(
+			'missing username' => array( '', self::PASSWORD ),
+			'missing password' => array( self::USERNAME, '' ),
+		);
+	}
+
+	/**
+	 * Verifies missing credentials fail before transport without leaking values.
+	 *
+	 * @dataProvider missing_credentials
+	 */
+	public function test_empty_credentials_fail_safely_before_transport( string $username, string $password ): void {
+		$called   = false;
+		$settings = new SettingsService();
+		$settings->save(
+			array(
+				'api_username' => $username,
+				'api_password' => $password,
+			)
+		);
+		$client = new ApiClient(
+			$settings,
+			static function () use ( &$called ): array {
+				$called = true;
+
+				return self::response( 200, '{"programmazione":[]}' );
+			}
+		);
+
+		$this->assert_safe_api_exception(
+			static function () use ( $client ): void {
+				$client->fetchProgrammazione();
+			},
+			array( $username, $password )
+		);
+		self::assertFalse( $called );
+	}
+
+	/**
+	 * Verifies credential decryption failures become safe API failures before transport.
+	 */
+	public function test_tampered_password_throws_safe_api_exception_before_transport(): void {
+		$called                 = false;
+		$settings               = $this->settings();
+		$stored                 = get_option( self::SETTINGS_OPTION );
+		$encrypted              = $stored['api_password'];
+		$payload                = base64_decode( $encrypted, true );
+		$last                   = strlen( $payload ) - 1;
+		$payload[ $last ]       = chr( ord( $payload[ $last ] ) ^ 1 );
+		$stored['api_password'] = base64_encode( $payload );
+		update_option( self::SETTINGS_OPTION, $stored );
+		$client = new ApiClient(
+			$settings,
+			static function () use ( &$called ): array {
+				$called = true;
+
+				return self::response( 200, '{"programmazione":[]}' );
+			}
+		);
+
+		try {
+			$client->fetchProgrammazione();
+			self::fail( 'Expected safe settings failure.' );
+		} catch ( ApiException $exception ) {
+			self::assertSame( 'Unable to prepare the Cinebot API request.', $exception->getMessage() );
+			$this->assert_message_excludes( $exception, array( $encrypted, $stored['api_password'], self::PASSWORD ) );
+		}
+		self::assertFalse( $called );
+	}
+
+	/**
+	 * Verifies network details and request URLs are not disclosed.
+	 */
+	public function test_wp_error_throws_safe_network_exception(): void {
+		$url_fragment = 'private-host.example.test';
+		$upstream     = 'Could not resolve ' . $url_fragment . '?token=secret';
+		$client       = new ApiClient(
+			$this->settings( null, 'https://' . $url_fragment ),
+			static function () use ( $upstream ): WP_Error {
+				return new WP_Error( 'http_request_failed', $upstream );
+			}
+		);
+
+		$this->assert_safe_api_exception(
+			static function () use ( $client ): void {
+				$client->fetchProgrammazione();
+			},
+			array( $url_fragment, $upstream, self::PASSWORD )
+		);
+	}
+
+	/**
+	 * Verifies malformed transport results fail safely.
+	 */
+	public function test_invalid_transport_result_throws_safe_exception(): void {
+		$client = $this->clientReturning( 'transport-result-secret' );
+
+		$this->assert_safe_api_exception(
+			static function () use ( $client ): void {
+				$client->fetchProgrammazione();
+			},
+			array( 'transport-result-secret', self::PASSWORD )
+		);
+	}
+
+	/**
+	 * Verifies thrown transport details are normalized to a fixed safe failure.
+	 */
+	public function test_throwing_transport_throws_safe_api_exception(): void {
+		$upstream = 'transport exception secret https://private.example.test?token=secret';
+		$client   = new ApiClient(
+			$this->settings(),
+			static function () use ( $upstream ): void {
+				throw new RuntimeException( $upstream );
+			}
+		);
+
+		try {
+			$client->fetchProgrammazione();
+			self::fail( 'Expected safe transport failure.' );
+		} catch ( ApiException $exception ) {
+			self::assertSame( 'Unable to connect to the Cinebot API.', $exception->getMessage() );
+			$this->assert_message_excludes( $exception, array( $upstream, self::PASSWORD ) );
+		}
+	}
+
+	/**
+	 * Verifies transport ApiExceptions retain their safe status semantics.
+	 */
+	public function test_transport_api_exception_is_preserved(): void {
+		$expected = new ApiException( 'Safe transport status.', 429 );
+		$client   = new ApiClient(
+			$this->settings(),
+			static function () use ( $expected ): void {
+				throw $expected;
+			}
+		);
+
+		try {
+			$client->fetchProgrammazione();
+			self::fail( 'Expected transport API exception.' );
+		} catch ( ApiException $exception ) {
+			self::assertSame( $expected, $exception );
+			self::assertSame( 429, $exception->status() );
+		}
+	}
+
+	/**
+	 * Verifies authentication failures retain only the safe HTTP status.
+	 */
+	public function test_http_401_throws_safe_authentication_exception(): void {
+		$body   = 'upstream authentication body secret';
+		$client = $this->clientReturning( self::response( 401, $body ) );
+
+		try {
+			$client->fetchProgrammazione();
+			self::fail( 'Expected API authentication failure.' );
+		} catch ( ApiException $exception ) {
+			self::assertSame( 401, $exception->status() );
+			$this->assert_message_excludes( $exception, array( $body, self::PASSWORD ) );
+		}
+	}
+
+	/**
+	 * Verifies other response failures expose only their numeric status.
+	 */
+	public function test_non_200_http_status_throws_safe_status_exception(): void {
+		$body   = '{"error":"database body secret"}';
+		$client = $this->clientReturning( self::response( 500, $body ) );
+
+		try {
+			$client->fetchProgrammazione();
+			self::fail( 'Expected API status failure.' );
+		} catch ( ApiException $exception ) {
+			self::assertSame( 500, $exception->status() );
+			self::assertStringContainsString( '500', $exception->getMessage() );
+			$this->assert_message_excludes( $exception, array( $body, 'database body secret', self::PASSWORD ) );
+		}
+	}
+
+	/**
+	 * Provides malformed or invalid top-level response bodies.
+	 *
+	 * @return array<string,array{0:string}>
+	 */
+	public function invalid_json_bodies(): array {
+		return array(
+			'malformed JSON' => array( '{"programmazione":[' ),
+			'JSON string'    => array( '"body scalar secret"' ),
+			'JSON integer'   => array( '42' ),
+			'JSON null'      => array( 'null' ),
+		);
+	}
+
+	/**
+	 * Verifies malformed and scalar JSON bodies fail without body disclosure.
+	 *
+	 * @dataProvider invalid_json_bodies
+	 */
+	public function test_invalid_json_body_throws_safe_exception( string $body ): void {
+		$client = $this->clientReturning( self::response( 200, $body ) );
+
+		$this->assert_safe_api_exception(
+			static function () use ( $client ): void {
+				$client->fetchProgrammazione();
+			},
+			array( $body, 'body scalar secret', self::PASSWORD )
+		);
+	}
+
+	/**
+	 * Verifies a valid response exactly at the body limit is accepted.
+	 */
+	public function test_body_exactly_at_limit_is_accepted(): void {
+		$body   = $this->validBodyAtSize( 10 * 1024 * 1024 );
+		$client = $this->clientReturning( self::response( 200, $body ) );
+
+		self::assertSame( 10 * 1024 * 1024, strlen( $body ) );
+		self::assertSame( array(), $client->fetchProgrammazione()['programmazione'] );
+	}
+
+	/**
+	 * Verifies a valid response one byte above the body limit is rejected.
+	 */
+	public function test_body_one_byte_over_limit_throws_safe_exception(): void {
+		$body   = $this->validBodyAtSize( ( 10 * 1024 * 1024 ) + 1 );
+		$client = $this->clientReturning( self::response( 200, $body ) );
+
+		self::assertSame( ( 10 * 1024 * 1024 ) + 1, strlen( $body ) );
+		$this->assert_safe_api_exception(
+			static function () use ( $client ): void {
+				$client->fetchProgrammazione();
+			},
+			array( self::PASSWORD )
+		);
+	}
+
+	/**
+	 * Provides JSON object values that are invalid programming arrays.
+	 *
+	 * @return array<string,array{0:string}>
+	 */
+	public function object_programmazione_bodies(): array {
+		return array(
+			'empty object'     => array( '{"programmazione":{}}' ),
+			'non-empty object' => array( '{"programmazione":{"frontend":50}}' ),
+		);
+	}
+
+	/**
+	 * Verifies JSON objects are rejected before associative conversion.
+	 *
+	 * @dataProvider object_programmazione_bodies
+	 */
+	public function test_object_programmazione_throws_safe_exception( string $body ): void {
+		$client = $this->clientReturning( self::response( 200, $body ) );
+
+		$this->assert_safe_api_exception(
+			static function () use ( $client ): void {
+				$client->fetchProgrammazione();
+			},
+			array( $body, self::PASSWORD )
+		);
+	}
+
+	/**
+	 * Provides rejected API payload envelopes.
+	 *
+	 * @return array<string,array{0:array<string,mixed>}>
+	 */
+	public function invalid_payloads(): array {
+		return array(
+			'non-200 integer status' => array( array( 'status' => 500, 'programmazione' => array() ) ),
+			'string status'          => array( array( 'status' => '200', 'programmazione' => array() ) ),
+			'non-empty error'        => array( array( 'error' => 'payload-error-secret', 'programmazione' => array() ) ),
+			'array error'            => array( array( 'error' => array( 'payload-error-secret' ), 'programmazione' => array() ) ),
+			'missing programming'    => array( array( 'status' => 200, 'error' => null ) ),
+			'wrong programming type' => array( array( 'programmazione' => 'payload-programming-secret' ) ),
+		);
+	}
+
+	/**
+	 * Verifies payload envelope failures never expose upstream data.
+	 *
+	 * @param array<string,mixed> $payload Invalid response payload.
+	 *
+	 * @dataProvider invalid_payloads
+	 */
+	public function test_invalid_payload_envelope_throws_safe_exception( array $payload ): void {
+		$body   = wp_json_encode( $payload );
+		$client = $this->clientReturning( self::response( 200, $body ) );
+
+		$this->assert_safe_api_exception(
+			static function () use ( $client ): void {
+				$client->fetchProgrammazione();
+			},
+			array( $body, 'payload-error-secret', 'payload-programming-secret', self::PASSWORD )
+		);
+	}
+
+	/**
+	 * Verifies empty programming data and empty optional error are valid.
+	 */
+	public function test_empty_programmazione_is_returned_unchanged(): void {
+		$payload = array(
+			'status'          => 200,
+			'error'           => '',
+			'programmazione' => array(),
+			'meta'            => array( 'request_id' => 17 ),
+		);
+		$client  = $this->clientReturning( self::response( 200, wp_json_encode( $payload ) ) );
+
+		self::assertSame( $payload, $client->fetchProgrammazione() );
+	}
+
+	/**
+	 * Verifies a complete valid associative payload is returned unchanged.
+	 */
+	public function test_valid_payload_is_returned_unchanged(): void {
+		$payload = array(
+			'status'          => 200,
+			'error'           => null,
+			'programmazione' => array(
+				array(
+					'frontend' => 50,
+					'titoli'   => array( array( 'idtitolo' => 491 ) ),
+				),
+			),
+			'additional'      => true,
+		);
+		$client  = $this->clientReturning( self::response( 200, wp_json_encode( $payload ) ) );
+
+		self::assertSame( $payload, $client->fetchProgrammazione() );
+	}
+
+	/**
+	 * Creates persisted settings for a client test.
+	 */
+	private function settings( ?int $frontend = null, string $base_url = 'https://api.example.test' ): SettingsService {
+		$settings = new SettingsService();
+		$settings->save(
+			array(
+				'api_username' => self::USERNAME,
+				'api_password' => self::PASSWORD,
+				'api_frontend' => $frontend,
+				'api_base_url' => $base_url,
+			)
+		);
+
+		return $settings;
+	}
+
+	/**
+	 * Creates a client whose injected transport returns one response.
+	 *
+	 * @param mixed $response Transport response.
+	 */
+	private function clientReturning( $response ): ApiClient {
+		return new ApiClient(
+			$this->settings(),
+			static function () use ( $response ) {
+				return $response;
+			}
+		);
+	}
+
+	/**
+	 * Builds a minimal WordPress HTTP response.
+	 *
+	 * @return array<string,mixed>
+	 */
+	private static function response( int $status, string $body ): array {
+		return array(
+			'response' => array(
+				'code'    => $status,
+				'message' => 'upstream response message',
+			),
+			'body'     => $body,
+		);
+	}
+
+	/**
+	 * Builds valid JSON at an exact byte size.
+	 */
+	private function validBodyAtSize( int $size ): string {
+		$prefix = '{"programmazione":[],"padding":"';
+		$suffix = '"}';
+
+		return $prefix . str_repeat( 'x', $size - strlen( $prefix ) - strlen( $suffix ) ) . $suffix;
+	}
+
+	/**
+	 * Asserts a callback fails with an ApiException containing no sensitive values.
+	 *
+	 * @param callable            $operation        Operation expected to fail.
+	 * @param array<int,string>   $sensitive_values Values forbidden from the message.
+	 */
+	private function assert_safe_api_exception( callable $operation, array $sensitive_values ): void {
+		try {
+			$operation();
+			self::fail( 'Expected safe API failure.' );
+		} catch ( ApiException $exception ) {
+			$this->assert_message_excludes( $exception, $sensitive_values );
+		}
+	}
+
+	/**
+	 * Asserts an exception message excludes every non-empty sensitive value.
+	 *
+	 * @param array<int,string> $sensitive_values Values forbidden from the message.
+	 */
+	private function assert_message_excludes( ApiException $exception, array $sensitive_values ): void {
+		self::assertNotSame( '', $exception->getMessage() );
+		foreach ( $sensitive_values as $sensitive_value ) {
+			if ( '' !== $sensitive_value ) {
+				self::assertStringNotContainsString( $sensitive_value, $exception->getMessage() );
+			}
+		}
+	}
+}
diff --git a/tests/Unit/LocandinaServiceTest.php b/tests/Unit/LocandinaServiceTest.php
new file mode 100644
index 0000000..3b91038
--- /dev/null
+++ b/tests/Unit/LocandinaServiceTest.php
@@ -0,0 +1,237 @@
+<?php
+/**
+ * Poster URL service unit tests.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Tests\Unit;
+
+use CinebotWp\Services\LocandinaService;
+use InvalidArgumentException;
+use PHPUnit\Framework\TestCase;
+
+/**
+ * Verifies deterministic and safe poster URL construction.
+ */
+final class LocandinaServiceTest extends TestCase {
+	/**
+	 * Verifies disabled posters do not validate unrelated input.
+	 */
+	public function test_non_positive_flag_returns_null_without_other_validation(): void {
+		$service = new LocandinaService();
+
+		self::assertNull( $service->build( 'https://invalid/?secret=1', '../invalid', 0, 0 ) );
+		self::assertNull( $service->build( '', '', -1, -5 ) );
+	}
+
+	/**
+	 * Verifies the required canonical sample exactly.
+	 */
+	public function test_build_returns_exact_sample_url(): void {
+		$service = new LocandinaService();
+
+		self::assertSame(
+			'https://ticket.cinebot.it/martinovich/titolo/491/locandina',
+			$service->build( 'ticket.cinebot.it', 'martinovich', 491, 1 )
+		);
+	}
+
+	/**
+	 * Verifies host casing and surrounding path slashes are normalized.
+	 */
+	public function test_build_normalizes_host_and_surrounding_path_slashes(): void {
+		$service = new LocandinaService();
+
+		self::assertSame(
+			'https://ticket.cinebot.it/martinovich/titolo/491/locandina',
+			$service->build( 'TICKET.CINEBOT.IT', '///martinovich///', 491, 2 )
+		);
+	}
+
+	/**
+	 * Verifies safe nested path segments are independently URL encoded.
+	 */
+	public function test_build_encodes_each_safe_nested_path_segment(): void {
+		$service = new LocandinaService();
+
+		self::assertSame(
+			'https://ticket.cinebot.it/cinema%20uno/sala%2Bdue/titolo/491/locandina',
+			$service->build( 'ticket.cinebot.it', 'cinema uno/sala+due', 491, 1 )
+		);
+	}
+
+	/**
+	 * Verifies already encoded text remains literal and cannot become traversal.
+	 */
+	public function test_build_double_encodes_percent_signs_to_prevent_encoded_traversal(): void {
+		$service = new LocandinaService();
+
+		self::assertSame(
+			'https://ticket.cinebot.it/%252e%252e/poster/titolo/491/locandina',
+			$service->build( 'ticket.cinebot.it', '%2e%2e/poster', 491, 1 )
+		);
+	}
+
+	/**
+	 * Verifies repeated calls produce exactly the same URL.
+	 */
+	public function test_build_is_deterministic(): void {
+		$service = new LocandinaService();
+		$first   = $service->build( 'TICKET.CINEBOT.IT', '/cinema uno/poster/', 491, 1 );
+
+		self::assertSame( $first, $service->build( 'TICKET.CINEBOT.IT', '/cinema uno/poster/', 491, 1 ) );
+	}
+
+	/**
+	 * Verifies the maximum DNS label length is accepted.
+	 */
+	public function test_build_accepts_63_byte_dns_label(): void {
+		$host = str_repeat( 'a', 63 ) . '.example';
+
+		self::assertSame(
+			'https://' . $host . '/poster/titolo/491/locandina',
+			( new LocandinaService() )->build( $host, 'poster', 491, 1 )
+		);
+	}
+
+	/**
+	 * Verifies the maximum total DNS host length is accepted.
+	 */
+	public function test_build_accepts_253_byte_dns_host(): void {
+		$host = str_repeat( 'a', 63 ) . '.'
+			. str_repeat( 'b', 63 ) . '.'
+			. str_repeat( 'c', 63 ) . '.'
+			. str_repeat( 'd', 61 );
+
+		self::assertSame( 253, strlen( $host ) );
+		self::assertSame(
+			'https://' . $host . '/poster/titolo/491/locandina',
+			( new LocandinaService() )->build( $host, 'poster', 491, 1 )
+		);
+	}
+
+	/**
+	 * Provides invalid positive title identifiers.
+	 *
+	 * @return array<string,array{0:int}>
+	 */
+	public function invalid_title_ids(): array {
+		return array(
+			'zero'     => array( 0 ),
+			'negative' => array( -1 ),
+		);
+	}
+
+	/**
+	 * Verifies enabled posters require a positive title identifier.
+	 *
+	 * @dataProvider invalid_title_ids
+	 */
+	public function test_positive_flag_rejects_non_positive_title_id( int $title_id ): void {
+		$this->expectException( InvalidArgumentException::class );
+		$this->expectExceptionMessage( 'Unable to build poster URL.' );
+
+		( new LocandinaService() )->build( 'ticket.cinebot.it', 'martinovich', $title_id, 1 );
+	}
+
+	/**
+	 * Provides invalid poster hosts.
+	 *
+	 * @return array<string,array{0:string}>
+	 */
+	public function invalid_hosts(): array {
+		$host_254 = str_repeat( 'a', 63 ) . '.'
+			. str_repeat( 'b', 63 ) . '.'
+			. str_repeat( 'c', 63 ) . '.'
+			. str_repeat( 'd', 62 );
+
+		return array(
+			'empty'              => array( '' ),
+			'HTTPS scheme'       => array( 'https://ticket.cinebot.it' ),
+			'HTTP scheme'        => array( 'http://ticket.cinebot.it' ),
+			'user info'          => array( 'user@ticket.cinebot.it' ),
+			'password user info' => array( 'user:pass@ticket.cinebot.it' ),
+			'port'               => array( 'ticket.cinebot.it:443' ),
+			'path'               => array( 'ticket.cinebot.it/path' ),
+			'query'              => array( 'ticket.cinebot.it?secret=1' ),
+			'fragment'           => array( 'ticket.cinebot.it#part' ),
+			'localhost'          => array( 'localhost' ),
+			'IP address'         => array( '127.0.0.1' ),
+			'IPv6 address'       => array( '[::1]' ),
+			'underscore'         => array( 'ticket_bad.cinebot.it' ),
+			'empty label'        => array( 'ticket..cinebot.it' ),
+			'leading hyphen'     => array( '-ticket.cinebot.it' ),
+			'trailing hyphen'    => array( 'ticket-.cinebot.it' ),
+			'trailing dot'       => array( 'ticket.cinebot.it.' ),
+			'space'              => array( 'ticket cinebot.it' ),
+			'control character'  => array( "ticket.cinebot.it\n" ),
+			'64-byte label'      => array( str_repeat( 'a', 64 ) . '.example' ),
+			'254-byte host'      => array( $host_254 ),
+		);
+	}
+
+	/**
+	 * Verifies invalid hosts fail without reflecting their content.
+	 *
+	 * @dataProvider invalid_hosts
+	 */
+	public function test_positive_flag_rejects_invalid_host( string $host ): void {
+		try {
+			( new LocandinaService() )->build( $host, 'martinovich', 491, 1 );
+			self::fail( 'Expected invalid poster host.' );
+		} catch ( InvalidArgumentException $exception ) {
+			self::assertSame( 'Unable to build poster URL.', $exception->getMessage() );
+			if ( '' !== $host ) {
+				self::assertStringNotContainsString( $host, $exception->getMessage() );
+			}
+		}
+	}
+
+	/**
+	 * Provides invalid poster paths.
+	 *
+	 * @return array<string,array{0:string}>
+	 */
+	public function invalid_paths(): array {
+		return array(
+			'empty'             => array( '' ),
+			'only slashes'      => array( '///' ),
+			'empty segment'     => array( 'cinema//poster' ),
+			'current segment'   => array( 'cinema/./poster' ),
+			'parent segment'    => array( 'cinema/../poster' ),
+			'backslash'         => array( 'cinema\\poster' ),
+			'query'             => array( 'cinema/poster?size=large' ),
+			'fragment'          => array( 'cinema/poster#large' ),
+			'HTTPS marker'      => array( 'https://ticket.cinebot.it/poster' ),
+			'HTTP marker'       => array( 'http://ticket.cinebot.it/poster' ),
+			'generic scheme'    => array( 'javascript:alert(1)' ),
+			'null control'      => array( "cinema/pos\0ter" ),
+			'newline control'   => array( "cinema/pos\nter" ),
+			'encoded query'     => array( 'cinema%3fsecret/poster' ),
+			'encoded fragment'  => array( 'cinema%23secret/poster' ),
+			'encoded slash'     => array( 'cinema%2fsecret/poster' ),
+			'encoded backslash' => array( 'cinema%5csecret/poster' ),
+			'encoded null'      => array( 'cinema%00secret/poster' ),
+			'encoded unit sep'  => array( 'cinema%1fsecret/poster' ),
+			'encoded delete'    => array( 'cinema%7fsecret/poster' ),
+		);
+	}
+
+	/**
+	 * Verifies path syntax cannot escape or alter the generated URL.
+	 *
+	 * @dataProvider invalid_paths
+	 */
+	public function test_positive_flag_rejects_invalid_path( string $path ): void {
+		try {
+			( new LocandinaService() )->build( 'ticket.cinebot.it', $path, 491, 1 );
+			self::fail( 'Expected invalid poster path.' );
+		} catch ( InvalidArgumentException $exception ) {
+			self::assertSame( 'Unable to build poster URL.', $exception->getMessage() );
+			if ( '' !== $path ) {
+				self::assertStringNotContainsString( $path, $exception->getMessage() );
+			}
+		}
+	}
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
?? .superpowers/sdd/task-8-brief.md
?? .superpowers/sdd/task-8-review-package.md
?? .superpowers/sdd/task-8-review.md
```

The modified `specs/` files and untracked coordinator/review artifacts are outside the Task 8 commits. No Task 8 implementation file is currently modified or untracked.
