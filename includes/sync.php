<?php
/**
 * The store's commerce records for app.pirkoo.com's Orders, Refunds, Customers, Products and
 * Coupons pages (Pirkoo 0.4, owner decisions of 15 Nov 2026): app.pirkoo.com pulls them page by
 * page with signed requests, the last 12 months when the store connects and what changed since
 * each day after. Orders carry the shopper's name, email and address, as shown in the app.
 *
 * @package Pirkoo
 */

defined( 'ABSPATH' ) || exit;

/** The most records one sync page returns. */
const PIRKOO_SYNC_MAX_PAGE = 100;

/**
 * What the records hold, raised when they gain fields (2: orders' fees and shipping methods, 0.4.8):
 * app.pirkoo.com syncs the 12 months of orders again when it rises, so older ones gain them too.
 */
const PIRKOO_SYNC_SCHEMA = 2;

/**
 * POST /pirkoo/v1/sync {"kind", "since", "page", "per_page"}: one page of the store's records of a
 * kind (orders, customers, products or coupons) changed since a Unix time, oldest change first.
 *
 * @param WP_REST_Request $request The request.
 * @return array{kind: string, schema: int, page: int, more: bool, items: array<int, array<string, mixed>>}|WP_Error
 */
function pirkoo_rest_sync( WP_REST_Request $request ) {
	$kind     = $request->get_param( 'kind' );
	$since    = (int) $request->get_param( 'since' );
	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = min( PIRKOO_SYNC_MAX_PAGE, max( 1, (int) ( $request->get_param( 'per_page' ) ? $request->get_param( 'per_page' ) : PIRKOO_SYNC_MAX_PAGE ) ) );
	$readers  = array(
		'orders'    => 'pirkoo_sync_orders',
		'customers' => 'pirkoo_sync_customers',
		'products'  => 'pirkoo_sync_products',
		'coupons'   => 'pirkoo_sync_coupons',
	);
	if ( ! is_string( $kind ) || ! isset( $readers[ $kind ] ) || $since < 0 ) {
		return new WP_Error( 'pirkoo_invalid_sync', 'Unknown kind or bad since.', array( 'status' => 400 ) );
	}
	list( $items, $more ) = call_user_func( $readers[ $kind ], $since, $page, $per_page );
	return array(
		'kind'   => $kind,
		'schema' => PIRKOO_SYNC_SCHEMA,
		'page'   => $page,
		'more'   => $more,
		'items'  => $items,
	);
}

/**
 * A WooCommerce date as Unix seconds, or 0.
 *
 * @param WC_DateTime|null $date The date.
 * @return int
 */
function pirkoo_sync_time( $date ) {
	return $date instanceof WC_DateTime ? $date->getTimestamp() : 0;
}

/**
 * Money as a string with the store's decimals (exact, never a float in JSON).
 *
 * @param mixed $amount The amount.
 * @return string
 */
function pirkoo_sync_money( $amount ) {
	return wc_format_decimal( (string) $amount, wc_get_price_decimals() );
}

/**
 * Orders changed since $since (any status but drafts), with their items, coupons and refunds.
 *
 * @param int $since    Unix seconds.
 * @param int $page     The page, from 1.
 * @param int $per_page Orders per page.
 * @return array{0: array<int, array<string, mixed>>, 1: bool}
 */
function pirkoo_sync_orders( $since, $page, $per_page ) {
	$result = wc_get_orders(
		array(
			'type'          => 'shop_order',
			'status'        => array_keys( wc_get_order_statuses() ),
			'date_modified' => '>=' . $since,
			'orderby'       => 'modified',
			'order'         => 'ASC',
			'limit'         => $per_page,
			'paged'         => $page,
			'paginate'      => true,
		)
	);
	$items  = array();
	foreach ( $result->orders as $order ) {
		if ( $order instanceof WC_Order ) {
			$items[] = pirkoo_sync_order( $order );
		}
	}
	return array( $items, $page < (int) $result->max_num_pages );
}

/**
 * One order as the app keeps it.
 *
 * @param WC_Order $order The order.
 * @return array<string, mixed>
 */
