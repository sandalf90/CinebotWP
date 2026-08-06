<?php
/**
 * Cinebot API client unit tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Unit;

use CinebotWp\Services\ApiClient;
use CinebotWp\Services\ApiException;
use CinebotWp\Services\SettingsService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_Error;

/**
 * Verifies authenticated requests and strict API response validation.
 */
final class ApiClientTest extends TestCase {
	private const SETTINGS_OPTION = 'cinebot_wp_settings';
	private const SALT_OPTION     = 'cinebot_wp_encryption_salt';
	private const USERNAME        = 'api-user';
	private const PASSWORD        = 'do-not-disclose-password';

	/** Remove API settings before each test. */
	protected function setUp(): void {
		parent::setUp();
		delete_option( self::SETTINGS_OPTION );
		delete_option( self::SALT_OPTION );
	}

	/** Remove API settings after each test. */
	protected function tearDown(): void {
		delete_option( self::SETTINGS_OPTION );
		delete_option( self::SALT_OPTION );
		parent::tearDown();
	}

	/**
	 * Verifies the frontend URL and exact safe transport arguments.
	 */
	public function test_fetch_uses_frontend_url_and_exact_transport_arguments(): void {
		$captured_url  = '';
		$captured_args = array();
		$client        = new ApiClient(
			$this->settings( 50, 'https://API.EXAMPLE.test/root/' ),
			static function ( string $url, array $args ) use ( &$captured_url, &$captured_args ): array {
				$captured_url  = $url;
				$captured_args = $args;

				return self::response( 200, '{"programmazione":[]}' );
			}
		);

		$client->fetchProgrammazione();

		self::assertSame( 'https://api.example.test/root/v1/programmazione/50', $captured_url );
		self::assertSame(
			array(
				'headers'            => array(
					'Authorization' => 'Basic ' . base64_encode( self::USERNAME . ':' . self::PASSWORD ),
					'Accept'        => 'application/json',
				),
				'timeout'            => 60,
				'redirection'        => 3,
				'reject_unsafe_urls' => true,
			),
			$captured_args
		);
	}

	/**
	 * Verifies no frontend segment or double slash is added when none is set.
	 */
	public function test_fetch_uses_unscoped_url_without_double_slashes(): void {
		$captured_url = '';
		$client       = new ApiClient(
			$this->settings( null, 'https://api.example.test/' ),
			static function ( string $url, array $args ) use ( &$captured_url ): array {
				$captured_url = $url;

				return self::response( 200, '{"programmazione":[]}' );
			}
		);

		$client->fetchProgrammazione();

		self::assertSame( 'https://api.example.test/v1/programmazione', $captured_url );
	}

	/**
	 * Provides missing credential combinations.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function missing_credentials(): array {
		return array(
			'missing username' => array( '', self::PASSWORD ),
			'missing password' => array( self::USERNAME, '' ),
		);
	}

	/**
	 * Verifies missing credentials fail before transport without leaking values.
	 *
	 * @dataProvider missing_credentials
	 */
	public function test_empty_credentials_fail_safely_before_transport( string $username, string $password ): void {
		$called   = false;
		$settings = new SettingsService();
		$settings->save(
			array(
				'api_username' => $username,
				'api_password' => $password,
			)
		);
		$client = new ApiClient(
			$settings,
			static function () use ( &$called ): array {
				$called = true;

				return self::response( 200, '{"programmazione":[]}' );
			}
		);

		$this->assert_safe_api_exception(
			static function () use ( $client ): void {
				$client->fetchProgrammazione();
			},
			array( $username, $password )
		);
		self::assertFalse( $called );
	}

