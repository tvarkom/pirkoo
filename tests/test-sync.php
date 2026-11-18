<?php
/**
 * POST /pirkoo/v1/sync: the store's orders, customers, products and coupons for app.pirkoo.com.
 *
 * @package Pirkoo
 */

/**
 * The sync endpoint's records, paging and "since".
 */
class Test_Pirkoo_Sync extends Pirkoo_Test_Case {

	const SECRET = 'test-secret-0123456789-0123456789-abcd';

	/**
	 * One page of a kind.
	 *
	 * @param array<string, mixed> $body The request.
	 * @return array<string, mixed>
	 */
	private function sync( array $body ) {
		$response = $this->rest_post( '/pirkoo/v1/sync', (string) wp_json_encode( $body ), self::SECRET );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	public function test_sync_needs_the_signature_and_a_known_kind() {
		$this->assertSame( 401, $this->rest_post( '/pirkoo/v1/sync', '{"kind":"orders"}', self::SECRET )->get_status(), 'Not connected.' );
		$this->connect_store( self::SECRET );
		$this->assertSame( 401, $this->rest_post( '/pirkoo/v1/sync', '{"kind":"orders"}', 'other' )->get_status() );
		$this->assertSame( 400, $this->rest_post( '/pirkoo/v1/sync', '{"kind":"posts"}', self::SECRET )->get_status() );
	}

	public function test_orders_with_their_items_coupons_refunds_and_shopper() {
		$this->connect_store( self::SECRET );
		$product = new WC_Product_Simple();
		$product->set_name( 'Desk lamp' );
		$product->set_regular_price( '49' );
		$product->set_sku( 'LAMP-1' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 2 );
		$order->set_billing_first_name( 'Ona' );
		$order->set_billing_last_name( 'Jonaitė' );
		$order->set_billing_email( 'ona@example.com' );
		$order->set_billing_country( 'LT' );
		$order->set_billing_city( 'Kaunas' );
		$order->set_payment_method_title( 'Cash on delivery' );
		$order->calculate_totals();
		$order->set_status( 'completed' );
		$order->save();
		wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '49.00',
				'reason'   => 'Broken',
			)
		);

		$page = $this->sync(
			array(
				'kind'  => 'orders',
				'since' => 0,
			)
		);
		$this->assertFalse( $page['more'] );
		$this->assertCount( 1, $page['items'] );
		$o = $page['items'][0];
		$this->assertSame( $order->get_id(), $o['id'] );
		$this->assertSame( 'completed', $o['status'] );
		$this->assertSame( '98.00', $o['total'] );
		$this->assertSame( 'Cash on delivery', $o['payment_method'] );
		$this->assertSame( 'Ona', $o['customer']['first_name'] );
		$this->assertSame( 'ona@example.com', $o['customer']['email'] );
		$this->assertSame( 'LT', $o['customer']['country'] );
		$this->assertSame( $product->get_id(), $o['items'][0]['product_id'] );
		$this->assertSame( 2, $o['items'][0]['quantity'] );
		$this->assertSame( '98.00', $o['items'][0]['total'] );
		$this->assertCount( 1, $o['refunds'] );
		$this->assertSame( '49.00', $o['refunds'][0]['amount'] );
		$this->assertGreaterThan( 0, $o['created'] );

