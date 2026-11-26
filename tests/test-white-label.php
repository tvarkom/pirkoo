<?php
/**
 * White label (includes/white-label.php): the app's signed request, the Plugins screen and
 * Dashboard → Updates, and removing it on disconnect.
 *
 * @package Pirkoo
 */

/**
 * White label.
 */
class Pirkoo_White_Label_Test extends Pirkoo_Test_Case {

	const SECRET = 'white-label-secret-0123456789-0123456789';

	/**
	 * The Plugins screen's list with the plugin's own headers, as get_plugins() gives it.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function plugins() {
		return array(
			plugin_basename( PIRKOO_FILE ) => array(
				'Name'        => 'Pirkoo',
				'Title'       => 'Pirkoo',
				'Description' => 'Manage, monitor and report on all your WooCommerce stores from one dashboard, with your team.',
				'Author'      => 'tvarkom',
				'AuthorName'  => 'tvarkom',
				'AuthorURI'   => 'https://tvarkom.com',
				'PluginURI'   => 'https://pirkoo.com',
			),
			'other/other.php'              => array( 'Name' => 'Other' ),
		);
	}

	/**
	 * Sends the app's white label request.
	 *
	 * @param mixed $label The white label (null: none).
	 * @return WP_REST_Response
	 */
	private function send( $label ) {
		return $this->rest_post( '/pirkoo/v1/white-label', (string) wp_json_encode( array( 'white_label' => $label ) ), self::SECRET );
	}

	public function test_without_a_white_label_the_plugin_shows_as_itself() {
		$this->assertSame( $this->plugins(), pirkoo_white_label_plugins( $this->plugins() ) );
		$this->assertSame( array( 'a', 'plugin-information link' ), pirkoo_white_label_row_meta( array( 'a', 'plugin-information link' ), plugin_basename( PIRKOO_FILE ) ) );
	}

