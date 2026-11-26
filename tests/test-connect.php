<?php
/**
 * The connection with app.pirkoo.com (includes/connect.php): the Plugins screen links, the
 * one-time code, the secret, signed requests, collecting and disconnecting.
 *
 * @package Pirkoo
 */

/**
 * Connector.
 */
class Pirkoo_Connect_Test extends Pirkoo_Test_Case {

	const SECRET = 'test-secret-0123456789-0123456789-abcd';

	/**
	 * The plugin's links on the Plugins screen.
	 *
	 * @return string
	 */
	private function links() {
		return implode( ' ', pirkoo_plugin_action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) ) );
	}

	public function test_plugins_screen_links() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertStringNotContainsString( 'Pirkoo', $this->links() );

		$this->sign_in_shop_manager();
		$links = $this->links();
		$this->assertStringContainsString( 'Connect to Pirkoo', $links );
		$this->assertStringContainsString( 'action=pirkoo_connect', $links );
		$this->assertStringContainsString( '_wpnonce=', $links );
		$this->assertStringNotContainsString( 'Disconnect', $links );

		$this->connect_store();
		$links = $this->links();
		$this->assertStringContainsString( 'Disconnect from Pirkoo', $links );
		$this->assertStringContainsString( 'Open Pirkoo', $links );
		$this->assertStringContainsString( 'https://app.test', $links );
		$this->assertStringNotContainsString( 'Connect to Pirkoo', $links );
	}

	/**
	 * Starts a connection as a shop manager and returns the code sent to the app.
	 *
	 * @return string
	 */
	private function start_connection() {
		$this->sign_in_shop_manager();
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'pirkoo_connect' );
		$location             = $this->redirect_of( 'pirkoo_handle_connect' );
		$this->assertStringStartsWith( 'https://app.test/connect?', $location );
		parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
		$this->assertSame( pirkoo_store_origin(), $query['store'] );
		$this->assertSame( untrailingslashit( rest_url( 'pirkoo/v1' ) ), $query['api'], 'The plugin\'s REST address goes with the link (0.3.1).' );
		return (string) $query['code'];
	}

	public function test_connecting_sends_nothing_and_exchanges_the_code_once() {
		$code = $this->start_connection();
		$this->assertSame( array(), $this->requests, 'Starting a connection contacts nobody.' );
		$this->assertFalse( pirkoo_is_connected() );
		$this->assertSame( hash( 'sha256', $code ), pirkoo_settings()['code_hash'] );

		$wrong = $this->rest_post( '/pirkoo/v1/connect', (string) wp_json_encode( array( 'code' => 'nope' ) ) );
		$this->assertSame( 403, $wrong->get_status() );

		$response = $this->rest_post( '/pirkoo/v1/connect', (string) wp_json_encode( array( 'code' => $code ) ) );
		$this->assertSame( 200, $response->get_status() );
		$secret = $response->get_data()['secret'];
		$this->assertGreaterThanOrEqual( 43, strlen( $secret ) );
		$this->assertTrue( pirkoo_is_connected() );
		$this->assertSame( $secret, pirkoo_settings()['secret'] );
		$this->assertSame( '', pirkoo_settings()['code_hash'] );

		$again = $this->rest_post( '/pirkoo/v1/connect', (string) wp_json_encode( array( 'code' => $code ) ) );
		$this->assertSame( 403, $again->get_status(), 'A code works once.' );
		$this->assertSame( $secret, pirkoo_settings()['secret'], 'A refused code changes nothing.' );
	}

	public function test_an_expired_code_is_refused() {
		$code = $this->start_connection();
		pirkoo_update_settings( array( 'code_expires' => time() - 1 ) );
		$this->assertSame( 403, $this->rest_post( '/pirkoo/v1/connect', (string) wp_json_encode( array( 'code' => $code ) ) )->get_status() );
		$this->assertFalse( pirkoo_is_connected() );
	}

	public function test_connecting_needs_the_right_user_and_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'pirkoo_connect' );
		$this->expectException( WPDieException::class );
		pirkoo_handle_connect();
	}

	/**
	 * The signature format both sides implement: the app's storelink tests use this same vector.
	 */
	public function test_signature_vector() {
		$this->assertSame(
			'2dc3e483af7eede02f1d571b3cbb54cdc12a9fea39ad176412af26af5da7d0f2',
			pirkoo_signature( 'key', 1794484800, 'POST', '/wp-json/pirkoo/v1/collect', '{}' )
		);
	}

	public function test_signed_routes_refuse_what_isnt_signed_right() {
		$this->assertSame( 401, $this->rest_post( '/pirkoo/v1/collect', '{}', self::SECRET )->get_status(), 'Not connected.' );
		$this->connect_store( self::SECRET );
		foreach ( array(
			'unsigned'        => array( null, null ),
			'other secret'    => array( 'other', null ),
			'too old'         => array( self::SECRET, time() - 6 * MINUTE_IN_SECONDS ),
			'from the future' => array( self::SECRET, time() + 6 * MINUTE_IN_SECONDS ),
		) as $name => $case ) {
			$response = $this->rest_post( '/pirkoo/v1/collect', '{}', $case[0], $case[1] );
			$this->assertSame( 401, $response->get_status(), $name );
		}
		$this->assertSame( 'pirkoo_clock_skew', $this->rest_post( '/pirkoo/v1/disconnect', '{}', self::SECRET, time() - HOUR_IN_SECONDS )->as_error()->get_error_code() );
		$this->assertTrue( pirkoo_is_connected(), 'Refused requests change nothing.' );
	}

	public function test_collect_answers_once_per_signature_and_one_at_a_time() {
		$this->connect_store( self::SECRET );
		$time     = time();
		$response = $this->rest_post( '/pirkoo/v1/collect', '{}', self::SECRET, $time );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( array( 'website', 'email', 'pages', 'metrics', 'detailed', 'store' ), array_keys( $data ) );
		$slugs = wp_list_pluck( $data['store']['plugins'], 'slug' );
		$this->assertContains( 'woocommerce', $slugs, 'Installed plugins are listed by slug (0.4).' );
		foreach ( $data['store']['plugins'] as $plugin ) {
			$this->assertSame( array( 'slug', 'active' ), array_keys( $plugin ) );
		}
		$this->assertSame( home_url( '/' ), $data['website'] );
		$this->assertSame( get_option( 'admin_email' ), $data['email'] );
		$this->assertSame( 3, $data['metrics']['schema'] );
		$this->assertSame( array( 'name', 'timezone', 'admin_url', 'orders_hpos', 'date_format', 'time_format', 'locale', 'payment_methods', 'shipping_methods', 'plugins' ), array_keys( $data['store'] ) );
		$this->assertSame( admin_url(), $data['store']['admin_url'] );
		$this->assertFalse( get_option( PIRKOO_LOCK ), 'The lock is released.' );

		$this->assertSame( 401, $this->rest_post( '/pirkoo/v1/collect', '{}', self::SECRET, $time )->get_status(), 'A replayed request is refused.' );

		pirkoo_lock();
		$this->assertSame( 409, $this->rest_post( '/pirkoo/v1/collect', '{}', self::SECRET, $time + 1 )->get_status() );
		pirkoo_unlock();
		$this->assertSame( array(), $this->requests, 'Collecting sends nothing by itself: the app takes the answer.' );
	}

	public function test_the_app_disconnects_the_store() {
		$this->connect_store( self::SECRET );
		$this->assertSame( 200, $this->rest_post( '/pirkoo/v1/disconnect', '{}', self::SECRET )->get_status() );
		$this->assertFalse( pirkoo_is_connected() );
		$this->assertSame( 401, $this->rest_post( '/pirkoo/v1/collect', '{}', self::SECRET, time() + 2 )->get_status() );
	}

	public function test_disconnecting_from_the_plugins_screen_tells_the_app() {
		$this->connect_store( self::SECRET );
		$this->sign_in_shop_manager();
		$this->respond( 'POST', '/api/stores/disconnect', 200, array( 'status' => 'ok' ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'pirkoo_disconnect' );

		$location = $this->redirect_of( 'pirkoo_handle_disconnect' );

		$this->assertStringContainsString( 'plugins.php?pirkoo=disconnected', $location );
		$this->assertFalse( pirkoo_is_connected() );
		$this->assertSame( array( 'POST /api/stores/disconnect' ), $this->request_lines() );
		$request = $this->requests[0];
		$this->assertSame( array( 'store' => pirkoo_store_origin() ), $request['body'] );
		$body = (string) wp_json_encode( $request['body'] );
		$this->assertSame(
			pirkoo_signature( self::SECRET, (int) $request['headers']['X-Pirkoo-Timestamp'], 'POST', '/api/stores/disconnect', $body ),
			$request['headers']['X-Pirkoo-Signature']
		);
	}

	public function test_disconnecting_works_when_the_app_is_down() {
		$this->connect_store( self::SECRET );
		$this->sign_in_shop_manager();
		$this->respond( 'POST', '/api/stores/disconnect', new WP_Error( 'http_request_failed', 'down' ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'pirkoo_disconnect' );
		$this->redirect_of( 'pirkoo_handle_disconnect' );
		$this->assertFalse( pirkoo_is_connected() );
	}

	public function test_an_update_from_0_2_drops_the_gateway_key() {
		update_option(
			PIRKOO_OPTION,
			array(
				'api_key'      => 'tvk_old',
				'connected_at' => time() - DAY_IN_SECONDS,
				'last'         => array( 'code' => 'queued' ),
			)
		);
		set_transient( 'pirkoo_verify_token', 'tok', MINUTE_IN_SECONDS );
		pirkoo_upgrade();
		$saved = get_option( PIRKOO_OPTION );
		$this->assertArrayNotHasKey( 'api_key', $saved );
		$this->assertArrayNotHasKey( 'last', $saved );
		$this->assertFalse( pirkoo_is_connected() );
		$this->assertSame( 0, pirkoo_settings()['connected_at'] );
		$this->assertFalse( get_transient( 'pirkoo_verify_token' ) );
	}

	public function test_collect_sends_the_stores_method_titles_only() {
		update_option(
			'woocommerce_cod_settings',
			array(
				'enabled'      => 'yes',
				'title'        => 'Pagamento <b>na</b> entrega',
				'instructions' => 'secret instructions',
			)
		);
		WC()->payment_gateways()->init();
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Brasil' );
		$zone->save();
		foreach ( array( 'PAC', 'SEDEX', 'PAC' ) as $title ) {
			$instance = $zone->add_shipping_method( 'flat_rate' );
			update_option(
				'woocommerce_flat_rate_' . $instance . '_settings',
				array(
					'title' => $title,
					'cost'  => '10',
				)
			);
		}
		$this->connect_store( self::SECRET );

		$store = $this->rest_post( '/pirkoo/v1/collect', '{}', self::SECRET )->get_data()['store'];

		$this->assertContains(
			array(
				'id'    => 'cod',
				'title' => 'Pagamento na entrega',
			),
			$store['payment_methods']
		);
		$this->assertSame(
			array(
				array(
					'id'    => 'flat_rate',
					'title' => 'PAC',
				),
				array(
					'id'    => 'flat_rate',
					'title' => 'SEDEX',
				),
			),
			$store['shipping_methods']
		);
		$this->assertStringNotContainsString( 'secret instructions', (string) wp_json_encode( $store ) );
	}

	public function test_the_signed_route_is_the_same_whatever_the_rest_prefix() {
		$this->connect_store( self::SECRET );
		$prefix = function () {
			return 'api';
		};
		add_filter( 'rest_url_prefix', $prefix );
		$response = $this->rest_post( '/pirkoo/v1/collect', '{}', self::SECRET );
		remove_filter( 'rest_url_prefix', $prefix );
		$this->assertSame( 200, $response->get_status() );
	}
	public function test_own_routes_pass_a_site_that_blocks_the_rest_api_for_visitors() {
		global $wp;
		$blocked = new WP_Error( 'rest_not_logged_in', 'Blocked.', array( 'status' => 401 ) );
		$before  = $wp->query_vars;

		$wp->query_vars['rest_route'] = '/pirkoo/v1/collect';
		$this->assertNull( pirkoo_rest_allow_own_routes( $blocked ) );
		$this->assertTrue( pirkoo_rest_allow_own_routes( true ) );

		// Other routes stay blocked, lookalikes included.
		foreach ( array( '/wp/v2/users', '/pirkoo/v10/x', '/x/pirkoo/v1/collect' ) as $route ) {
			$wp->query_vars['rest_route'] = $route;
			$this->assertSame( $blocked, pirkoo_rest_allow_own_routes( $blocked ) );
		}
		$wp->query_vars = $before;
		$this->assertSame( PHP_INT_MAX, has_filter( 'rest_authentication_errors', 'pirkoo_rest_allow_own_routes' ) );
	}

	public function test_the_plugin_is_in_brazilian_portuguese() {
		$this->assertSame( 10, has_action( 'init', 'pirkoo_load_textdomain' ) );
		// The test site has only English installed, so the language is set directly.
		$portuguese = function () {
			return 'pt_BR';
		};
		add_filter( 'pre_determine_locale', $portuguese );
		unload_textdomain( 'pirkoo', true );
		pirkoo_load_textdomain();
		$connect = __( 'Connect to Pirkoo', 'pirkoo' );
		$notice  = __( 'This store is disconnected from Pirkoo. Its history stays in the app; connect it again from here at any time.', 'pirkoo' );
		remove_filter( 'pre_determine_locale', $portuguese );
		unload_textdomain( 'pirkoo', true );

		$this->assertSame( 'Conectar ao Pirkoo', $connect );
		$this->assertStringContainsString( 'desconectada do Pirkoo', $notice );
		$this->assertSame( 'Connect to Pirkoo', __( 'Connect to Pirkoo', 'pirkoo' ) );
	}

	/**
	 * The Connect to Pirkoo page's output.
	 *
	 * @return string
	 */
	private function connect_page() {
		ob_start();
		pirkoo_render_connect_page();
		return (string) ob_get_clean();
	}

	/**
	 * The page the app opens (0.9.0): one button that posts with a nonce and the app's setup token;
	 * opening it makes no code and contacts nobody.
	 */
	public function test_the_app_started_connect_page() {
		$this->assertSame( 10, has_action( 'admin_menu', 'pirkoo_connect_menu' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( 'blogname', 'The <b>Shop</b>' );
		$_GET['setup'] = 'setup-token_0123456789abcdef';
		$page          = $this->connect_page();
		$this->assertStringContainsString( '<strong>The Shop</strong>', $page );
		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $page );
		$this->assertStringContainsString( 'method="post"', $page );
		$this->assertStringContainsString( 'name="action" value="pirkoo_connect"', $page );
		$this->assertStringContainsString( 'name="_wpnonce"', $page );
		$this->assertStringContainsString( 'name="setup" value="setup-token_0123456789abcdef"', $page );
		$this->assertStringContainsString( 'Connect to Pirkoo', $page );
		$this->assertSame( '', pirkoo_settings()['code_hash'], 'Opening the page makes no code.' );
		$this->assertSame( array(), $this->requests, 'Opening the page contacts nobody.' );
		$this->assertFalse( pirkoo_is_connected() );

		// A token that isn't one is left out.
		$_GET['setup'] = '"><script>alert(1)</script>';
		$page          = $this->connect_page();
		$this->assertStringNotContainsString( 'name="setup"', $page );
		$this->assertStringNotContainsString( '<script>', $page );
		unset( $_GET['setup'] );

		$this->connect_store();
		$page = $this->connect_page();
		$this->assertStringContainsString( 'already connected', $page );
		$this->assertStringNotContainsString( '<form', $page );
	}

	public function test_the_connect_page_needs_a_user_who_can_activate_plugins() {
		$this->sign_in_shop_manager();
		$this->expectException( WPDieException::class );
		$this->connect_page();
	}

	/**
	 * The button's post sends the setup token to the app unchanged, next to the usual code; the
	 * Plugins screen's link sends none.
	 */
	public function test_the_setup_token_goes_back_to_the_app() {
		$this->sign_in_shop_manager();
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'pirkoo_connect' );
		$_POST['setup']       = 'setup-token_0123456789abcdef';
		parse_str( (string) wp_parse_url( $this->redirect_of( 'pirkoo_handle_connect' ), PHP_URL_QUERY ), $query );
		$this->assertSame( 'setup-token_0123456789abcdef', $query['setup'] );
		$this->assertSame( hash( 'sha256', (string) $query['code'] ), pirkoo_settings()['code_hash'] );

		$_POST['setup'] = 'bad token';
		parse_str( (string) wp_parse_url( $this->redirect_of( 'pirkoo_handle_connect' ), PHP_URL_QUERY ), $query );
		$this->assertArrayNotHasKey( 'setup', $query );
		unset( $_POST['setup'] );
		parse_str( (string) wp_parse_url( $this->redirect_of( 'pirkoo_handle_connect' ), PHP_URL_QUERY ), $query );
		$this->assertArrayNotHasKey( 'setup', $query );
	}
}
