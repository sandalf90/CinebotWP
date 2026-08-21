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

function wp_send_json( $response, $status_code = null ) {
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
	}
	if ( null !== $status_code ) {
		status_header( $status_code );
	}
	echo wp_json_encode( $response );
}

function wp_send_json_success( $data = null, $status_code = null ) {
	$response = array( 'success' => true );
	if ( isset( $data ) ) {
		$response['data'] = $data;
	}
	wp_send_json( $response, $status_code );
}

function wp_send_json_error( $data = null, $status_code = null ) {
	$response = array( 'success' => false );
	if ( isset( $data ) ) {
		$response['data'] = $data;
	}
	wp_send_json( $response, $status_code );
}

namespace CinebotWp\Admin\Pages;

function wp_send_json( $response, $status_code = null ) {
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
	}
	if ( null !== $status_code ) {
		status_header( $status_code );
	}
	echo wp_json_encode( $response );
}

function wp_send_json_success( $data = null, $status_code = null ) {
	$response = array( 'success' => true );
	if ( isset( $data ) ) {
		$response['data'] = $data;
	}
	wp_send_json( $response, $status_code );
}

function wp_send_json_error( $data = null, $status_code = null ) {
	$response = array( 'success' => false );
	if ( isset( $data ) ) {
		$response['data'] = $data;
	}
	wp_send_json( $response, $status_code );
}
