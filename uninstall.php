<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$p = $wpdb->prefix . 'fgr_ts_';
foreach ( [ 'attachments', 'threads', 'tickets', 'categories', 'priorities', 'statuses' ] as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$p}{$table}" );
}
delete_option( 'fgr_ts_db_version' );