function pirkoo_sync_order( WC_Order $order ) {
	$lines = array();
	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		// A variation's attributes as ordered (an "any" attribute's value is the order's own).
		$attributes = array();
		$variation  = $item->get_variation_id() ? wc_get_product( $item->get_variation_id() ) : null;
		if ( $variation instanceof WC_Product_Variation ) {
			foreach ( array_keys( $variation->get_variation_attributes( false ) ) as $name ) {
				$value = $item->get_meta( $name );
				if ( '' === $value ) {
					$value = $variation->get_attribute( $name );
				}
				$attributes[ wc_attribute_label( $name, $variation ) ] = is_scalar( $value ) ? (string) $value : '';
			}
		}
		$lines[] = array(
			'id'           => $item->get_id(),
			'product_id'   => $item->get_product_id(),
			'variation_id' => $item->get_variation_id(),
			'name'         => $item->get_name(),
			'quantity'     => (int) $item->get_quantity(),
			'subtotal'     => pirkoo_sync_money( $item->get_subtotal() ),
			'total'        => pirkoo_sync_money( $item->get_total() ),
			'attributes'   => (object) $attributes,
		);
	}
	$coupons = array();
	foreach ( $order->get_items( 'coupon' ) as $coupon ) {
		if ( $coupon instanceof WC_Order_Item_Coupon ) {
			$coupons[] = array(
				'code'     => $coupon->get_code(),
				'discount' => pirkoo_sync_money( $coupon->get_discount() ),
			);
		}
	}
	$shipping_methods = array();
	foreach ( $order->get_items( 'shipping' ) as $shipping ) {
		if ( $shipping instanceof WC_Order_Item_Shipping ) {
			$shipping_methods[] = $shipping->get_method_title();
		}
	}
	$refunds = array();
	foreach ( $order->get_refunds() as $refund ) {
		$refund_lines = array();
		foreach ( $refund->get_items( 'line_item' ) as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				$refund_lines[] = array(
					'product_id'   => $item->get_product_id(),
					'variation_id' => $item->get_variation_id(),
					'quantity'     => abs( (int) $item->get_quantity() ),
					'total'        => pirkoo_sync_money( abs( (float) $item->get_total() ) ),
				);
			}
		}
		$refunds[] = array(
			'id'      => $refund->get_id(),
			'created' => pirkoo_sync_time( $refund->get_date_created() ),
			'amount'  => pirkoo_sync_money( $refund->get_amount() ),
			'reason'  => $refund->get_reason(),
			'items'   => $refund_lines,
		);
	}
	return array(
		'id'               => $order->get_id(),
		'number'           => (string) $order->get_order_number(),
		'status'           => $order->get_status(),
		'currency'         => $order->get_currency(),
		'created'          => pirkoo_sync_time( $order->get_date_created() ),
		'paid'             => pirkoo_sync_time( $order->get_date_paid() ),
		'completed'        => pirkoo_sync_time( $order->get_date_completed() ),
		'modified'         => pirkoo_sync_time( $order->get_date_modified() ),
		'total'            => pirkoo_sync_money( $order->get_total() ),
		'subtotal'         => pirkoo_sync_money( $order->get_subtotal() ),
		'discount'         => pirkoo_sync_money( $order->get_discount_total() ),
		'shipping'         => pirkoo_sync_money( $order->get_shipping_total() ),
		'tax'              => pirkoo_sync_money( $order->get_total_tax() ),
		'fees'             => pirkoo_sync_money( $order->get_total_fees() ),
		'payment_method'   => $order->get_payment_method_title(),
		'shipping_methods' => $shipping_methods,
		'customer_id'      => $order->get_customer_id(),
		'customer'         => array(
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'email'      => $order->get_billing_email(),
			'phone'      => $order->get_billing_phone(),
			'company'    => $order->get_billing_company(),
			'city'       => $order->get_billing_city(),
			'state'      => $order->get_billing_state(),
			'postcode'   => $order->get_billing_postcode(),
			'country'    => $order->get_billing_country(),
		),
		'items'            => $lines,
		'coupons'          => $coupons,
		'refunds'          => $refunds,
	);
}

/**
 * Registered customers (role customer) changed since $since, by WooCommerce's last_update.
 *
 * @param int $since    Unix seconds.
 * @param int $page     The page, from 1.
 * @param int $per_page Customers per page.
 * @return array{0: array<int, array<string, mixed>>, 1: bool}
 */
function pirkoo_sync_customers( $since, $page, $per_page ) {
	$query = new WP_User_Query(
		array(
			'role'        => 'customer',
			'number'      => $per_page,
			'paged'       => $page,
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'count_total' => true,
			'meta_query'  => $since > 0 ? array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- daily sync, paged.
				array(
					'key'     => 'last_update',
					'value'   => $since,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			) : array(),
		)
	);
	$items = array();
	foreach ( $query->get_results() as $user ) {
		$customer = new WC_Customer( $user->ID );
		$items[]  = array(
			'id'         => $customer->get_id(),
			'joined'     => pirkoo_sync_time( $customer->get_date_created() ),
			'modified'   => pirkoo_sync_time( $customer->get_date_modified() ),
			'first_name' => $customer->get_first_name() ? $customer->get_first_name() : $customer->get_billing_first_name(),
			'last_name'  => $customer->get_last_name() ? $customer->get_last_name() : $customer->get_billing_last_name(),
			'email'      => $customer->get_email(),
			'phone'      => $customer->get_billing_phone(),
			'city'       => $customer->get_billing_city(),
			'state'      => $customer->get_billing_state(),
			'postcode'   => $customer->get_billing_postcode(),
			'country'    => $customer->get_billing_country(),
		);
	}
	return array( $items, $page * $per_page < (int) $query->get_total() );
}

