<?php
/**
 * Plugin Name:  FGR Ticketsystem
 * Description:  Eigenes Support-Ticketsystem für die Freie Gestalterische Republik. Werbefrei.
 * Version:      1.4.4
 * Author:       Freie Gestalterische Republik
 * Author URI:   https://fgr.design
 * License:      GPL-2.0-or-later
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Text Domain:  fgr-ticketsystem
 */

defined( 'ABSPATH' ) || exit;

define( 'FGR_TS_VERSION', '1.4.4' );
define( 'FGR_TS_DIR',     plugin_dir_path( __FILE__ ) );
define( 'FGR_TS_URL',     plugin_dir_url( __FILE__ ) );
define( 'FGR_TS_DB_VERSION', '3' ); // bei Schema-Änderungen hochzählen, löst dbDelta erneut aus

// Update-Checker: fragt die zentrale FGR-Update-API ab (nicht direkt GitHub)
require_once FGR_TS_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
$fgr_ts_updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://fgr-plugins-api.fgr.design/fgr-ticketsystem.json',
    __FILE__,
    'fgr-ticketsystem'
);

// Auto-Update: WordPress' täglicher Update-Cron installiert neue Versionen automatisch
add_filter( 'auto_update_plugin', function ( $update, $item ) {
    if ( isset( $item->slug ) && $item->slug === 'fgr-ticketsystem' ) {
        return true;
    }
    return $update;
}, 10, 2 );

require_once FGR_TS_DIR . 'includes/class-fgr-ts-db.php';
require_once FGR_TS_DIR . 'includes/class-fgr-ts-migrate.php';
require_once FGR_TS_DIR . 'includes/class-fgr-ts-capabilities.php';
require_once FGR_TS_DIR . 'includes/class-fgr-ts-ticket.php';
require_once FGR_TS_DIR . 'includes/class-fgr-ts-notifications.php';
require_once FGR_TS_DIR . 'includes/class-fgr-ts-attachment.php';
require_once FGR_TS_DIR . 'includes/class-fgr-ts-registration.php';
require_once FGR_TS_DIR . 'includes/admin/class-fgr-ts-admin.php';
require_once FGR_TS_DIR . 'includes/frontend/class-fgr-ts-frontend.php';

register_activation_hook( __FILE__, function () {
    FGR_TS_DB::install();
    FGR_TS_Registration::activate();
} );
register_deactivation_hook( __FILE__, [ 'FGR_TS_Registration', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
    FGR_TS_DB::maybe_upgrade();
    FGR_TS_Attachment::init();
    new FGR_TS_Migrate();
    new FGR_TS_Notifications();
    new FGR_TS_Admin();
    new FGR_TS_Frontend();
    new FGR_TS_Registration();
} );

// Suchfeld in der Admin-Toolbar ausblenden: das globale Theme-CSS
// (body input[type="text"] { border-bottom: 3px solid ... }) erwischt es
// seitenweit und sieht kaputt aus - unabhängig vom Ticketsystem, aber hier
// aufgefallen. remove_node() allein reichte nicht zuverlässig, daher
// zusätzlich per CSS versteckt.
add_action( 'admin_bar_menu', function ( $wp_admin_bar ) {
    $wp_admin_bar->remove_node( 'search' );
}, 999 );
add_action( 'wp_before_admin_bar_render', function () {
    echo '<style>#wp-admin-bar-search{display:none!important;}</style>';
} );