	/**
	 * Verifies credential decryption failures become safe API failures before transport.
	 */
	public function test_tampered_password_throws_safe_api_exception_before_transport(): void {
		$called                 = false;
		$settings               = $this->settings();
		$stored                 = get_option( self::SETTINGS_OPTION );
		$encrypted              = $stored['api_password'];
		$payload                = base64_decode( $encrypted, true );
		$last                   = strlen( $payload ) - 1;
		$payload[ $last ]       = chr( ord( $payload[ $last ] ) ^ 1 );
		$stored['api_password'] = base64_encode( $payload );
		update_option( self::SETTINGS_OPTION, $stored );
		$client = new ApiClient(
			$settings,
			static function () use ( &$called ): array {
				$called = true;

				return self::response( 200, '{"programmazione":[]}' );
			}
		);

		try {
			$client->fetchProgrammazione();
			self::fail( 'Expected safe settings failure.' );
		} catch ( ApiException $exception ) {
			self::assertSame( 'Unable to prepare the Cinebot API request.', $exception->getMessage() );
			$this->assert_message_excludes( $exception, array( $encrypted, $stored['api_password'], self::PASSWORD ) );
		}
		self::assertFalse( $called );
	}

	/**
	 * Verifies network details and request URLs are not disclosed.
	 */
	public function test_wp_error_throws_safe_network_exception(): void {
		$url_fragment = 'private-host.example.test';
		$upstream     = 'Could not resolve ' . $url_fragment . '?token=secret';
		$client       = new ApiClient(
			$this->settings( null, 'https://' . $url_fragment ),
			static function () use ( $upstream ): WP_Error {
				return new WP_Error( 'http_request_failed', $upstream );
			}
		);

		$this->assert_safe_api_exception(
			static function () use ( $client ): void {
				$client->fetchProgrammazione();
			},
			array( $url_fragment, $upstream, self::PASSWORD )
		);
	}

	/**
	 * Verifies malformed transport results fail safely.
	 */
	public function test_invalid_transport_result_throws_safe_exception(): void {
		$client = $this->clientReturning( 'transport-result-secret' );

		$this->assert_safe_api_exception(
			static function () use ( $client ): void {
				$client->fetchProgrammazione();
			},
			array( 'transport-result-secret', self::PASSWORD )
		);
	}

	/**
	 * Verifies thrown transport details are normalized to a fixed safe failure.
	 */
	public function test_throwing_transport_throws_safe_api_exception(): void {
		$upstream = 'transport exception secret https://private.example.test?token=secret';
		$client   = new ApiClient(
			$this->settings(),
			static function () use ( $upstream ): void {
				throw new RuntimeException( $upstream );
			}
		);

		try {
			$client->fetchProgrammazione();
			self::fail( 'Expected safe transport failure.' );
		} catch ( ApiException $exception ) {
			self::assertSame( 'Unable to connect to the Cinebot API.', $exception->getMessage() );
			$this->assert_message_excludes( $exception, array( $upstream, self::PASSWORD ) );
		}
	}

	/**
	 * Verifies transport ApiExceptions retain their safe status semantics.
	 */
	public function test_transport_api_exception_is_preserved(): void {
		$expected = new ApiException( 'Safe transport status.', 429 );
		$client   = new ApiClient(
			$this->settings(),
			static function () use ( $expected ): void {
				throw $expected;
			}
		);

		try {
			$client->fetchProgrammazione();
			self::fail( 'Expected transport API exception.' );
		} catch ( ApiException $exception ) {
			self::assertSame( $expected, $exception );
			self::assertSame( 429, $exception->status() );
		}
	}

	/**
	 * Verifies authentication failures retain only the safe HTTP status.
	 */
	public function test_http_401_throws_safe_authentication_exception(): void {
		$body   = 'upstream authentication body secret';
		$client = $this->clientReturning( self::response( 401, $body ) );

		try {
			$client->fetchProgrammazione();
			self::fail( 'Expected API authentication failure.' );
		} catch ( ApiException $exception ) {
			self::assertSame( 401, $exception->status() );
			$this->assert_message_excludes( $exception, array( $body, self::PASSWORD ) );
		}
	}

