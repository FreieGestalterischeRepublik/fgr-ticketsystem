<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$p = $wpdb->prefix . 'fgr_ts_';

foreach ( [
    'attachments',
    'ticket_watchers',
    'ticket_agents',
    'threads',
    'tickets',
    'categories',
    'priorities',
    'statuses',
] as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$p}{$table}" );
}

foreach ( [ 'fgr_ts_db_version', 'fgr_ts_portal_page_id', 'fgr_ts_gdpr_text' ] as $option ) {
    delete_option( $option );
}

// $delete_all = true löscht den Meta-Key bei ALLEN Benutzern, nicht nur bei einem.
foreach ( [
    'fgr_ts_is_agent',
    'fgr_ts_tier',
    'fgr_ts_pending_confirmation', // FGR_TS_Registration::META_PENDING
    'fgr_ts_confirm_token',        // FGR_TS_Registration::META_TOKEN
    'fgr_ts_confirm_expires',      // FGR_TS_Registration::META_EXPIRES
] as $meta_key ) {
    delete_metadata( 'user', 0, $meta_key, '', true );
}

wp_clear_scheduled_hook( 'fgr_ts_cleanup_unconfirmed' );

// Hochgeladene Anhänge liegen außerhalb der Datenbank unter wp-content/uploads
// und werden von keiner der obigen Löschungen erfasst.
$upload_dir = wp_get_upload_dir()['basedir'] . '/fgr-ticketsystem';
if ( is_dir( $upload_dir ) ) {
    fgr_ts_uninstall_rrmdir( $upload_dir );
}

function fgr_ts_uninstall_rrmdir( string $dir ): void {
    $items = scandir( $dir );
    if ( false === $items ) {
        return;
    }
    foreach ( $items as $item ) {
        if ( '.' === $item || '..' === $item ) {
            continue;
        }
        $path = $dir . '/' . $item;
        if ( is_dir( $path ) ) {
            fgr_ts_uninstall_rrmdir( $path );
        } else {
            @unlink( $path );
        }
    }
    @rmdir( $dir );
}
