<?php
/**
 * Relay mode (0.4, owner decision of 15 Nov 2026): the connection for a store whose firewall blocks
 * app.pirkoo.com's requests to this plugin's REST routes.
 *
 * - When the app can't reach /pirkoo/v1/connect, it asks the store's home page to prove this plugin
 *   runs there (GET /?pirkoo_verify=<nonce>, answered with an HMAC keyed by the pending connection
 *   code's hash), then sends the administrator back here (admin-post.php?action=pirkoo_relay).
 * - The plugin makes the connection secret and posts it to the app, which keeps it.
 * - From then on the plugin collects the app's requests itself: POST /api/stores/poll, runs each
 *   as its REST route would, and POST /api/stores/result, all signed with the secret. It collects
 *   when the app pokes it (GET /?pirkoo_poke=<time>.<signature>, which only the app can sign) and
 *   every 5 minutes from WP-Cron.
 *
 * Nothing here runs for a store connected the usual way.
 *
 * @package Pirkoo
 */

defined( 'ABSPATH' ) || exit;

define( 'PIRKOO_RELAY_HOOK', 'pirkoo_relay_collect' );
define( 'PIRKOO_RELAY_LOCK', 'pirkoo_relay_running' );

/**
 * Whether the store is connected in relay mode.
 *
 * @return bool
 */
function pirkoo_is_relay() {
	$settings = pirkoo_settings();
	return $settings['relay'] && '' !== $settings['secret'];
}

/**
 * The 5-minute schedule of the collections.
 *
 * @param array<string, array{interval: int, display: string}> $schedules WP-Cron's schedules.
 * @return array<string, array{interval: int, display: string}>
 */
function pirkoo_relay_schedules( $schedules ) {
	$schedules['pirkoo_five_minutes'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => 'Every 5 minutes (Pirkoo)',
	);
	return $schedules;
}

/**
 * Keeps the collections scheduled while the store is in relay mode, and unscheduled otherwise.
 *
 * @return void
 */
function pirkoo_relay_schedule() {
	$scheduled = wp_next_scheduled( PIRKOO_RELAY_HOOK );
	if ( pirkoo_is_relay() && ! $scheduled ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'pirkoo_five_minutes', PIRKOO_RELAY_HOOK );
	} elseif ( ! pirkoo_is_relay() && $scheduled ) {
		wp_clear_scheduled_hook( PIRKOO_RELAY_HOOK );
	}
}

/**
 * On deactivation: no more collections (the connection itself stays, for when it's activated again).
 *
 * @return void
 */
function pirkoo_relay_deactivate() {
	wp_clear_scheduled_hook( PIRKOO_RELAY_HOOK );
}

/**
 * The proof that this plugin, holding a pending connection code, runs at this address: the HMAC of
 * the app's nonce keyed by the code's hash. Null when no connection is being made.
 *
 * @param string $nonce The app's nonce (32 hexadecimal characters).
 * @return string|null
 */
function pirkoo_relay_proof( $nonce ) {
	$settings = pirkoo_settings();
	if ( ! preg_match( '/^[0-9a-f]{32}$/', $nonce ) || '' === $settings['code_hash'] || $settings['code_expires'] < time() ) {
		return null;
	}
	return hash_hmac( 'sha256', $nonce, $settings['code_hash'] );
}

/**
 * Whether a poke is the app's: "<time>.<signature>", signed with the connection secret within 5
 * minutes of this server's clock.
 *
 * @param string $value The pirkoo_poke parameter.
 * @return bool
 */
function pirkoo_relay_poke_valid( $value ) {
	$parts = explode( '.', $value );
	if ( ! pirkoo_is_relay() || 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || abs( time() - (int) $parts[0] ) > PIRKOO_CLOCK_SKEW ) {
		return false;
	}
	return hash_equals( pirkoo_signature( pirkoo_settings()['secret'], (int) $parts[0], 'GET', '/pirkoo-poke', '' ), strtolower( $parts[1] ) );
}

/**
 * Answers the app's two requests to the store's home page: the proof of a pending connection, and
 * a poke to collect now. Any other request goes on untouched.
 *
 * @return void
 */
function pirkoo_relay_front() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Not form submissions: the app's requests, checked by HMAC.
	if ( isset( $_GET['pirkoo_verify'] ) && is_string( $_GET['pirkoo_verify'] ) ) {
		$proof = pirkoo_relay_proof( sanitize_text_field( wp_unslash( $_GET['pirkoo_verify'] ) ) );
		if ( null !== $proof ) {
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( $proof );
			exit;
		}
	} elseif ( isset( $_GET['pirkoo_poke'] ) && is_string( $_GET['pirkoo_poke'] ) && pirkoo_relay_poke_valid( sanitize_text_field( wp_unslash( $_GET['pirkoo_poke'] ) ) ) ) {
		// The app doesn't wait for the answer: the collection goes on after the response is sent.
		ignore_user_abort( true );
		nocache_headers();
		status_header( 204 );
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		pirkoo_relay_collect();
		exit;
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}

/**
 * Finishes a connection in relay mode (admin-post.php?action=pirkoo_relay&state=…), where the app
 * sends the administrator when it couldn't reach this plugin: makes the secret, posts it to the
 * app, and starts collecting.
 *
 * @return void
 */
