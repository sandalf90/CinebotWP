<?php
/**
 * Authenticated Cinebot API client.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Services;

use WP_Error;

/**
 * Fetches and validates the Cinebot programming response.
 */
final class ApiClient {
	private const MAX_BODY_BYTES = 10485760;

	/** @var SettingsService */
	private $settings;

	/** @var callable */
	private $http_get;

	/**
	 * Creates an API client with an optional test transport.
	 */
	public function __construct( SettingsService $settings, ?callable $httpGet = null ) {
		$this->settings = $settings;
		$this->http_get = $httpGet ?? static function ( string $url, array $args ) {
			return wp_remote_get( $url, $args );
		};
	}

	/**
	 * Fetches one validated programming payload.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws ApiException When credentials, transport, or response data are invalid.
	 */
	public function fetchProgrammazione(): array {
		$username = $this->settings->username();
		$password = $this->settings->password();
		if ( '' === $username || '' === $password ) {
			throw new ApiException( 'API credentials are not configured.' );
		}

		$url      = rtrim( $this->settings->baseUrl(), '/' ) . '/v1/programmazione';
		$frontend = $this->settings->frontend();
		if ( null !== $frontend ) {
			$url .= '/' . $frontend;
		}

		$response = call_user_func(
			$this->http_get,
			$url,
			array(
				'headers'            => array(
					'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
					'Accept'        => 'application/json',
				),
				'timeout'            => 60,
				'redirection'        => 3,
				'reject_unsafe_urls' => true,
			)
		);

		if ( $response instanceof WP_Error ) {
			throw new ApiException( 'Unable to connect to the Cinebot API.' );
		}
		if ( ! is_array( $response ) ) {
			throw new ApiException( 'Cinebot API response is invalid.' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $status ) {
			throw new ApiException( 'Cinebot API authentication failed.', 401 );
		}
		if ( 200 !== $status ) {
			throw new ApiException( 'Cinebot API returned HTTP status ' . $status . '.', $status );
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || strlen( $body ) > self::MAX_BODY_BYTES ) {
			throw new ApiException( 'Cinebot API response is invalid.' );
		}

		$object = json_decode( $body );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $object ) ) {
			throw new ApiException( 'Cinebot API response is invalid.' );
		}

		$payload = json_decode( $body, true );
		if ( ! is_array( $payload ) ) {
			throw new ApiException( 'Cinebot API response is invalid.' );
		}
		if ( array_key_exists( 'status', $payload ) && 200 !== $payload['status'] ) {
			throw new ApiException( 'Cinebot API response status is invalid.' );
		}
		if (
			array_key_exists( 'error', $payload )
			&& null !== $payload['error']
			&& '' !== $payload['error']
		) {
			throw new ApiException( 'Cinebot API reported an error.' );
		}
		if ( ! isset( $payload['programmazione'] ) || ! is_array( $payload['programmazione'] ) ) {
			throw new ApiException( 'Cinebot API programming data is invalid.' );
		}

		return $payload;
	}
}
