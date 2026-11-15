<?php
/**
 * Relay mode (includes/relay.php): the proof of a pending connection, finishing the connection,
 * the app's poke, and collecting the app's requests.
 *
 * @package Pirkoo
 */

/**
 * Relay mode.
 */
class Pirkoo_Relay_Test extends Pirkoo_Test_Case {

	const SECRET = 'test-secret-0123456789-0123456789-abcd';

	public function tear_down() {
		wp_clear_scheduled_hook( PIRKOO_RELAY_HOOK );
		delete_transient( PIRKOO_RELAY_LOCK );
		unset( $_GET['state'] );
		parent::tear_down();
	}

	/**
	 * A store connected in relay mode with SECRET.
	 */
	private function relay_store() {
		pirkoo_update_settings(
			array(
				'secret'       => self::SECRET,
				'connected_at' => time(),
				'relay'        => true,
			)
		);
	}

	/**
	 * A connection being made: its code's hash.
	 *
	 * @return string
	 */
	private function pending_code() {
		$hash = hash( 'sha256', 'the-code' );
		pirkoo_update_settings(
			array(
				'code_hash'    => $hash,
				'code_expires' => time() + 600,
			)
		);
		return $hash;
	}

	public function test_the_connect_link_says_where_to_finish_in_relay_mode() {
		$this->sign_in_shop_manager();
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'pirkoo_connect' );
		$location             = $this->redirect_of( 'pirkoo_handle_connect' );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertSame( admin_url( 'admin-post.php?action=pirkoo_relay' ), $query['return'] );
	}

	public function test_the_proof_needs_a_pending_code() {
		$nonce = str_repeat( 'a1', 16 );
		$this->assertNull( pirkoo_relay_proof( $nonce ), 'No connection is being made.' );

		$hash = $this->pending_code();
		$this->assertSame( hash_hmac( 'sha256', $nonce, $hash ), pirkoo_relay_proof( $nonce ) );
		$this->assertNull( pirkoo_relay_proof( 'not-a-nonce' ) );

		pirkoo_update_settings( array( 'code_expires' => time() - 1 ) );
		$this->assertNull( pirkoo_relay_proof( $nonce ), 'The code has expired.' );
	}

	public function test_finishing_posts_the_secret_and_starts_collecting() {
		$hash = $this->pending_code();
		$this->sign_in_shop_manager();
		$this->respond(
			'POST',
			'/api/stores/relay',
			200,
			array(
				'status' => 'ok',
				'store'  => 12,
			)
		);
		$_GET['state'] = $hash;

		$location = $this->redirect_of( 'pirkoo_handle_relay' );

		$this->assertSame( 'https://app.test/stores/12?done=connected', $location );
		$this->assertTrue( pirkoo_is_relay() );
		$sent = $this->requests[0]['body'];
		$this->assertSame( pirkoo_store_origin(), $sent['store'] );
		$this->assertSame( $hash, $sent['state'] );
		$this->assertSame( pirkoo_settings()['secret'], $sent['secret'] );
		$this->assertSame( '', pirkoo_settings()['code_hash'], 'The code works once.' );
		$this->assertNotFalse( wp_next_scheduled( PIRKOO_RELAY_HOOK ) );
	}

	public function test_finishing_needs_the_pending_codes_hash_and_the_right_user() {
		$hash = $this->pending_code();
		$this->sign_in_shop_manager();
		$_GET['state'] = str_repeat( '0', 64 );
		$this->assertStringContainsString( 'pirkoo=relay_expired', $this->redirect_of( 'pirkoo_handle_relay' ) );
		$this->assertFalse( pirkoo_is_connected() );
		$this->assertSame( array(), $this->requests );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_GET['state'] = $hash;
		$this->expectException( WPDieException::class );
		pirkoo_handle_relay();
	}

	public function test_a_refused_secret_leaves_the_store_unconnected() {
		$_GET['state'] = $this->pending_code();
		$this->sign_in_shop_manager();
		$this->respond( 'POST', '/api/stores/relay', 404, array( 'error' => 'no_offer' ) );

		$this->assertStringContainsString( 'pirkoo=relay_failed', $this->redirect_of( 'pirkoo_handle_relay' ) );
		$this->assertFalse( pirkoo_is_connected() );
		$this->assertFalse( wp_next_scheduled( PIRKOO_RELAY_HOOK ) );
	}

	public function test_only_the_app_can_poke() {
		$poke = function ( $secret, $time ) {
			return $time . '.' . pirkoo_signature( $secret, $time, 'GET', '/pirkoo-poke', '' );
		};
		$this->assertFalse( pirkoo_relay_poke_valid( $poke( self::SECRET, time() ) ), 'Not in relay mode.' );

		$this->relay_store();
		$this->assertTrue( pirkoo_relay_poke_valid( $poke( self::SECRET, time() ) ) );
		$this->assertFalse( pirkoo_relay_poke_valid( $poke( 'another-secret', time() ) ) );
		$this->assertFalse( pirkoo_relay_poke_valid( $poke( self::SECRET, time() - 10 * MINUTE_IN_SECONDS ) ) );
		$this->assertFalse( pirkoo_relay_poke_valid( 'nonsense' ) );
	}

	public function test_collecting_runs_the_apps_requests_and_posts_their_results() {
		$this->relay_store();
		$this->respond(
			'POST',
			'/api/stores/poll',
			200,
			array(
				'tasks' => array(
					array(
						'id'    => 'task-1',
						'route' => '/sync',
						'body'  => array(
							'kind'     => 'coupons',
							'since'    => 0,
							'page'     => 1,
							'per_page' => 10,
						),
					),
				),
			)
		);
		// The result's answer brings the next request; the last answer brings none.
		$this->respond(
			'POST',
			'/api/stores/result',
			200,
			array(
				'tasks' => array(
					array(
						'id'    => 'task-2',
						'route' => '/nonsense',
						'body'  => array(),
					),
				),
			)
		);
		$this->respond( 'POST', '/api/stores/result', 200, array( 'tasks' => array() ) );

		pirkoo_relay_collect();

		$this->assertSame( array( 'POST /api/stores/poll', 'POST /api/stores/result', 'POST /api/stores/result' ), $this->request_lines() );
		$poll = $this->requests[0];
		$this->assertSame( pirkoo_store_origin(), $poll['body']['store'] );
		$this->assertSame(
			pirkoo_signature( self::SECRET, (int) $poll['headers']['X-Pirkoo-Timestamp'], 'POST', '/api/stores/poll', (string) wp_json_encode( $poll['body'] ) ),
			$poll['headers']['X-Pirkoo-Signature']
		);
		$first = $this->requests[1]['body'];
		$this->assertSame( 'task-1', $first['id'] );
		$this->assertSame( 200, $first['status'] );
		$this->assertSame( 'coupons', $first['body']['kind'] );
		$second = $this->requests[2]['body'];
		$this->assertSame( 404, $second['status'] );
		$this->assertSame( 'rest_no_route', $second['body']['code'] );
		$this->assertNotSame( $poll['body']['nonce'], $first['nonce'], 'Each request has its own signature.' );
		$this->assertFalse( get_transient( PIRKOO_RELAY_LOCK ) );
	}

	public function test_a_routes_error_goes_back_as_the_rest_api_would_send_it() {
		$this->relay_store();
		$result = pirkoo_relay_run(
			array(
				'id'    => 'x',
				'route' => '/update',
				'body'  => array(
					'type'   => 'nonsense',
					'target' => 'x',
				),
			)
		);
		$this->assertNotSame( 200, $result['status'] );
		$this->assertArrayHasKey( 'code', $result['body'] );
		$this->assertStringStartsWith( 'pirkoo_', $result['body']['code'] );
	}

	public function test_a_store_the_app_no_longer_has_stops_collecting() {
		$this->relay_store();
		pirkoo_relay_schedule();
		$this->assertNotFalse( wp_next_scheduled( PIRKOO_RELAY_HOOK ) );
		$this->respond( 'POST', '/api/stores/poll', 404, array( 'error' => 'not_connected' ) );

		pirkoo_relay_collect();

		$this->assertFalse( pirkoo_is_connected() );
		$this->assertFalse( wp_next_scheduled( PIRKOO_RELAY_HOOK ) );
	}

	public function test_nothing_is_collected_outside_relay_mode_or_while_a_collection_runs() {
		$this->connect_store( self::SECRET );
		pirkoo_relay_collect();
		$this->relay_store();
		set_transient( PIRKOO_RELAY_LOCK, 1, 60 );
		pirkoo_relay_collect();
		$this->assertSame( array(), $this->requests );

		// The app down: nothing breaks, and the next collection can run.
		delete_transient( PIRKOO_RELAY_LOCK );
		$this->respond( 'POST', '/api/stores/poll', new WP_Error( 'http_request_failed', 'down' ) );
		pirkoo_relay_collect();
		$this->assertTrue( pirkoo_is_relay() );
		$this->assertFalse( get_transient( PIRKOO_RELAY_LOCK ) );
	}

	public function test_connecting_the_usual_way_leaves_relay_mode() {
		$this->relay_store();
		pirkoo_relay_schedule();
		pirkoo_update_settings(
			array(
				'code_hash'    => hash( 'sha256', 'new-code' ),
				'code_expires' => time() + 600,
			)
		);
		$response = $this->rest_post( '/pirkoo/v1/connect', '{"code":"new-code"}' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( pirkoo_is_relay() );
		$this->assertFalse( wp_next_scheduled( PIRKOO_RELAY_HOOK ) );
	}
}
