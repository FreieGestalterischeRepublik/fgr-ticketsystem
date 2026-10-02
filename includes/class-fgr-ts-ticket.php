<?php
defined( 'ABSPATH' ) || exit;

/**
 * Model-Klasse: alle schreibenden/lesenden Zugriffe auf Tickets & Threads
 * laufen hier durch - UI (Admin, Frontend, REST) ruft nur diese Methoden auf.
 *
 * Löst bei wichtigen Ereignissen WP-Actions aus, an die sich
 * FGR_TS_Notifications (E-Mails) und später weitere Dinge (Aktivitäts-Log
 * im UI etc.) anhängen können.
 */
class FGR_TS_Ticket {

    public static function table( string $name ): string {
        global $wpdb;
        return $wpdb->prefix . 'fgr_ts_' . $name;
    }

    /**
     * Legt ein neues Ticket samt erster Nachricht (Beschreibung) an.
     * Gibt die neue Ticket-ID zurück.
     */
    public static function create( int $customer_id, string $subject, string $body, int $category_id, ?string $ip_address = null ): int {
        global $wpdb;

        $default_status_id   = (int) $wpdb->get_var( "SELECT id FROM " . self::table( 'statuses' ) . " WHERE is_closed = 0 ORDER BY sort_order ASC LIMIT 1" );
        $default_priority_id = (int) $wpdb->get_var( "SELECT id FROM " . self::table( 'priorities' ) . " ORDER BY sort_order ASC LIMIT 1" );
        $now = current_time( 'mysql' );

        $wpdb->insert( self::table( 'tickets' ), [
            'subject'      => $subject,
            'customer_id'  => $customer_id,
            'status_id'    => $default_status_id,
            'priority_id'  => $default_priority_id,
            'category_id'  => $category_id,
            'source'       => 'web',
            'ip_address'   => $ip_address,
            'gdpr_consent' => 1,
            'date_created' => $now,
            'date_updated' => $now,
        ] );
        $ticket_id = (int) $wpdb->insert_id;

        self::add_thread( $ticket_id, 'message', $customer_id, 'customer', $body, $ip_address );

        do_action( 'fgr_ts_ticket_created', $ticket_id );

        return $ticket_id;
    }

    /**
     * Fügt eine Nachricht/Notiz/Log-Zeile zu einem Ticket hinzu und
     * aktualisiert bei "message" automatisch den Status (siehe
     * Planungs-Notizen: Kundenantwort -> "Warten auf FGR-Antwort",
     * Agentenantwort -> "Warten auf Kundenantwort").
     */
    public static function add_thread( int $ticket_id, string $type, ?int $author_id, string $author_role, string $body, ?string $ip_address = null ): int {
        global $wpdb;

        $wpdb->insert( self::table( 'threads' ), [
            'ticket_id'    => $ticket_id,
            'type'         => $type,
            'author_id'    => $author_id,
            'author_role'  => $author_role,
            'body'         => $body,
            'ip_address'   => $ip_address,
            'date_created' => current_time( 'mysql' ),
        ] );
        $thread_id = (int) $wpdb->insert_id;

        $wpdb->update( self::table( 'tickets' ), [ 'date_updated' => current_time( 'mysql' ) ], [ 'id' => $ticket_id ] );

        if ( 'message' === $type && in_array( $author_role, [ 'customer', 'agent' ], true ) ) {
            self::auto_advance_status( $ticket_id, $author_role );
            do_action( 'fgr_ts_ticket_replied', $ticket_id, $author_role, $thread_id );
        }

        return $thread_id;
    }

    /**
     * Status-Automatik wie bisher in SupportCandy: Kundenantwort setzt auf
     * "Warten auf FGR-Antwort", Agentenantwort auf "Warten auf Kundenantwort".
     * Fest verdrahtet über den Status-Namen, nicht über eine ID, damit es
     * robust gegen Reihenfolge-Änderungen in der Stammdaten-Tabelle bleibt.
     */
    private static function auto_advance_status( int $ticket_id, string $author_role ): void {
        $target_name = 'customer' === $author_role ? 'Warten auf FGR-Antwort' : 'Warten auf Kundenantwort';
        self::set_status_by_name( $ticket_id, $target_name );
    }

