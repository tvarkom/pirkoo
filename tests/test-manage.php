<?php
/**
 * POST /pirkoo/v1/inventory, /pirkoo/v1/update and /pirkoo/v1/install.
 *
 * @package Pirkoo
 */

/**
 * WordPress management for app.pirkoo.com.
 */
class Test_Pirkoo_Manage extends Pirkoo_Test_Case {

	const SECRET = 'test-secret-0123456789-0123456789-abcd';

	public function test_inventory_lists_core_plugins_themes_and_php_errors() {
		$this->connect_store( self::SECRET );
		$response = $this->rest_post( '/pirkoo/v1/inventory', '{}', self::SECRET );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$this->assertSame( array( 'core', 'plugins', 'themes', 'php_errors' ), array_keys( $data ) );
		$this->assertSame( get_bloginfo( 'version' ), $data['core']['version'] );
		$this->assertContains( 'woocommerce', wp_list_pluck( $data['plugins'], 'slug' ) );
		$this->assertNotEmpty( $data['themes'] );
		$this->assertSame( array( 'file', 'slug', 'name', 'version', 'author', 'active', 'update', 'auto_updates' ), array_keys( $data['plugins'][0] ) );
	}

	public function test_log_messages_lose_what_identifies_someone() {
		$this->assertSame(
			'Failed for [email] from [ip] at [link] order [number]',
			pirkoo_redact( 'Failed for ona@example.com from 203.0.113.9 at https://shop.example/x?y=1 order 12345678' )
		);
	}

	public function test_update_refuses_what_it_cannot_update() {
		$this->connect_store( self::SECRET );
		$unknown = $this->rest_post(
			'/pirkoo/v1/update',
			(string) wp_json_encode(
				array(
					'type'   => 'plugin',
					'target' => 'nope/nope.php',
				)
			),
			self::SECRET
		);
		$this->assertSame( 404, $unknown->get_status() );
		$bad = $this->rest_post(
			'/pirkoo/v1/update',
			(string) wp_json_encode(
				array(
					'type'   => 'database',
					'target' => 'x',
				)
			),
			self::SECRET
		);
		$this->assertSame( 400, $bad->get_status() );
		// WordPress itself isn't updated from the app (owner, 18 Nov 2026).
		$core = $this->rest_post( '/pirkoo/v1/update', '{"type":"core","target":"core"}', self::SECRET );
		$this->assertSame( 400, $core->get_status() );
		$this->assertSame( 'pirkoo_invalid_update', $core->get_data()['code'] );
		$this->assertSame( 401, $this->rest_post( '/pirkoo/v1/update', '{"type":"core"}', 'other' )->get_status() );
		$this->assertFalse( get_option( PIRKOO_LOCK ), 'The lock is released.' );
	}

	public function test_install_refuses_plugins_pirkoo_does_not_recommend() {
		$this->connect_store( self::SECRET );
		foreach ( array( 'hello-dolly', '../woocommerce', '' ) as $slug ) {
			$response = $this->rest_post( '/pirkoo/v1/install', (string) wp_json_encode( array( 'slug' => $slug ) ), self::SECRET );
			$this->assertSame( 400, $response->get_status(), $slug );
			$this->assertSame( 'pirkoo_not_installable', $response->get_data()['code'] );
		}
		$this->assertSame( 401, $this->rest_post( '/pirkoo/v1/install', '{"slug":"query-monitor"}', 'other' )->get_status() );
		$this->assertFalse( get_option( PIRKOO_LOCK ), 'The lock is released.' );
	}

	public function test_install_fails_cleanly_when_wordpress_org_is_offline() {
		$this->connect_store( self::SECRET );
		// Requests outside the fake app fail as if offline (Pirkoo_Test_Case).
		$response = $this->rest_post( '/pirkoo/v1/install', '{"slug":"query-monitor"}', self::SECRET );
		$this->assertContains( $response->get_status(), array( 409, 502 ), (string) wp_json_encode( $response->get_data() ) );
		$this->assertContains( $response->get_data()['code'], array( 'pirkoo_install_failed', 'pirkoo_files_not_writable' ) );
		$this->assertFalse( get_option( PIRKOO_LOCK ), 'The lock is released.' );
	}

	public function test_install_activates_a_recommended_plugin_already_there() {
		$folder = WP_PLUGIN_DIR . '/wp-crontrol';
		if ( ! wp_mkdir_p( $folder ) || ! wp_is_writable( $folder ) ) {
			$this->markTestSkipped( 'The plugins folder is not writable here.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- a fixture plugin.
		file_put_contents( $folder . '/wp-crontrol.php', "<?php\n/**\n * Plugin Name: WP Crontrol\n * Version: 9.9.9\n */\n" );
		wp_clean_plugins_cache( false );
		try {
			$this->connect_store( self::SECRET );
			$response = $this->rest_post( '/pirkoo/v1/install', '{"slug":"wp-crontrol"}', self::SECRET );
			$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
			$this->assertSame(
				array(
					'slug'      => 'wp-crontrol',
					'file'      => 'wp-crontrol/wp-crontrol.php',
					'version'   => '9.9.9',
					'installed' => false,
					'active'    => true,
				),
				$response->get_data()
			);
			$this->assertTrue( is_plugin_active( 'wp-crontrol/wp-crontrol.php' ) );
		} finally {
			deactivate_plugins( 'wp-crontrol/wp-crontrol.php', true );
			wp_delete_file( $folder . '/wp-crontrol.php' );
			rmdir( $folder ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- the fixture's folder.
			wp_clean_plugins_cache( false );
		}
	}
}