function pirkoo_handle_relay() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to connect this store.', 'pirkoo' ), 403 );
	}
	// No WordPress nonce: the link comes from app.pirkoo.com. The state is the hash of the one-time
	// code this plugin made minutes ago when the administrator clicked Connect to Pirkoo.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- See above.
	$state    = isset( $_GET['state'] ) && is_string( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
	$settings = pirkoo_settings();
	if ( '' === $settings['code_hash'] || $settings['code_expires'] < time() || ! hash_equals( $settings['code_hash'], $state ) ) {
		wp_safe_redirect( add_query_arg( 'pirkoo', 'relay_expired', admin_url( 'plugins.php' ) ) );
		exit;
	}
	$secret = pirkoo_random_token();
	pirkoo_update_settings(
		array(
			'secret'       => $secret,
			'connected_at' => time(),
			'relay'        => true,
			'code_hash'    => '',
			'code_expires' => 0,
		)
	);
	$response = wp_remote_post(
		PIRKOO_APP_URL . '/api/stores/relay',
		array(
			'timeout' => 20,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => (string) wp_json_encode(
				array(
					'store'  => pirkoo_store_origin(),
					'state'  => $state,
					'secret' => $secret,
				)
			),
		)
	);
	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		pirkoo_forget_connection();
		wp_safe_redirect( add_query_arg( 'pirkoo', 'relay_failed', admin_url( 'plugins.php' ) ) );
		exit;
	}
	pirkoo_relay_schedule();
	$answer = json_decode( wp_remote_retrieve_body( $response ), true );
	$store  = is_array( $answer ) && isset( $answer['store'] ) ? (int) $answer['store'] : 0;
	wp_safe_redirect( PIRKOO_APP_URL . ( $store > 0 ? '/stores/' . $store . '?done=connected' : '/' ) );
	exit;
}

/**
 * Posts a signed request about this store to the app and returns its JSON answer; null when the
 * app can't be reached or refuses. An app that no longer has this store connected ends the
 * connection here too.
 *
 * @param string               $path The app's path, e.g. /api/stores/poll.
 * @param array<string, mixed> $data The request, without the store.
 * @return array<string, mixed>|null
 */
function pirkoo_relay_post( $path, array $data ) {
	$secret = pirkoo_settings()['secret'];
	if ( '' === $secret ) {
		return null;
	}
	// The nonce makes each request's signature its own: the app accepts a signature once.
	$data['store'] = pirkoo_store_origin();
	$data['nonce'] = pirkoo_random_token();
	$body          = (string) wp_json_encode( $data );
	$response      = wp_remote_post(
		PIRKOO_APP_URL . $path,
		array(
			'timeout' => 30,
			'headers' => array_merge( array( 'Content-Type' => 'application/json' ), pirkoo_signature_headers( $secret, 'POST', $path, $body ) ),
			'body'    => $body,
		)
	);
	if ( is_wp_error( $response ) ) {
		return null;
	}
	$answer = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 404 === wp_remote_retrieve_response_code( $response ) && is_array( $answer ) && isset( $answer['error'] ) && 'not_connected' === $answer['error'] ) {
		pirkoo_forget_connection(); // Disconnected or removed in the app.
		return null;
	}
	return 200 === wp_remote_retrieve_response_code( $response ) && is_array( $answer ) ? $answer : null;
}

/**
 * Runs one of the app's requests as its REST route would, and returns the answer's status and body.
 *
 * @param array<string, mixed> $task The task: id, route and body.
 * @return array{status: int, body: mixed}
 */
function pirkoo_relay_run( array $task ) {
	$routes = array(
		'/collect'    => 'pirkoo_rest_collect',
		'/sync'       => 'pirkoo_rest_sync',
		'/inventory'  => 'pirkoo_rest_inventory',
		'/update'     => 'pirkoo_rest_update',
		'/disconnect' => 'pirkoo_rest_disconnect',
	);
	$route  = isset( $task['route'] ) && is_string( $task['route'] ) ? $task['route'] : '';
	if ( ! isset( $routes[ $route ] ) ) {
		return array(
			'status' => 404,
			'body'   => array(
				'code'    => 'rest_no_route',
				'message' => 'Unknown request.',
			),
		);
	}
	$request = new WP_REST_Request( 'POST', '/pirkoo/v1' . $route );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body( (string) wp_json_encode( isset( $task['body'] ) && is_array( $task['body'] ) ? $task['body'] : new stdClass() ) );
	try {
		$result = call_user_func( $routes[ $route ], $request );
	} catch ( Throwable $e ) {
		return array(
			'status' => 500,
			'body'   => array(
				'code'    => 'pirkoo_task_failed',
				'message' => 'The request failed on the store.',
			),
		);
	}
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		return array(
			'status' => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 500,
			'body'   => array(
				'code'    => (string) $result->get_error_code(),
				'message' => $result->get_error_message(),
			),
		);
	}
	return array(
		'status' => 200,
		'body'   => $result,
	);
}

/**
 * Collects the app's requests and posts their results, one after the other, for at most 40
 * seconds; one collection at a time.
 *
 * @return void
 */
function pirkoo_relay_collect() {
	if ( ! pirkoo_is_relay() || false !== get_transient( PIRKOO_RELAY_LOCK ) ) {
		return;
	}
	set_transient( PIRKOO_RELAY_LOCK, 1, 2 * MINUTE_IN_SECONDS );
	try {
		$until  = time() + 40;
		$answer = pirkoo_relay_post( '/api/stores/poll', array() );
		while ( is_array( $answer ) && ! empty( $answer['tasks'][0] ) && is_array( $answer['tasks'][0] ) && isset( $answer['tasks'][0]['id'] ) ) {
			$task   = $answer['tasks'][0];
			$result = pirkoo_relay_run( $task );
			$answer = pirkoo_relay_post(
				'/api/stores/result',
				array(
					'id'     => (string) $task['id'],
					'status' => $result['status'],
					'body'   => $result['body'],
				)
			);
			if ( time() >= $until ) {
				break;
			}
		}
	} finally {
		delete_transient( PIRKOO_RELAY_LOCK );
	}
}