	public function test_only_a_signed_request_changes_it() {
		$this->connect_store( self::SECRET );
		$response = $this->rest_post( '/pirkoo/v1/white-label', '{"white_label":{"name":"Agency Care"}}' );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( array(), pirkoo_white_label() );

		$response = $this->send( array( 'name' => 'Agency Care' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'Agency Care', pirkoo_white_label()['name'] );
	}

	public function test_the_plugins_screen_shows_the_white_label() {
		$this->connect_store( self::SECRET );
		$this->send(
			array(
				'name'        => 'Agency Care',
				'description' => 'Maintenance by Agency.',
				'author'      => 'Agency',
				'author_url'  => 'https://agency.example',
			)
		);
		$file   = plugin_basename( PIRKOO_FILE );
		$plugin = pirkoo_white_label_plugins( $this->plugins() )[ $file ];
		$this->assertSame( 'Agency Care', $plugin['Name'] );
		$this->assertSame( 'Agency Care', $plugin['Title'] );
		$this->assertSame( 'Maintenance by Agency.', $plugin['Description'] );
		$this->assertSame( 'Agency', $plugin['Author'] );
		$this->assertSame( 'https://agency.example', $plugin['AuthorURI'] );
		$this->assertSame( '', $plugin['PluginURI'] );

		$meta = pirkoo_white_label_row_meta( array( 'Version 0.7.0', '<a href="plugin-install.php?tab=plugin-information&plugin=pirkoo">View details</a>' ), $file );
		$this->assertSame( array( 'Version 0.7.0' ), $meta );
		$this->assertCount( 2, pirkoo_white_label_row_meta( array( 'a', 'plugin-information' ), 'other/other.php' ), 'Other plugins keep their links.' );

		$this->sign_in_shop_manager();
		$links = implode( ' ', pirkoo_plugin_action_links( array() ) );
		$this->assertStringNotContainsString( 'Pirkoo', $links );
		$this->assertStringNotContainsString( 'https://app.test', $links );
		$this->assertStringContainsString( 'action=pirkoo_disconnect', $links );
	}

	public function test_blank_fields_keep_the_plugins_own() {
		$this->connect_store( self::SECRET );
		$this->send( array( 'author' => 'Agency' ) );
		$plugin = pirkoo_white_label_plugins( $this->plugins() )[ plugin_basename( PIRKOO_FILE ) ];
		$this->assertSame( 'Pirkoo', $plugin['Name'] );
		$this->assertSame( 'Agency', $plugin['Author'] );
		$this->assertSame( '', $plugin['AuthorURI'], 'tvarkom\'s address doesn\'t go with another author.' );
	}

	public function test_hiding_removes_the_plugin_from_the_list() {
		$this->connect_store( self::SECRET );
		$this->send( array( 'hide' => true ) );
		$plugins = pirkoo_white_label_plugins( $this->plugins() );
		$this->assertArrayNotHasKey( plugin_basename( PIRKOO_FILE ), $plugins );
		$this->assertArrayHasKey( 'other/other.php', $plugins );
	}

	public function test_what_the_app_sends_is_cleaned() {
		$this->connect_store( self::SECRET );
		$this->send(
			array(
				'name'       => '<script>alert(1)</script>Agency <b>Care</b>',
				'author_url' => 'javascript:alert(1)',
				'logo'       => 'http://agency.example/logo.png',
				'extra'      => 'ignored',
			)
		);
		$label = pirkoo_white_label();
		$this->assertSame( 'Agency Care', $label['name'] );
		$this->assertSame( '', $label['author_url'] );
		$this->assertSame( '', $label['logo'], 'The logo must be https.' );
		$this->assertArrayNotHasKey( 'extra', $label );
		$this->send( array( 'name' => str_repeat( 'a', 150 ) ) );
		$this->assertSame( 100, strlen( pirkoo_white_label()['name'] ) );
	}

	public function test_an_empty_white_label_removes_it() {
		$this->connect_store( self::SECRET );
		$this->send( array( 'name' => 'Agency Care' ) );
		$this->send( array( 'name' => '' ) );
		$this->assertFalse( get_option( PIRKOO_WHITE_LABEL_OPTION ) );
		$this->send( array( 'name' => 'Agency Care' ) );
		$this->send( null );
		$this->assertFalse( get_option( PIRKOO_WHITE_LABEL_OPTION ) );
	}

	public function test_a_collection_carries_it_and_one_without_it_changes_nothing() {
		$this->connect_store( self::SECRET );
		$this->rest_post( '/pirkoo/v1/collect', '{"white_label":{"name":"Agency Care"}}', self::SECRET );
		$this->assertSame( 'Agency Care', pirkoo_white_label()['name'] );
		$this->rest_post( '/pirkoo/v1/collect', '{}', self::SECRET );
		$this->assertSame( 'Agency Care', pirkoo_white_label()['name'] );
		$this->rest_post( '/pirkoo/v1/collect', '{"white_label":null}', self::SECRET );
		$this->assertSame( array(), pirkoo_white_label() );
	}

	public function test_disconnecting_removes_it() {
		$this->connect_store( self::SECRET );
		$this->send( array( 'hide' => true ) );
		$this->rest_post( '/pirkoo/v1/disconnect', '{}', self::SECRET );
		$this->assertFalse( get_option( PIRKOO_WHITE_LABEL_OPTION ) );
	}

	public function test_the_app_link_follows_the_companys_domain() {
		$this->connect_store( self::SECRET );
		$this->sign_in_shop_manager();

		// Only the domain, no white label: the plugin shows as itself and Open goes to the domain.
		$this->send( array( 'app_url' => 'https://app.agency.example' ) );
		$this->assertSame( 'https://app.agency.example', pirkoo_app_link() );
		$this->assertSame( $this->plugins(), pirkoo_white_label_plugins( $this->plugins() ) );
		$this->assertSame( array( 'a', 'plugin-information' ), pirkoo_white_label_row_meta( array( 'a', 'plugin-information' ), plugin_basename( PIRKOO_FILE ) ) );
		$links = implode( ' ', pirkoo_plugin_action_links( array() ) );
		$this->assertStringContainsString( '<a href="https://app.agency.example">Open Pirkoo</a>', $links );
		$this->assertStringContainsString( 'Disconnect from Pirkoo', $links );
		$this->assertStringNotContainsString( 'https://app.test', $links );

		// With a white label: Open takes its name and goes to the domain; nothing names Pirkoo.
		$this->send(
			array(
				'name'    => 'Agency Care',
				'app_url' => 'https://app.agency.example',
			)
		);
		$links = implode( ' ', pirkoo_plugin_action_links( array() ) );
		$this->assertStringContainsString( '<a href="https://app.agency.example">Open Agency Care</a>', $links );
		$this->assertStringContainsString( 'action=pirkoo_disconnect', $links );
		$this->assertStringNotContainsString( 'Pirkoo', $links );

		// No name: a neutral label.
		$this->send(
			array(
				'author'  => 'Agency',
				'app_url' => 'https://app.agency.example',
			)
		);
		$this->assertStringContainsString( '>Open dashboard</a>', implode( ' ', pirkoo_plugin_action_links( array() ) ) );

		// The domain removed: back to app.pirkoo.com, or no Open link while white-labelled.
		$this->send( array( 'name' => 'Agency Care' ) );
		$this->assertSame( PIRKOO_APP_URL, pirkoo_app_link() );
		$this->assertStringNotContainsString( 'Open', implode( ' ', pirkoo_plugin_action_links( array() ) ) );
		$this->send( null );
		$this->assertStringContainsString( '<a href="' . PIRKOO_APP_URL . '">Open Pirkoo</a>', implode( ' ', pirkoo_plugin_action_links( array() ) ) );
	}

	public function test_only_an_https_origin_is_taken_as_the_app_url() {
		foreach ( array( 'https://app.agency.example', 'https://App.Agency.Example/', 'https://xn--ag-uia.example' ) as $ok ) {
			$this->assertNotSame( '', pirkoo_white_label_app_url( $ok ), $ok );
		}
		$this->assertSame( 'https://app.agency.example', pirkoo_white_label_app_url( 'https://App.Agency.Example/' ) );
		foreach (
			array(
				'http://app.agency.example',
				'javascript:alert(1)',
				'https://app.agency.example/login',
				'https://app.agency.example?x=1',
				'https://user@app.agency.example',
				'https://app.agency.example:8443',
				'https://localhost',
				'https://app.agency.example"><script>',
				'//app.agency.example',
				array( 'https://app.agency.example' ),
				'https://' . str_repeat( 'a', 300 ) . '.example',
			) as $bad
		) {
			$this->assertSame( '', pirkoo_white_label_app_url( $bad ), (string) wp_json_encode( $bad ) );
		}
	}

	public function test_the_app_url_comes_only_from_a_signed_request() {
		$this->connect_store( self::SECRET );
		$response = $this->rest_post( '/pirkoo/v1/white-label', '{"white_label":{"app_url":"https://evil.example"}}' );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( PIRKOO_APP_URL, pirkoo_app_link() );
		$this->rest_post( '/pirkoo/v1/collect', '{"white_label":{"app_url":"https://app.agency.example"}}', self::SECRET );
		$this->assertSame( 'https://app.agency.example', pirkoo_app_link() );
		$this->rest_post( '/pirkoo/v1/disconnect', '{}', self::SECRET );
		$this->assertSame( PIRKOO_APP_URL, pirkoo_app_link() );
	}

	public function test_the_updates_screen_row() {
		ob_start();
		pirkoo_white_label_updates_screen();
		$this->assertSame( '', ob_get_clean(), 'Nothing without a white label.' );

		$this->connect_store( self::SECRET );
		$this->send(
			array(
				'name' => 'Agency "Care"',
				'logo' => 'https://agency.example/logo.png',
			)
		);
		ob_start();
		pirkoo_white_label_updates_screen();
		$script = (string) ob_get_clean();
		$this->assertStringContainsString( wp_json_encode( 'Agency "Care"' ), $script );
		$this->assertStringContainsString( 'agency.example\/logo.png', $script );
		$this->assertStringContainsString( (string) wp_json_encode( 'input[value="' . plugin_basename( PIRKOO_FILE ) . '"]' ), $script );
		$this->assertStringContainsString( 'if(false)', $script );

		$this->send( array( 'hide' => true ) );
		ob_start();
		pirkoo_white_label_updates_screen();
		$this->assertStringContainsString( 'if(true)', (string) ob_get_clean() );
	}
}