    public static function set_status_by_name( int $ticket_id, string $status_name ): void {
        global $wpdb;
        $status_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . self::table( 'statuses' ) . " WHERE name = %s", $status_name ) );
        if ( $status_id ) {
            self::set_status( $ticket_id, $status_id );
        }
    }

    public static function set_status( int $ticket_id, int $status_id ): void {
        global $wpdb;
        $is_closed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT is_closed FROM " . self::table( 'statuses' ) . " WHERE id = %d", $status_id ) );

        $data = [ 'status_id' => $status_id, 'date_updated' => current_time( 'mysql' ) ];
        $data['date_closed'] = $is_closed ? current_time( 'mysql' ) : null;

        $wpdb->update( self::table( 'tickets' ), $data, [ 'id' => $ticket_id ] );

        do_action( 'fgr_ts_ticket_status_changed', $ticket_id, $status_id, (bool) $is_closed );
    }

    /**
     * Weist einen Agenten zu. Ab jetzt bekommt NUR noch dieser Agent
     * Benachrichtigungen zu diesem Ticket (siehe Planungs-Notizen).
     */
    public static function assign_agent( int $ticket_id, int $agent_id ): void {
        global $wpdb;
        $wpdb->update( self::table( 'tickets' ), [
            'assigned_agent' => $agent_id,
            'date_updated'   => current_time( 'mysql' ),
        ], [ 'id' => $ticket_id ] );

        do_action( 'fgr_ts_ticket_assigned', $ticket_id, $agent_id );
    }

    public static function get( int $ticket_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::table( 'tickets' ) . " WHERE id = %d", $ticket_id ), ARRAY_A );
        return $row ?: null;
    }

    public static function get_threads( int $ticket_id, bool $include_internal = true ): array {
        global $wpdb;
        $where = $include_internal ? "ticket_id = %d" : "ticket_id = %d AND type = 'message'";
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM " . self::table( 'threads' ) . " WHERE {$where} ORDER BY date_created ASC, id ASC", $ticket_id ), ARRAY_A );
    }

    /** Tickets, die ein Benutzer laut Rechtemodell sehen darf. */
    public static function get_for_user( int $user_id, array $filters = [] ): array {
        global $wpdb;
        $where  = [ '1=1' ];
        $params = [];

        if ( FGR_TS_Capabilities::is_admin_tier( $user_id ) ) {
            // keine Einschränkung
        } elseif ( FGR_TS_Capabilities::is_agent( $user_id ) ) {
            $where[]  = 'assigned_agent = %d';
            $params[] = $user_id;
        } else {
            $where[]  = 'customer_id = %d';
            $params[] = $user_id;
        }

        if ( ! empty( $filters['status_id'] ) ) {
            $where[]  = 'status_id = %d';
            $params[] = (int) $filters['status_id'];
        }

        $sql = "SELECT * FROM " . self::table( 'tickets' ) . " WHERE " . implode( ' AND ', $where ) . " ORDER BY date_updated DESC";
        if ( $params ) {
            $sql = $wpdb->prepare( $sql, $params );
        }
        return $wpdb->get_results( $sql, ARRAY_A );
    }

    /**
     * Nachrichtentext fürs Anzeigen aufbereiten. Mehrfache Leerzeilen
     * (3+ Zeilenumbrüche hintereinander, häufig in migrierten/eingefügten
     * Texten) werden auf einen normalen Absatzumbruch reduziert - sonst
     * erzeugt wpautop() dafür einen zusätzlichen leeren Absatz, der mit
     * dem globalen Theme-Absatzabstand (30px) riesige Lücken reißt.
     */
    public static function format_body( string $body ): string {
        $body = preg_replace( '/\n{3,}/', "\n\n", trim( $body ) );
        $html = wp_kses_post( wpautop( $body ) );
        // Manche (v.a. migrierte) Nachrichten haben bereits fertiges HTML
        // mit leeren Absätzen (<p>&nbsp;</p>) statt reiner Textzeilen -
        // die obige Zeilen-Normalisierung greift dann nicht, also zur
        // Sicherheit auch leere Absätze im Ergebnis-HTML entfernen.
        return preg_replace( '/<p>(\s|&nbsp;|&#160;)*<\/p>/i', '', $html );
    }
}