	/**
	 * Verifies other response failures expose only their numeric status.
	 */
	public function test_non_200_http_status_throws_safe_status_exception(): void {
		$body   = '{"error":"database body secret"}';
		$client = $this->clientReturning( self::response( 500, $body ) );

		try {
			$client->fetchProgrammazione();
			self::fail( 'Expected API status failure.' );
		} catch ( ApiException $exception ) {
			self::assertSame( 500, $exception->status() );
			self::assertStringContainsString( '500', $exception->getMessage() );
			$this->assert_message_excludes( $exception, array( $body, 'database body secret', self::PASSWORD ) );
		}
	}

	/**
	 * Provides malformed or invalid top-level response bodies.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function invalid_json_bodies(): array {
		return array(
			'malformed JSON' => array( '{"programmazione":[' ),
			'JSON string'    => array( '"body scalar secret"' ),
			'JSON integer'   => array( '42' ),
			'JSON null'      => array( 'null' ),
		);
	}

	/**
	 * Verifies malformed and scalar JSON bodies fail without body disclosure.
	 *
	 * @dataProvider invalid_json_bodies
	 */
	public function test_invalid_json_body_throws_safe_exception( string $body ): void {
		$client = $this->clientReturning( self::response( 200, $body ) );

		$this->assert_safe_api_exception(
			static function () use ( $client ): void {
				$client->fetchProgrammazione();
			},
			array( $body, 'body scalar secret', self::PASSWORD )
		);
	}

	/**
	 * Verifies a valid response exactly at the body limit is accepted.
	 */
	public function test_body_exactly_at_limit_is_accepted(): void {
		$body   = $this->validBodyAtSize( 10 * 1024 * 1024 );
		$client = $this->clientReturning( self::response( 200, $body ) );

		self::assertSame( 10 * 1024 * 1024, strlen( $body ) );
		self::assertSame( array(), $client->fetchProgrammazione()['programmazione'] );
	}

	/**
	 * Verifies a valid response one byte above the body limit is rejected.
	 */
	public function test_body_one_byte_over_limit_throws_safe_exception(): void {
		$body   = $this->validBodyAtSize( ( 10 * 1024 * 1024 ) + 1 );
		$client = $this->clientReturning( self::response( 200, $body ) );

		self::assertSame( ( 10 * 1024 * 1024 ) + 1, strlen( $body ) );
		$this->assert_safe_api_exception(
			static function () use ( $client ): void {
				$client->fetchProgrammazione();
			},
			array( self::PASSWORD )
		);
	}

	/**
	 * Provides JSON object values that are invalid programming arrays.
	 *
	 * @return array<string,array{0:string}>
	 */
	public function object_programmazione_bodies(): array {
		return array(
			'empty object'     => array( '{"programmazione":{}}' ),
			'non-empty object' => array( '{"programmazione":{"frontend":50}}' ),
		);
	}

	/**
	 * Verifies JSON objects are rejected before associative conversion.
	 *
	 * @dataProvider object_programmazione_bodies
	 */
	public function test_object_programmazione_throws_safe_exception( string $body ): void {
		$client = $this->clientReturning( self::response( 200, $body ) );

		$this->assert_safe_api_exception(
			static function () use ( $client ): void {
				$client->fetchProgrammazione();
			},
			array( $body, self::PASSWORD )
		);
	}

	/**
	 * Provides rejected API payload envelopes.
	 *
	 * @return array<string,array{0:array<string,mixed>}>
	 */
	public function invalid_payloads(): array {
		return array(
			'non-200 integer status' => array( array( 'status' => 500, 'programmazione' => array() ) ),
			'string status'          => array( array( 'status' => '200', 'programmazione' => array() ) ),
			'non-empty error'        => array( array( 'error' => 'payload-error-secret', 'programmazione' => array() ) ),
			'array error'            => array( array( 'error' => array( 'payload-error-secret' ), 'programmazione' => array() ) ),
			'missing programming'    => array( array( 'status' => 200, 'error' => null ) ),
			'wrong programming type' => array( array( 'programmazione' => 'payload-programming-secret' ) ),
		);
	}

