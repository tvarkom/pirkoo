<?php
/**
 * WordPress management for app.pirkoo.com (Pirkoo 0.4, owner decisions of 15 Nov 2026): the store's
 * WordPress core, plugins and themes with their versions and available updates, its PHP errors of
 * the last 7 days, and an update of one of them when an Owner or Admin asks for it in the app.
 * Nothing is activated, deactivated or deleted from the app.
 *
 * @package Pirkoo
 */

defined( 'ABSPATH' ) || exit;

/**
 * POST /pirkoo/v1/inventory: core, plugins and themes with their updates, and PHP errors.
 *
 * @return array<string, mixed>
 */
function pirkoo_rest_inventory() {
	pirkoo_load_admin_files();
	// WordPress's own update checks, which ask WordPress.org at most twice a day.
	wp_version_check();
	wp_update_plugins();
	wp_update_themes();

	return array(
		'core'       => pirkoo_inventory_core(),
		'plugins'    => pirkoo_inventory_plugins(),
		'themes'     => pirkoo_inventory_themes(),
		'php_errors' => pirkoo_php_errors_7d(),
	);
}

/**
 * Loads the wp-admin files updates and plugin lists need, outside wp-admin.
 *
 * @return void
 */
function pirkoo_load_admin_files() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/theme.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
}

/**
 * WordPress core: its version, the newest one offered, and its automatic updates.
 *
 * @return array{version: string, update: string, auto_updates: string}
 */
function pirkoo_inventory_core() {
	$update = '';
	foreach ( (array) get_core_updates() as $offer ) {
		if ( is_object( $offer ) && isset( $offer->response, $offer->current ) && 'upgrade' === $offer->response ) {
			$update = (string) $offer->current;
			break;
		}
	}
	return array(
		'version'      => (string) get_bloginfo( 'version' ),
		'update'       => $update,
		'auto_updates' => pirkoo_core_auto_updates(),
	);
}

/**
 * The installed plugins with their versions and updates.
 *
 * @return array<int, array<string, mixed>>
 */
function pirkoo_inventory_plugins() {
	$updates = get_site_transient( 'update_plugins' );
	$auto    = (array) get_site_option( 'auto_update_plugins', array() );
	$out     = array();
	foreach ( get_plugins() as $file => $data ) {
		$slug   = dirname( $file );
		$update = '';
		if ( is_object( $updates ) && isset( $updates->response[ $file ]->new_version ) ) {
			$update = (string) $updates->response[ $file ]->new_version;
		}
		$out[] = array(
			'file'         => $file,
			'slug'         => sanitize_key( '.' === $slug ? basename( $file, '.php' ) : $slug ),
			'name'         => wp_strip_all_tags( (string) $data['Name'] ),
			'version'      => (string) $data['Version'],
			'author'       => wp_strip_all_tags( (string) $data['Author'] ),
			'active'       => is_plugin_active( $file ),
			'update'       => $update,
			'auto_updates' => in_array( $file, $auto, true ),
		);
	}
	return $out;
}

/**
 * The installed themes with their versions and updates.
 *
 * @return array<int, array<string, mixed>>
 */
function pirkoo_inventory_themes() {
	$updates = get_site_transient( 'update_themes' );
	$active  = get_stylesheet();
	$parent  = get_template();
	$out     = array();
	foreach ( wp_get_themes() as $slug => $theme ) {
		$update = '';
		if ( is_object( $updates ) && isset( $updates->response[ $slug ]['new_version'] ) ) {
			$update = (string) $updates->response[ $slug ]['new_version'];
		}
		$out[] = array(
			'slug'    => (string) $slug,
			'name'    => wp_strip_all_tags( (string) $theme->get( 'Name' ) ),
			'version' => (string) $theme->get( 'Version' ),
			'active'  => $slug === $active,
			'parent'  => $slug === $parent && $parent !== $active,
			'update'  => $update,
		);
	}
	return $out;
}

/**
 * The PHP errors of the last 7 days in the store's PHP log, grouped by message, at most 100 groups:
 * the level, the message with email addresses, IP addresses, links and long numbers replaced, the
 * file relative to WordPress's folder and its line, how many times and when last.
 *
 * @return array<int, array<string, mixed>>|null Null when there is no readable log.
 */
function pirkoo_php_errors_7d() {
	$tail = pirkoo_php_log_tail();
	if ( null === $tail ) {
		return null;
	}
	$since  = time() - 7 * DAY_IN_SECONDS;
	$groups = array();
	if ( ! preg_match_all( '/^\[([^\]]+)\] PHP (Fatal error|Parse error|Warning|Notice|Deprecated):\s+(.+?)(?: in (\S+?)(?: on line |:)(\d+))?$/m', $tail, $matches, PREG_SET_ORDER ) ) {
		return array();
	}
	foreach ( $matches as $m ) {
		$at = strtotime( $m[1] );
		if ( ! $at || $at < $since ) {
			continue;
		}
		$message = pirkoo_redact( $m[3] );
		$file    = isset( $m[4] ) ? str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $m[4] ) ) : '';
		$key     = md5( $m[2] . $message . $file );
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'level'   => strtolower( str_replace( ' error', '', $m[2] ) ),
				'message' => mb_substr( $message, 0, 300 ),
				'file'    => mb_substr( $file, 0, 200 ),
				'line'    => isset( $m[5] ) ? (int) $m[5] : 0,
				'count'   => 0,
				'last'    => 0,
			);
		}
		++$groups[ $key ]['count'];
		$groups[ $key ]['last'] = max( $groups[ $key ]['last'], $at );
	}
	usort(
		$groups,
		function ( $a, $b ) {
			return $b['last'] - $a['last'];
		}
	);
	return array_slice( array_values( $groups ), 0, 100 );
}

