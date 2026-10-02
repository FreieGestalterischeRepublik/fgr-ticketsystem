<?php
/**
 * Plugin Name:  FGR Ticketsystem
 * Description:  Eigenes Support-Ticketsystem für die Freie Gestalterische Republik, als Ersatz für SupportCandy. Werbefrei.
 * Version:      0.1.0
 * Author:       Freie Gestalterische Republik
 * Author URI:   https://fgr.design
 * License:      GPL-2.0-or-later
 * Requires PHP: 7.4
 * Requires at least: 6.0
 * Text Domain:  fgr-ticketsystem
 */

defined( 'ABSPATH' ) || exit;

define( 'FGR_TS_VERSION', '0.1.0' );
define( 'FGR_TS_DIR',     plugin_dir_path( __FILE__ ) );
define( 'FGR_TS_URL',     plugin_dir_url( __FILE__ ) );
define( 'FGR_TS_DB_VERSION', '1' ); // bei Schema-Änderungen hochzählen, löst dbDelta erneut aus

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

register_activation_hook( __FILE__, [ 'FGR_TS_DB', 'install' ] );

add_action( 'plugins_loaded', function () {
    FGR_TS_DB::maybe_upgrade();
    new FGR_TS_Migrate();
    new FGR_TS_Notifications();
} );
