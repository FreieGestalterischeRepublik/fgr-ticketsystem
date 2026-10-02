<?php
defined( 'ABSPATH' ) || exit;

/**
 * Legt die eigenen Datenbanktabellen an und hält sie aktuell.
 *
 * Schema (bewusst schlanker als SupportCandy, siehe Planungs-Notizen):
 * - fgr_ts_statuses / fgr_ts_priorities / fgr_ts_categories: konfigurierbare Stammdaten
 * - fgr_ts_tickets: ein Ticket
 * - fgr_ts_threads: jede Nachricht zu einem Ticket (type: message = Beschreibung/Antwort,
 *   note = interne Notiz nur für Agenten, log = automatischer Aktivitätseintrag)
 * - fgr_ts_attachments: Dateianhänge, an einen Thread gehängt
 */
class FGR_TS_DB {

    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'fgr_ts_';

        $sql = "
        CREATE TABLE {$p}statuses (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            color VARCHAR(20) NOT NULL DEFAULT '#000000',
            bg_color VARCHAR(20) NOT NULL DEFAULT '#eeeeee',
            is_closed TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) {$charset_collate};

        CREATE TABLE {$p}priorities (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            color VARCHAR(20) NOT NULL DEFAULT '#000000',
            bg_color VARCHAR(20) NOT NULL DEFAULT '#eeeeee',
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) {$charset_collate};

        CREATE TABLE {$p}categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            PRIMARY KEY (id)
        ) {$charset_collate};

        CREATE TABLE {$p}tickets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subject VARCHAR(255) NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            assigned_agent BIGINT UNSIGNED NULL,
            status_id BIGINT UNSIGNED NOT NULL,
            priority_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL,
            source VARCHAR(50) NOT NULL DEFAULT 'web',
            ip_address VARCHAR(45) NULL,
            gdpr_consent TINYINT(1) NOT NULL DEFAULT 0,
            date_created DATETIME NOT NULL,
            date_updated DATETIME NOT NULL,
            date_closed DATETIME NULL,
            legacy_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY customer_id (customer_id),
            KEY assigned_agent (assigned_agent),
            KEY status_id (status_id),
            KEY legacy_id (legacy_id)
        ) {$charset_collate};

        CREATE TABLE {$p}threads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            type VARCHAR(20) NOT NULL DEFAULT 'message',
            author_id BIGINT UNSIGNED NULL,
            author_role VARCHAR(20) NULL,
            body LONGTEXT NOT NULL,
            ip_address VARCHAR(45) NULL,
            date_created DATETIME NOT NULL,
            legacy_id BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY ticket_id (ticket_id),
            KEY legacy_id (legacy_id)
        ) {$charset_collate};

        CREATE TABLE {$p}attachments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            thread_id BIGINT UNSIGNED NULL,
            ticket_id BIGINT UNSIGNED NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            uploaded_by BIGINT UNSIGNED NULL,
            date_created DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY thread_id (thread_id),
            KEY ticket_id (ticket_id)
        ) {$charset_collate};
        ";

        dbDelta( $sql );

        self::maybe_seed_defaults();

        update_option( 'fgr_ts_db_version', FGR_TS_DB_VERSION );
    }

    public static function maybe_upgrade(): void {
        if ( get_option( 'fgr_ts_db_version' ) !== FGR_TS_DB_VERSION ) {
            self::install();
        }
    }

    /**
     * Legt beim allerersten Aktivieren die Standard-Stammdaten an, 1:1 wie
     * aktuell in SupportCandy konfiguriert (siehe Planungs-Notizen).
     */
    private static function maybe_seed_defaults(): void {
        global $wpdb;
        $p = $wpdb->prefix . 'fgr_ts_';

        if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}statuses" ) === 0 ) {
            $statuses = [
                [ 'offen', '#ffffff', '#2271b1', 0 ],
                [ 'Warten auf Kundenantwort', '#000000', '#f0c33c', 0 ],
                [ 'Warten auf FGR-Antwort', '#ffffff', '#d63638', 0 ],
                [ 'geschlossen', '#ffffff', '#787c82', 1 ],
                [ 'In Wartestellung', '#000000', '#eeeeee', 0 ],
            ];
            foreach ( $statuses as $i => $s ) {
                $wpdb->insert( "{$p}statuses", [
                    'name'       => $s[0],
                    'color'      => $s[1],
                    'bg_color'   => $s[2],
                    'is_closed'  => $s[3],
                    'sort_order' => $i,
                ] );
            }
        }

        if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}priorities" ) === 0 ) {
            foreach ( [ 'Niedrig', 'Mittel', 'Hoch' ] as $i => $name ) {
                $wpdb->insert( "{$p}priorities", [ 'name' => $name, 'sort_order' => $i ] );
            }
        }

        if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}categories" ) === 0 ) {
            foreach ( [ 'Allgemein', 'Internetseite', 'FGR Server' ] as $i => $name ) {
                $wpdb->insert( "{$p}categories", [ 'name' => $name, 'sort_order' => $i ] );
            }
        }
    }
}