		$later = $this->sync(
			array(
				'kind'  => 'orders',
				'since' => time() + 3600,
			)
		);
		$this->assertSame( array(), $later['items'], 'Nothing changed since then.' );
	}

	/**
	 * Orders carry their fees and shipping methods' titles, and every page the records' schema
	 * (0.4.8), so app.pirkoo.com knows to sync older orders again.
	 */
	public function test_orders_carry_fees_and_shipping_methods() {
		$this->connect_store( self::SECRET );
		$product = new WC_Product_Simple();
		$product->set_name( 'Notebook' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Gift wrap' );
		$fee->set_total( '2.50' );
		$order->add_item( $fee );
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( 'Flat rate' );
		$shipping->set_method_id( 'flat_rate' );
		$shipping->set_total( '4.90' );
		$order->add_item( $shipping );
		$order->calculate_totals( false );
		$order->save();

		$page = $this->sync(
			array(
				'kind'  => 'orders',
				'since' => 0,
			)
		);
		$this->assertSame( 3, $page['schema'] );
		$o = $page['items'][0];
		$this->assertSame( '2.50', $o['fees'] );
		$this->assertSame( '4.90', $o['shipping'] );
		$this->assertSame( array( 'Flat rate' ), $o['shipping_methods'] );
		$this->assertSame( '17.40', $o['total'] );

		$plain = wc_create_order();
		$plain->add_product( $product, 1 );
		$plain->calculate_totals( false );
		$plain->save();
		$items = $this->sync(
			array(
				'kind'     => 'orders',
				'since'    => 0,
				'per_page' => 50, // Another body: the same signed request twice is refused as a replay.
			)
		)['items'];
		$last  = end( $items );
		$this->assertSame( '0.00', $last['fees'] );
		$this->assertSame( array(), $last['shipping_methods'] );
	}

	/**
	 * What the app's order page shows (0.5.0, records schema 3): both addresses, the payment
	 * method's id, fee and shipping lines, how it was created, its source, notes and custom fields,
	 * without those that look like secrets.
	 */
	public function test_orders_carry_what_the_order_page_shows() {
		$this->connect_store( self::SECRET );
		$product = new WC_Product_Simple();
		$product->set_name( 'Mug' );
		$product->set_regular_price( '12' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_billing_first_name( 'Ona' );
		$order->set_billing_email( 'ona@example.com' );
		$order->set_billing_address_1( 'Laisvės al. 1' );
		$order->set_shipping_first_name( 'Petras' );
		$order->set_shipping_address_1( 'Vilniaus g. 2' );
		$order->set_shipping_city( 'Vilnius' );
		$order->set_shipping_country( 'LT' );
		$order->set_payment_method( 'cod' );
		$order->set_payment_method_title( 'Cash on delivery' );
		$order->set_created_via( 'checkout' );
		$order->set_customer_note( 'Leave at the door' );
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Gift wrap' );
		$fee->set_total( '3.50' );
		$order->add_item( $fee );
		$order->update_meta_data( '_wc_order_attribution_utm_source', 'newsletter' );
		$order->update_meta_data( '_pirkoo_fake', '1' );
		$order->update_meta_data( 'gift_message', array( 'to' => 'Ona' ) );
		$order->update_meta_data( '_stripe_customer_token', 'tok_123' );
		$order->update_meta_data( '_api_key', 'k' );
		$order->update_meta_data( '_customer_ip_address_copy', '10.0.0.1' );
		$order->update_meta_data( 'user_agent', 'Mozilla' );
		$order->calculate_totals( false );
		$order->save();
		$order->add_order_note( 'Packed.' );

		$o = $this->sync(
			array(
				'kind'  => 'orders',
				'since' => 0,
			)
		)['items'][0];
		$this->assertSame( 'cod', $o['payment_method_id'] );
		$this->assertSame( 'checkout', $o['created_via'] );
		$this->assertSame( 'guest', $o['customer_role'] );
		$this->assertSame( 'Leave at the door', $o['customer_note'] );
		$this->assertSame( 'Laisvės al. 1', $o['customer']['address_1'] );
		$this->assertSame( 'Petras', $o['shipping_address']['first_name'] );
		$this->assertSame( 'Vilnius', $o['shipping_address']['city'] );
		$this->assertSame(
			array(
				array(
					'name'  => 'Gift wrap',
					'total' => '3.50',
				),
			),
			$o['fee_lines']
		);
		$this->assertSame( 'newsletter', $o['attribution']['utm_source'] );
		$this->assertSame( 'Packed.', $o['notes'][0]['content'] );
		$keys = wp_list_pluck( $o['meta'], 'key' );
		$this->assertContains( '_pirkoo_fake', $keys );
		$this->assertContains( 'gift_message', $keys );
		$this->assertNotContains( '_stripe_customer_token', $keys );
		$this->assertNotContains( '_api_key', $keys );
		$this->assertNotContains( '_customer_ip_address_copy', $keys );
		$this->assertNotContains( 'user_agent', $keys );
		$gift = array_values( wp_list_filter( $o['meta'], array( 'key' => 'gift_message' ) ) );
		$this->assertSame( '{"to":"Ona"}', $gift[0]['value'] );
	}

	public function test_secret_looking_keys() {
		foreach ( array( '_order_key', 'stripe_token', 'api_secret', 'password', '_card_last4', 'session_hash', '_customer_ip_address', 'ip', 'user_agent', '_wc_order_attribution_user_agent' ) as $key ) {
			$this->assertTrue( pirkoo_sync_secret_key( $key ), $key );
		}
		foreach ( array( '_pirkoo_fake', 'gift_message', '_wc_order_attribution_utm_source', 'shipping_zip', 'description' ) as $key ) {
			$this->assertFalse( pirkoo_sync_secret_key( $key ), $key );
		}
	}

	public function test_orders_page_by_page() {
		$this->connect_store( self::SECRET );
		for ( $i = 0; $i < 3; $i++ ) {
			wc_create_order()->save();
		}
		$first = $this->sync(
			array(
				'kind'     => 'orders',
				'since'    => 0,
				'per_page' => 2,
			)
		);
		$this->assertCount( 2, $first['items'] );
		$this->assertTrue( $first['more'] );
		$second = $this->sync(
			array(
				'kind'     => 'orders',
				'since'    => 0,
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$this->assertCount( 1, $second['items'] );
		$this->assertFalse( $second['more'] );
	}

	public function test_products_with_variations_and_categories() {
		$this->connect_store( self::SECRET );
		$category = wp_insert_term( 'Lighting', 'product_cat' );
		$this->assertIsArray( $category );
		$attribute = new WC_Product_Attribute();
		$attribute->set_name( 'Size' );
		$attribute->set_options( array( 'S', 'M' ) );
		$attribute->set_variation( true );
		$product = new WC_Product_Variable();
		$product->set_name( 'Lamp' );
		$product->set_category_ids( array( (int) $category['term_id'] ) );
		$product->set_attributes( array( $attribute ) );
		$product->save();
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product->get_id() );
		$variation->set_attributes( array( 'size' => 'M' ) );
		$variation->set_regular_price( '20' );
		$variation->set_manage_stock( true );
		$variation->set_stock_quantity( 7 );
		$variation->save();

		$page  = $this->sync(
			array(
				'kind'  => 'products',
				'since' => 0,
			)
		);
		$items = array_values(
			array_filter(
				$page['items'],
				function ( $p ) use ( $product ) {
					return $p['id'] === $product->get_id();
				}
			)
		);
		$this->assertCount( 1, $items );
		$this->assertSame( 'variable', $items[0]['type'] );
		$this->assertSame( 'Lighting', $items[0]['categories'][0]['name'] );
		$this->assertCount( 1, $items[0]['variations'] );
		$this->assertSame( 7, $items[0]['variations'][0]['stock_quantity'] );
		$this->assertSame( '20.00', $items[0]['variations'][0]['regular_price'] );
	}

	public function test_customers_and_coupons() {
		$this->connect_store( self::SECRET );
		$customer = new WC_Customer();
		$customer->set_email( 'jonas@example.com' );
		$customer->set_first_name( 'Jonas' );
		$customer->set_billing_country( 'LT' );
		$customer->set_password( wp_generate_password() );
		$customer->save();
		$coupon = new WC_Coupon();
		$coupon->set_code( 'spring10' );
		$coupon->set_amount( '10' );
		$coupon->save();

		$customers = $this->sync(
			array(
				'kind'  => 'customers',
				'since' => 0,
			)
		);
		$emails    = wp_list_pluck( $customers['items'], 'email' );
		$this->assertContains( 'jonas@example.com', $emails );

		$coupons = $this->sync(
			array(
				'kind'  => 'coupons',
				'since' => 0,
			)
		);
		$this->assertSame( 'spring10', $coupons['items'][0]['code'] );
		$this->assertSame( '10.00', $coupons['items'][0]['amount'] );
		$this->assertFalse( $coupons['items'][0]['restrictions']['individual_use'] );
		$this->assertSame( 0, $coupons['items'][0]['restrictions']['allowed_emails'] );
		$this->assertSame( 'customer', $customers['items'][0]['role'] );
		$this->assertSame( 'LT', $customers['items'][0]['billing']['country'] );
	}
}
