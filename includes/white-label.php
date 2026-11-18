<?php
/**
 * White label (0.7.0): how the plugin appears in this store's wp-admin, as the company that
 * manages the store set it at app.pirkoo.com (Settings → White label). The app sends it with a
 * signed request when it changes, and with every collection, so a store that was offline catches
 * up at its next scan. Disconnecting removes it.
 *
 * It changes the plugin's name, description and author on the Plugins screen and its row on
 * Dashboard → Updates, or hides it from both. The privacy policy text WordPress suggests
 * (pirkoo_privacy_policy_content()) keeps naming Pirkoo and tvarkom: it describes who processes
 * the store's data.
 *
 * @package Pirkoo
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PIRKOO_WHITE_LABEL_OPTION', 'pirkoo_white_label' );

/**
 * The store's white label; an empty array when there is none (the plugin shows as itself).
 *
 * @return array{hide: bool, name: string, description: string, author: string, author_url: string, logo: string}|array{}
 */
function pirkoo_white_label() {
	$saved = get_option( PIRKOO_WHITE_LABEL_OPTION, array() );
	if ( ! is_array( $saved ) || array() === $saved ) {
		return array();
	}
	return pirkoo_white_label_clean( $saved );
}

/**
 * Keeps only the white label's known fields, as plain text and web addresses of limited length.
 *
 * @param array<string, mixed> $data The white label as sent by the app.
 * @return array{hide: bool, name: string, description: string, author: string, author_url: string, logo: string}
 */
function pirkoo_white_label_clean( array $data ) {
	$text = static function ( $key, $max ) use ( $data ) {
		if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) ) {
			return '';
		}
		return mb_substr( sanitize_text_field( $data[ $key ] ), 0, $max );
	};
	$url  = static function ( $key, array $protocols ) use ( $data ) {
		if ( ! isset( $data[ $key ] ) || ! is_string( $data[ $key ] ) || strlen( $data[ $key ] ) > 500 ) {
			return '';
		}
		return esc_url_raw( trim( $data[ $key ] ), $protocols );
	};
	return array(
		'hide'        => ! empty( $data['hide'] ),
		'name'        => $text( 'name', 100 ),
		'description' => $text( 'description', 300 ),
		'author'      => $text( 'author', 100 ),
		'author_url'  => $url( 'author_url', array( 'https', 'http' ) ),
		'logo'        => $url( 'logo', array( 'https' ) ),
	);
}

/**
 * Keeps the white label the app sent; one with nothing set (or null) removes it.
 *
 * @param mixed $data The white label as sent by the app.
 * @return void
 */
function pirkoo_white_label_save( $data ) {
	$clean = is_array( $data ) ? pirkoo_white_label_clean( $data ) : array();
	if ( array() === $clean || ( ! $clean['hide'] && '' === $clean['name'] && '' === $clean['description'] && '' === $clean['author'] && '' === $clean['author_url'] && '' === $clean['logo'] ) ) {
		delete_option( PIRKOO_WHITE_LABEL_OPTION );
		return;
	}
	update_option( PIRKOO_WHITE_LABEL_OPTION, $clean, false );
}

/**
 * Applies the white label in a request from the app, when the request carries one (the key
 * white_label: an object, or null for none); a request without the key changes nothing.
 *
 * @param WP_REST_Request|null $request The app's request.
 * @return void
 */
function pirkoo_white_label_from_request( $request ) {
	if ( ! $request instanceof WP_REST_Request ) {
		return;
	}
	$params = $request->get_json_params();
	if ( is_array( $params ) && array_key_exists( 'white_label', $params ) ) {
		pirkoo_white_label_save( $params['white_label'] );
	}
}

/**
 * POST /pirkoo/v1/white-label {"white_label": {…}|null} (signed): the company changed its white label.
 *
 * @param WP_REST_Request $request The request.
 * @return array{status: string}
 */
function pirkoo_rest_white_label( WP_REST_Request $request ) {
	pirkoo_white_label_from_request( $request );
	return array( 'status' => 'ok' );
}

/**
 * The plugin's entry on the Plugins screen, renamed or removed (filter all_plugins).
 *
 * @param array<string, array<string, mixed>> $plugins The installed plugins' headers, by file.
 * @return array<string, array<string, mixed>>
 */
function pirkoo_white_label_plugins( $plugins ) {
	$file  = plugin_basename( PIRKOO_FILE );
	$label = pirkoo_white_label();
	if ( array() === $label || ! isset( $plugins[ $file ] ) || ! is_array( $plugins ) ) {
		return $plugins;
	}
	if ( $label['hide'] ) {
		unset( $plugins[ $file ] );
		return $plugins;
	}
	if ( '' !== $label['name'] ) {
		$plugins[ $file ]['Name']  = $label['name'];
		$plugins[ $file ]['Title'] = $label['name'];
	}
	if ( '' !== $label['description'] ) {
		$plugins[ $file ]['Description'] = $label['description'];
	}
	if ( '' !== $label['author'] ) {
		$plugins[ $file ]['Author']     = $label['author'];
		$plugins[ $file ]['AuthorName'] = $label['author'];
		$plugins[ $file ]['AuthorURI']  = $label['author_url'];
	} elseif ( '' !== $label['author_url'] ) {
		$plugins[ $file ]['AuthorURI'] = $label['author_url'];
	}
	// Links to pirkoo.com and to the plugin's WordPress.org details would name it.
	$plugins[ $file ]['PluginURI'] = '';
	return $plugins;
}

/**
 * The plugin's row links (filter plugin_row_meta): with a white label, no "View details" link to
 * its WordPress.org page.
 *
 * @param string[] $meta The links under the description.
 * @param string   $file The plugin's file.
 * @return string[]
 */
function pirkoo_white_label_row_meta( $meta, $file ) {
	if ( plugin_basename( PIRKOO_FILE ) !== $file || array() === pirkoo_white_label() ) {
		return $meta;
	}
	return array_values(
		array_filter(
			(array) $meta,
			static function ( $link ) {
				return false === strpos( (string) $link, 'plugin-information' );
			}
		)
	);
}

/**
 * The plugin's row on Dashboard → Updates (core reads the plugin's own headers there, not the
 * all_plugins filter): renamed, with the logo or no icon, or hidden.
 *
 * @return void
 */
function pirkoo_white_label_updates_screen() {
	$label = pirkoo_white_label();
	if ( array() === $label || ( ! $label['hide'] && '' === $label['name'] ) ) {
		return;
	}
	$script = sprintf(
		'document.addEventListener("DOMContentLoaded",function(){var b=document.querySelector(%1$s);if(!b){return;}var r=b.closest("tr");if(!r){return;}if(%2$s){r.style.display="none";return;}var c=r.querySelector(".plugin-title");if(!c){return;}var s=c.querySelector("strong");if(s){s.textContent=%3$s;}var i=c.querySelector("img");if(i){if(%4$s){i.src=%4$s;}else{i.remove();}}c.querySelectorAll("a").forEach(function(a){a.remove();});});',
		wp_json_encode( 'input[value="' . plugin_basename( PIRKOO_FILE ) . '"]' ),
		$label['hide'] ? 'true' : 'false',
		wp_json_encode( $label['name'] ),
		wp_json_encode( $label['logo'] )
	);
	wp_print_inline_script_tag( $script );
}