/**
 * Products and their variations changed since $since.
 *
 * @param int $since    Unix seconds.
 * @param int $page     The page, from 1.
 * @param int $per_page Products per page (variations come with their product).
 * @return array{0: array<int, array<string, mixed>>, 1: bool}
 */
function pirkoo_sync_products( $since, $page, $per_page ) {
	$result = wc_get_products(
		array(
			'status'        => array( 'publish', 'private', 'draft', 'pending' ),
			'type'          => array_keys( wc_get_product_types() ),
			'date_modified' => '>=' . $since,
			'orderby'       => 'modified',
			'order'         => 'ASC',
			'limit'         => $per_page,
			'page'          => $page,
			'paginate'      => true,
		)
	);
	$items  = array();
	foreach ( $result->products as $product ) {
		if ( ! $product instanceof WC_Product ) {
			continue;
		}
		$item = pirkoo_sync_product( $product );
		if ( $product instanceof WC_Product_Variable ) {
			$item['variations'] = array();
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child instanceof WC_Product_Variation ) {
					$item['variations'][] = pirkoo_sync_product( $child );
				}
			}
		}
		$items[] = $item;
	}
	return array( $items, $page < (int) $result->max_num_pages );
}

/**
 * One product or variation.
 *
 * @param WC_Product $product The product.
 * @return array<string, mixed>
 */
function pirkoo_sync_product( WC_Product $product ) {
	$categories = array();
	if ( ! $product instanceof WC_Product_Variation ) {
		foreach ( $product->get_category_ids() as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( $term instanceof WP_Term ) {
				$categories[] = array(
					'id'   => $term->term_id,
					'name' => $term->name,
				);
			}
		}
	}
	$attributes = array();
	if ( $product instanceof WC_Product_Variation ) {
		foreach ( $product->get_variation_attributes( false ) as $name => $value ) {
			$attributes[ wc_attribute_label( $name, $product ) ] = (string) $value;
		}
	}
	return array(
		'id'             => $product->get_id(),
		'parent_id'      => $product->get_parent_id(),
		'type'           => $product->get_type(),
		'name'           => $product->get_name(),
		'sku'            => $product->get_sku(),
		'status'         => $product->get_status(),
		'regular_price'  => pirkoo_sync_money( $product->get_regular_price() ),
		'price'          => pirkoo_sync_money( $product->get_price() ),
		'manage_stock'   => (bool) $product->get_manage_stock(),
		'stock_quantity' => $product->get_manage_stock() ? (int) $product->get_stock_quantity() : null,
		'stock_status'   => $product->get_stock_status(),
		'created'        => pirkoo_sync_time( $product->get_date_created() ),
		'modified'       => pirkoo_sync_time( $product->get_date_modified() ),
		'categories'     => $categories,
		'attributes'     => (object) $attributes,
	);
}

/**
 * Coupons changed since $since.
 *
 * @param int $since    Unix seconds.
 * @param int $page     The page, from 1.
 * @param int $per_page Coupons per page.
 * @return array{0: array<int, array<string, mixed>>, 1: bool}
 */
function pirkoo_sync_coupons( $since, $page, $per_page ) {
	$query = new WP_Query(
		array(
			'post_type'      => 'shop_coupon',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'modified',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'date_query'     => $since > 0 ? array(
				array(
					'column'    => 'post_modified_gmt',
					'after'     => gmdate( 'Y-m-d H:i:s', $since ),
					'inclusive' => true,
				),
			) : array(),
		)
	);
	$items = array();
	foreach ( $query->posts as $id ) {
		$coupon  = new WC_Coupon( (int) $id );
		$items[] = array(
			'id'          => $coupon->get_id(),
			'code'        => $coupon->get_code(),
			'type'        => $coupon->get_discount_type(),
			'amount'      => pirkoo_sync_money( $coupon->get_amount() ),
			'created'     => pirkoo_sync_time( $coupon->get_date_created() ),
			'modified'    => pirkoo_sync_time( $coupon->get_date_modified() ),
			'expires'     => pirkoo_sync_time( $coupon->get_date_expires() ),
			'usage_count' => (int) $coupon->get_usage_count(),
		);
	}
	return array( $items, $page < (int) $query->max_num_pages );
}
