<?php
/**
 * WordPress management for app.pirkoo.com (Pirkoo 0.4, owner decisions of 15 Nov 2026): the store's
 * WordPress core, plugins and themes with their versions and available updates, its PHP errors of
 * the last 7 days, and an update of one of them when an Owner or Admin asks for it in the app.
 * From Pirkoo 0.6 (owner, 18 Nov 2026), an Owner or Admin can also install and activate one of the
 * WordPress.org plugins Pirkoo recommends (pirkoo_installable_plugins()); nothing else is
 * installed, and nothing is deactivated or deleted from the app.
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
	pirkoo_refresh_updates();

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
 * WordPress's own update checks, which ask WordPress.org at most twice a day. When WordPress.org
 * can't be reached, WordPress raises a warning; it's ignored here, and the last known updates are
 * used.
 *
 * @return void
 */
function pirkoo_refresh_updates() {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- only around WordPress's own update checks.
	set_error_handler(
		function ( $level ) {
			return E_USER_WARNING === $level;
		}
	);
	try {
		wp_version_check();
		wp_update_plugins();
		wp_update_themes();
	} finally {
		restore_error_handler();
	}
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
	return array_slice( $groups, 0, 100 );
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
		pirkoo_refresh_updates();
		$skin = new Automatic_Upgrader_Skin();
		switch ( $type ) {
			case 'plugin':
				if ( ! array_key_exists( $target, get_plugins() ) ) {
					return new WP_Error( 'pirkoo_unknown_plugin', 'No such plugin.', array( 'status' => 404 ) );
				}
				$blocked = pirkoo_cannot_write( $skin, WP_PLUGIN_DIR );
				if ( $blocked ) {
					return $blocked;
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
				$blocked = pirkoo_cannot_write( $skin, get_theme_root( $target ) );
				if ( $blocked ) {
					return $blocked;
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
				$blocked = pirkoo_cannot_write( $skin, ABSPATH );
				if ( $blocked ) {
					return $blocked;
				}
				// Core_Upgrader answers the new version, a WP_Error, or false when it gave up (no
				// access to the files): only a version is a finished update.
				$result  = ( new Core_Upgrader( $skin ) )->upgrade( $offer );
				$version = is_string( $result ) ? $result : (string) get_bloginfo( 'version' );
				$result  = is_string( $result ) ? true : ( is_wp_error( $result ) ? $result : false );
				break;
			default:
				return new WP_Error( 'pirkoo_invalid_update', 'Unknown type.', array( 'status' => 400 ) );
		}
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'pirkoo_update_failed', $result->get_error_message(), array( 'status' => 502 ) );
		}
		if ( true !== $result && null !== $result ) {
			$messages = array_filter( array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) );
			return new WP_Error( 'pirkoo_update_failed', $messages ? implode( ' ', $messages ) : 'The update didn\'t complete.', array( 'status' => 502 ) );
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

/**
 * An error when WordPress can't change the files under $context without asking for FTP or SSH
 * details, as its own automatic updates check first; null when it can.
 *
 * @param Automatic_Upgrader_Skin $skin    The upgrader's skin.
 * @param string                  $context The folder the update or install writes to.
 * @return WP_Error|null
 */
function pirkoo_cannot_write( $skin, $context ) {
	if ( $skin->request_filesystem_credentials( false, $context ) ) {
		return null;
	}
	return new WP_Error(
		'pirkoo_files_not_writable',
		'WordPress can\'t write to its own files on this server (it would ask for FTP details), so it can\'t do this from Pirkoo. Ask the host to let the web server write to the WordPress folder, or do it from the store\'s admin.',
		array( 'status' => 409 )
	);
}

/**
 * The plugins Pirkoo may install: the WordPress.org plugins app.pirkoo.com recommends in its fixes
 * (their slugs). Only these; anything else is refused.
 *
 * @return string[]
 */
function pirkoo_installable_plugins() {
	return array(
		'advanced-database-cleaner',
		'better-wp-security',
		'health-check',
		'litespeed-cache',
		'query-monitor',
		'redis-cache',
		'shortpixel-image-optimiser',
		'stops-core-theme-and-plugin-updates',
		'updraftplus',
		'wordfence',
		'wp-crontrol',
		'wp-mail-smtp',
		'wp-optimize',
	);
}

/**
 * The installed plugin in the folder $slug (its file, such as "query-monitor/query-monitor.php"),
 * or "".
 *
 * @param string $slug The plugin's folder name.
 * @return string
 */
function pirkoo_plugin_file( $slug ) {
	foreach ( array_keys( get_plugins() ) as $file ) {
		if ( dirname( (string) $file ) === $slug ) {
			return (string) $file;
		}
	}
	return '';
}

/**
 * POST /pirkoo/v1/install {"slug"}: installs one of the recommended plugins from WordPress.org,
 * as Plugins → Add New would, and activates it (an installed one is only activated). Answers the
 * plugin's file and version, whether it was installed now, and whether it is active.
 *
 * @param WP_REST_Request $request The request.
 * @return array{slug: string, file: string, version: string, installed: bool, active: bool}|WP_Error
 */
function pirkoo_rest_install( WP_REST_Request $request ) {
	$slug = (string) $request->get_param( 'slug' );
	if ( ! in_array( $slug, pirkoo_installable_plugins(), true ) ) {
		return new WP_Error( 'pirkoo_not_installable', 'Pirkoo only installs the plugins it recommends.', array( 'status' => 400 ) );
	}
	if ( ! wp_is_file_mod_allowed( 'pirkoo_install' ) ) {
		return new WP_Error( 'pirkoo_updates_disabled', 'This site doesn\'t allow installing plugins from WordPress (DISALLOW_FILE_MODS).', array( 'status' => 409 ) );
	}
	if ( ! pirkoo_lock() ) {
		return new WP_Error( 'pirkoo_busy', 'Another Pirkoo task is running.', array( 'status' => 409 ) );
	}
	try {
		pirkoo_load_admin_files();
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		$file      = pirkoo_plugin_file( $slug );
		$installed = false;
		if ( '' === $file ) {
			$skin    = new Automatic_Upgrader_Skin();
			$blocked = pirkoo_cannot_write( $skin, WP_PLUGIN_DIR );
			if ( $blocked ) {
				return $blocked;
			}
			// When WordPress.org can't be reached, plugins_api() raises a warning as well as
			// answering an error: the error is what counts (as in pirkoo_refresh_updates()).
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- only around plugins_api().
			set_error_handler(
				function ( $level ) {
					return E_USER_WARNING === $level;
				}
			);
			try {
				$api = plugins_api(
					'plugin_information',
					array(
						'slug'   => $slug,
						'fields' => array( 'sections' => false ),
					)
				);
			} finally {
				restore_error_handler();
			}
			if ( is_wp_error( $api ) || ! is_object( $api ) || empty( $api->download_link ) ) {
				return new WP_Error( 'pirkoo_install_failed', 'WordPress.org couldn\'t be reached for the plugin. Try again in a few minutes.', array( 'status' => 502 ) );
			}
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->install( (string) $api->download_link );
			if ( true !== $result ) {
				$message  = is_wp_error( $result ) ? $result->get_error_message() : '';
				$messages = array_filter( array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) );
				$message  = '' !== $message ? $message : ( $messages ? implode( ' ', $messages ) : 'The install didn\'t complete.' );
				return new WP_Error( 'pirkoo_install_failed', $message, array( 'status' => 502 ) );
			}
			$file      = (string) $upgrader->plugin_info();
			$file      = '' !== $file ? $file : pirkoo_plugin_file( $slug );
			$installed = true;
		}
		if ( '' === $file ) {
			return new WP_Error( 'pirkoo_install_failed', 'The plugin was installed, but its file wasn\'t found.', array( 'status' => 502 ) );
		}
		if ( ! is_plugin_active( $file ) ) {
			$activated = activate_plugin( $file );
			if ( is_wp_error( $activated ) ) {
				return new WP_Error( 'pirkoo_activate_failed', 'The plugin is installed, but WordPress didn\'t activate it: ' . $activated->get_error_message(), array( 'status' => 502 ) );
			}
		}
		$plugins = get_plugins();
		return array(
			'slug'      => $slug,
			'file'      => $file,
			'version'   => isset( $plugins[ $file ]['Version'] ) ? (string) $plugins[ $file ]['Version'] : '',
			'installed' => $installed,
			'active'    => is_plugin_active( $file ),
		);
	} finally {
		pirkoo_unlock();
	}
}