/**
 * Removes what could identify someone from a log message: email addresses, IP addresses, links,
 * and runs of six or more digits.
 *
 * @param string $text The message.
 * @return string
 */
function pirkoo_redact( $text ) {
	$text = (string) preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email]', $text );
	$text = (string) preg_replace( '#https?://\S+#i', '[link]', $text );
	$text = (string) preg_replace( '/\b(?:\d{1,3}\.){3}\d{1,3}\b|\b(?:[0-9a-f]{1,4}:){2,7}[0-9a-f]{1,4}\b/i', '[ip]', $text );
	return (string) preg_replace( '/\d{6,}/', '[number]', $text );
}

/**
 * POST /pirkoo/v1/update {"type": "plugin"|"theme"|"core", "target"}: updates one plugin (by its
 * file), theme (by its folder) or WordPress core to the newest version WordPress offers, as
 * Dashboard → Updates would, and answers the version now installed.
 *
 * @param WP_REST_Request $request The request.
 * @return array{type: string, target: string, version: string}|WP_Error
 */
function pirkoo_rest_update( WP_REST_Request $request ) {
	$type   = (string) $request->get_param( 'type' );
	$target = (string) $request->get_param( 'target' );
	if ( ! wp_is_file_mod_allowed( 'pirkoo_update' ) ) {
		return new WP_Error( 'pirkoo_updates_disabled', 'This site doesn\'t allow updates from WordPress (DISALLOW_FILE_MODS).', array( 'status' => 409 ) );
	}
	if ( ! pirkoo_lock() ) {
		return new WP_Error( 'pirkoo_busy', 'Another Pirkoo task is running.', array( 'status' => 409 ) );
	}
	try {
		pirkoo_load_admin_files();
		wp_version_check();
		wp_update_plugins();
		wp_update_themes();
		$skin = new Automatic_Upgrader_Skin();
		switch ( $type ) {
			case 'plugin':
				if ( ! array_key_exists( $target, get_plugins() ) ) {
					return new WP_Error( 'pirkoo_unknown_plugin', 'No such plugin.', array( 'status' => 404 ) );
				}
				$active = is_plugin_active( $target );
				$result = ( new Plugin_Upgrader( $skin ) )->upgrade( $target );
				if ( true === $result && $active && ! is_plugin_active( $target ) ) {
					activate_plugin( $target ); // An update keeps the plugin active, as Dashboard → Updates does.
				}
				$plugins = get_plugins();
				$version = isset( $plugins[ $target ]['Version'] ) ? (string) $plugins[ $target ]['Version'] : '';
				break;
			case 'theme':
				if ( ! wp_get_theme( $target )->exists() ) {
					return new WP_Error( 'pirkoo_unknown_theme', 'No such theme.', array( 'status' => 404 ) );
				}
				$result  = ( new Theme_Upgrader( $skin ) )->upgrade( $target );
				$version = (string) wp_get_theme( $target )->get( 'Version' );
				break;
			case 'core':
				$offer = find_core_update( '', get_locale() );
				if ( ! $offer ) {
					$updates = get_core_updates();
					$offer   = is_array( $updates ) && isset( $updates[0] ) ? $updates[0] : false;
				}
				if ( ! is_object( $offer ) || 'upgrade' !== $offer->response ) {
					return new WP_Error( 'pirkoo_no_update', 'No WordPress update is offered.', array( 'status' => 409 ) );
				}
				$result  = ( new Core_Upgrader( $skin ) )->upgrade( $offer );
				$version = is_string( $result ) ? $result : (string) get_bloginfo( 'version' );
				$result  = is_wp_error( $result ) ? $result : true;
				break;
			default:
				return new WP_Error( 'pirkoo_invalid_update', 'Unknown type.', array( 'status' => 400 ) );
		}
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'pirkoo_update_failed', $result->get_error_message(), array( 'status' => 502 ) );
		}
		if ( true !== $result && null !== $result ) {
			$errors = $skin->get_errors();
			return new WP_Error( 'pirkoo_update_failed', $errors->has_errors() ? $errors->get_error_message() : 'The update didn\'t complete.', array( 'status' => 502 ) );
		}
		return array(
			'type'    => $type,
			'target'  => $target,
			'version' => $version,
		);
	} finally {
		pirkoo_unlock();
	}
}