	/**
	 * Verifies payload envelope failures never expose upstream data.
	 *
	 * @param array<string,mixed> $payload Invalid response payload.
	 *
	 * @dataProvider invalid_payloads
	 */
	public function test_invalid_payload_envelope_throws_safe_exception( array $payload ): void {
		$body   = wp_json_encode( $payload );
		$client = $this->clientReturning( self::response( 200, $body ) );

		$this->assert_safe_api_exception(
			static function () use ( $client ): void {
				$client->fetchProgrammazione();
			},
			array( $body, 'payload-error-secret', 'payload-programming-secret', self::PASSWORD )
		);
	}

	/**
	 * Verifies empty programming data and empty optional error are valid.
	 */
	public function test_empty_programmazione_is_returned_unchanged(): void {
		$payload = array(
			'status'          => 200,
			'error'           => '',
			'programmazione' => array(),
			'meta'            => array( 'request_id' => 17 ),
		);
		$client  = $this->clientReturning( self::response( 200, wp_json_encode( $payload ) ) );

		self::assertSame( $payload, $client->fetchProgrammazione() );
	}

	/**
	 * Verifies a complete valid associative payload is returned unchanged.
	 */
	public function test_valid_payload_is_returned_unchanged(): void {
		$payload = array(
			'status'          => 200,
			'error'           => null,
			'programmazione' => array(
				array(
					'frontend' => 50,
					'titoli'   => array( array( 'idtitolo' => 491 ) ),
				),
			),
			'additional'      => true,
		);
		$client  = $this->clientReturning( self::response( 200, wp_json_encode( $payload ) ) );

		self::assertSame( $payload, $client->fetchProgrammazione() );
	}

	/**
	 * Creates persisted settings for a client test.
	 */
	private function settings( ?int $frontend = null, string $base_url = 'https://api.example.test' ): SettingsService {
		$settings = new SettingsService();
		$settings->save(
			array(
				'api_username' => self::USERNAME,
				'api_password' => self::PASSWORD,
				'api_frontend' => $frontend,
				'api_base_url' => $base_url,
			)
		);

		return $settings;
	}

	/**
	 * Creates a client whose injected transport returns one response.
	 *
	 * @param mixed $response Transport response.
	 */
	private function clientReturning( $response ): ApiClient {
		return new ApiClient(
			$this->settings(),
			static function () use ( $response ) {
				return $response;
			}
		);
	}

	/**
	 * Builds a minimal WordPress HTTP response.
	 *
	 * @return array<string,mixed>
	 */
	private static function response( int $status, string $body ): array {
		return array(
			'response' => array(
				'code'    => $status,
				'message' => 'upstream response message',
			),
			'body'     => $body,
		);
	}

	/**
	 * Builds valid JSON at an exact byte size.
	 */
	private function validBodyAtSize( int $size ): string {
		$prefix = '{"programmazione":[],"padding":"';
		$suffix = '"}';

		return $prefix . str_repeat( 'x', $size - strlen( $prefix ) - strlen( $suffix ) ) . $suffix;
	}

	/**
	 * Asserts a callback fails with an ApiException containing no sensitive values.
	 *
	 * @param callable            $operation        Operation expected to fail.
	 * @param array<int,string>   $sensitive_values Values forbidden from the message.
	 */
	private function assert_safe_api_exception( callable $operation, array $sensitive_values ): void {
		try {
			$operation();
			self::fail( 'Expected safe API failure.' );
		} catch ( ApiException $exception ) {
			$this->assert_message_excludes( $exception, $sensitive_values );
		}
	}

	/**
	 * Asserts an exception message excludes every non-empty sensitive value.
	 *
	 * @param array<int,string> $sensitive_values Values forbidden from the message.
	 */
	private function assert_message_excludes( ApiException $exception, array $sensitive_values ): void {
		self::assertNotSame( '', $exception->getMessage() );
		foreach ( $sensitive_values as $sensitive_value ) {
			if ( '' !== $sensitive_value ) {
				self::assertStringNotContainsString( $sensitive_value, $exception->getMessage() );
			}
		}
	}
}
