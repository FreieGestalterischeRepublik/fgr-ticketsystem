<?php
defined( 'ABSPATH' ) || exit;

/**
 * Einmaliger Import der bestehenden SupportCandy-Daten in das eigene Schema.
 *
 * Aufruf: wp fgr-ts migrate --path=...
 *
 * Voraussetzung: SupportCandy (das alte Plugin) ist noch installiert bzw.
 * seine Tabellen (Präfix "psmsc_") existieren noch in derselben Datenbank.
 * Nichts an den SupportCandy-Tabellen wird verändert - reiner Lese-Import.
 *
 * Vereinfachungen gegenüber dem Original (siehe Planungs-Notizen):
 * - Mehrfach zugewiesene Agenten (z.B. "1|3") werden auf den ERSTEN
 *   genannten Agenten reduziert - im neuen System gibt es nur einen
 *   zuständigen Agenten pro Ticket.
 * - Soft-gelöschte Tickets/Threads (is_active = 0) werden nicht übernommen.
 * - Tags, Timer, KI-Felder, Mehrfach-Empfänger etc. werden nicht übernommen
 *   (siehe Planungs-Notizen: ungenutzt).
 */
class FGR_TS_Migrate {

    public function __construct() {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'fgr-ts migrate', [ $this, 'run' ] );
        }
    }

    public function run( $args, $assoc_args ): void {
        global $wpdb;
        $old = $wpdb->prefix . 'psmsc_';
        $new = $wpdb->prefix . 'fgr_ts_';

        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$old}tickets'" ) !== $old . 'tickets' ) {
            WP_CLI::error( 'SupportCandy-Tabellen nicht gefunden (Präfix ' . $old . '). Ist das Plugin noch installiert?' );
            return;
        }

        if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new}tickets" ) > 0 && ! isset( $assoc_args['force'] ) ) {
            WP_CLI::error( 'Es gibt bereits Tickets in fgr_ts_tickets. Mit --force trotzdem erneut importieren (vorher bestehende Daten manuell löschen).' );
            return;
        }

        // --- Stammdaten-Zuordnung alt -> neu, über den Namen ---
        $status_map   = $this->map_by_name( "{$old}statuses", "{$new}statuses" );
        $priority_map = $this->map_by_name( "{$old}priorities", "{$new}priorities" );
        $category_map = $this->map_by_name( "{$old}categories", "{$new}categories" );

        // --- Agenten-IDs (wp_users.ID), um author_role zu bestimmen ---
        $agent_ids = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$old}agents" ) );

        // --- Kunde (SupportCandy-interne customer.id) -> wp_users.ID ---
        $customer_user_map = [];
        foreach ( $wpdb->get_results( "SELECT id, user FROM {$old}customers", ARRAY_A ) as $row ) {
            $customer_user_map[ (int) $row['id'] ] = (int) $row['user'];
        }

        // --- Tickets ---
        $tickets = $wpdb->get_results( "SELECT * FROM {$old}tickets WHERE is_active = 1", ARRAY_A );
        $ticket_id_map = [];
        $imported_tickets = 0;

        foreach ( $tickets as $t ) {
            $customer_id = $customer_user_map[ (int) $t['customer'] ] ?? null;
            if ( ! $customer_id ) {
                WP_CLI::warning( "Ticket #{$t['id']}: kein Benutzer gefunden, übersprungen." );
                continue;
            }

            $assigned_agent = null;
            if ( ! empty( $t['assigned_agent'] ) ) {
                $first = explode( '|', $t['assigned_agent'] )[0];
                $assigned_agent = is_numeric( $first ) ? (int) $first : null;
            }

            $wpdb->insert( "{$new}tickets", [
                'subject'        => $t['subject'],
                'customer_id'    => $customer_id,
                'assigned_agent' => $assigned_agent,
                'status_id'      => $status_map[ (int) $t['status'] ] ?? array_key_first( $status_map ),
                'priority_id'    => $priority_map[ (int) $t['priority'] ] ?? array_key_first( $priority_map ),
                'category_id'    => $category_map[ (int) $t['category'] ] ?? array_key_first( $category_map ),
                'source'         => 'legacy-' . $t['source'],
                'ip_address'     => $t['ip_address'] ?: null,
                'gdpr_consent'   => 1, // historisch, war zum Erstellzeitpunkt Pflicht
                'date_created'   => $t['date_created'],
                'date_updated'   => $t['date_updated'],
                'date_closed'    => $t['date_closed'] ?: null,
                'legacy_id'      => $t['id'],
            ] );

            $ticket_id_map[ (int) $t['id'] ] = (int) $wpdb->insert_id;
            $imported_tickets++;
        }
        WP_CLI::log( "{$imported_tickets} Tickets importiert." );

        // --- Threads ---
        $type_map = [ 'report' => 'message', 'reply' => 'message', 'note' => 'note', 'log' => 'log' ];
        $thread_id_map = [];
        $imported_threads = 0;

        $old_ids_csv = implode( ',', array_keys( $ticket_id_map ) ) ?: '0';
        $threads = $wpdb->get_results( "SELECT * FROM {$old}threads WHERE ticket IN ({$old_ids_csv}) ORDER BY id ASC", ARRAY_A );

        foreach ( $threads as $th ) {
            $new_ticket_id = $ticket_id_map[ (int) $th['ticket'] ] ?? null;
            if ( ! $new_ticket_id ) {
                continue;
            }

            $author_id = $th['customer'] ? (int) $th['customer'] : null;
            if ( ! $author_id ) {
                $author_role = 'system';
            } elseif ( in_array( $author_id, $agent_ids, true ) ) {
                $author_role = 'agent';
            } else {
                $author_role = 'customer';
            }

            $wpdb->insert( "{$new}threads", [
                'ticket_id'    => $new_ticket_id,
                'type'         => $type_map[ $th['type'] ] ?? 'log',
                'author_id'    => $author_id,
                'author_role'  => $author_role,
                'body'         => $th['body'],
                'ip_address'   => $th['ip_address'] ?: null,
                'date_created' => $th['date_created'],
                'legacy_id'    => $th['id'],
            ] );

            $thread_id_map[ (int) $th['id'] ] = (int) $wpdb->insert_id;
            $imported_threads++;
        }
        WP_CLI::log( "{$imported_threads} Thread-Einträge importiert." );

        // --- Attachments ---
        $attachments = $wpdb->get_results( "SELECT * FROM {$old}attachments WHERE ticket_id IN ({$old_ids_csv}) AND is_active = 1", ARRAY_A );
        $imported_attachments = 0;

        foreach ( $attachments as $a ) {
            $new_ticket_id = $ticket_id_map[ (int) $a['ticket_id'] ] ?? null;
            if ( ! $new_ticket_id ) {
                continue;
            }
            $new_thread_id = $thread_id_map[ (int) $a['source_id'] ] ?? null;

            $wpdb->insert( "{$new}attachments", [
                'thread_id'    => $new_thread_id,
                'ticket_id'    => $new_ticket_id,
                'file_path'    => $a['file_path'],
                'file_name'    => $a['name'],
                'uploaded_by'  => is_numeric( $a['uploaded_by'] ) ? (int) $a['uploaded_by'] : null,
                'date_created' => $a['date_created'],
            ] );
            $imported_attachments++;
        }
        WP_CLI::log( "{$imported_attachments} Anhänge importiert." );

        WP_CLI::success( 'Migration abgeschlossen.' );
    }

    private function map_by_name( string $old_table, string $new_table ): array {
        global $wpdb;
        $old_rows = $wpdb->get_results( "SELECT id, name FROM {$old_table}", ARRAY_A );
        $new_rows = $wpdb->get_results( "SELECT id, name FROM {$new_table}", ARRAY_A );

        $new_by_name = [];
        foreach ( $new_rows as $r ) {
            $new_by_name[ $r['name'] ] = (int) $r['id'];
        }

        $map = [];
        foreach ( $old_rows as $r ) {
            if ( isset( $new_by_name[ $r['name'] ] ) ) {
                $map[ (int) $r['id'] ] = $new_by_name[ $r['name'] ];
            }
        }
        return $map;
    }
}
