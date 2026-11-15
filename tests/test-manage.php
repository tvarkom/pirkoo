<?php
/**
 * POST /pirkoo/v1/inventory and /pirkoo/v1/update.
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
		$unknown = $this->rest_post( '/pirkoo/v1/update', (string) wp_json_encode( array( 'type' => 'plugin', 'target' => 'nope/nope.php' ) ), self::SECRET );
		$this->assertSame( 404, $unknown->get_status() );
		$bad = $this->rest_post( '/pirkoo/v1/update', (string) wp_json_encode( array( 'type' => 'database', 'target' => 'x' ) ), self::SECRET );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 401, $this->rest_post( '/pirkoo/v1/update', '{"type":"core"}', 'other' )->get_status() );
		$this->assertFalse( get_option( PIRKOO_LOCK ), 'The lock is released.' );
	}
}
