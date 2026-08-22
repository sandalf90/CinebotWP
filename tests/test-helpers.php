<?php
/**
 * Test helpers: namespace-local wp_send_json overrides.
 *
 * In WordPress 6.0, wp_send_json() calls die; which kills the PHP process
 * during tests. PHP function resolution checks the current namespace first,
 * so defining wp_send_json in the same namespace as the calling code
 * intercepts the call before the global function is reached.
 *
 * This file must be loaded AFTER the WordPress test framework bootstrap.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Frontend;

/**
 * Intercept wp_send_json in the Frontend namespace to prevent die during tests.
 *
 * @param mixed $response    Response data.
 * @param int   $status_code Optional HTTP status code.
 */
function wp_send_json( $response, $status_code = null ) {
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
	}
	if ( null !== $status_code ) {
		status_header( $status_code );
	}
	echo wp_json_encode( $response );
}

/**
 * Intercept wp_send_json_success in the Frontend namespace.
 *
 * @param mixed $data        Optional response data.
 * @param int   $status_code Optional HTTP status code.
 */
function wp_send_json_success( $data = null, $status_code = null ) {
	$response = array( 'success' => true );
	if ( isset( $data ) ) {
		$response['data'] = $data;
	}
	wp_send_json( $response, $status_code );
}

/**
 * Intercept wp_send_json_error in the Frontend namespace.
 *
 * @param mixed $data        Optional error data.
 * @param int   $status_code Optional HTTP status code.
 */
function wp_send_json_error( $data = null, $status_code = null ) {
	$response = array( 'success' => false );
	if ( isset( $data ) ) {
		$response['data'] = $data;
	}
	wp_send_json( $response, $status_code );
}

// phpcs:ignore Universal.Namespaces.OneDeclarationPerFile.MultipleFound -- intentional: same helpers needed in Admin\Pages namespace.
namespace CinebotWp\Admin\Pages;

/**
 * Intercept wp_send_json in the Admin\Pages namespace to prevent die during tests.
 *
 * @param mixed $response    Response data.
 * @param int   $status_code Optional HTTP status code.
 */
function wp_send_json( $response, $status_code = null ) {
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
	}
	if ( null !== $status_code ) {
		status_header( $status_code );
	}
	echo wp_json_encode( $response );
}

/**
 * Intercept wp_send_json_success in the Admin\Pages namespace.
 *
 * @param mixed $data        Optional response data.
 * @param int   $status_code Optional HTTP status code.
 */
function wp_send_json_success( $data = null, $status_code = null ) {
	$response = array( 'success' => true );
	if ( isset( $data ) ) {
		$response['data'] = $data;
	}
	wp_send_json( $response, $status_code );
}

/**
 * Intercept wp_send_json_error in the Admin\Pages namespace.
 *
 * @param mixed $data        Optional error data.
 * @param int   $status_code Optional HTTP status code.
 */
function wp_send_json_error( $data = null, $status_code = null ) {
	$response = array( 'success' => false );
	if ( isset( $data ) ) {
		$response['data'] = $data;
	}
	wp_send_json( $response, $status_code );
}
