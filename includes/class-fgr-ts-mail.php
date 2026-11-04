<?php
defined( 'ABSPATH' ) || exit;

/**
 * E-Mail-Eingang: Ein System-Cronjob ruft per WP-CLI ein IMAP-Postfach ab
 * (wp fgr-ts fetch-mail). Antworten auf Benachrichtigungen landen im Ticket,
 * Mails ohne Ticketbezug von bekannten Benutzern eröffnen ein neues Ticket.
 *
 * Nichts wird gelöscht: Verarbeitete Mails wandern in den Ordner
 * FGR-Verarbeitet, alles Zweifelhafte in FGR-Pruefen. Der Grund steht auf der
 * Seite "E-Mail-Eingang". Eine Mail aus FGR-Pruefen zurück in den Eingang zu
 * verschieben, verarbeitet sie erneut.
 *
 * Sicherheit: Antworten brauchen eine HMAC-Referenz pro Ticket (steht nur in
 * Mails an Beteiligte) UND einen Absender, der am Ticket beteiligt ist, weil
 * sich der From-Header fälschen lässt. Neue Tickets haben keine Referenz, dort
 * bleibt die Absenderprüfung von Rspamd (SPF/DMARC) die einzige Hürde.
 */
class FGR_TS_Mail {

    const OPTION        = 'fgr_ts_mail';
    const STATUS_OPTION = 'fgr_ts_mail_status';

    const FOLDER_DONE   = 'FGR-Verarbeitet';
    const FOLDER_REVIEW = 'FGR-Pruefen';

    const MARKER = '##- Bitte oberhalb dieser Zeile antworten -##';

    const BATCH_SIZE         = 50;
    const MAX_MESSAGE_BYTES  = 41943040; // 40 MB (Base64 bläht 20-MB-Anhänge um ein Drittel auf)
    const MAX_BODY_CHARS     = 200000;
    const MAX_MAILS_PER_HOUR = 20;       // bremst Autoresponder-Schleifen ohne Auto-Submitted-Header
    const LOG_RETENTION_DAYS = 30;

    public static function init(): void {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            WP_CLI::add_command( 'fgr-ts fetch-mail', [ __CLASS__, 'cli_fetch' ] );
            WP_CLI::add_command( 'fgr-ts process-eml', [ __CLASS__, 'cli_process_eml' ] );
        }
    }

    // --- Einstellungen ---------------------------------------------------

    public static function settings(): array {
        return wp_parse_args( (array) get_option( self::OPTION, [] ), [
            'enabled'    => 0,
            'address'    => '',
            'host'       => '',
            'port'       => 993,
            'encryption' => 'ssl',
            'username'   => '',
            'password'   => '',
        ] );
    }

    /** Bestimmt auch, ob ausgehende Mails Reply-To und Referenz tragen. Standard: aus. */
    public static function is_enabled(): bool {
        $settings = self::settings();
        return ! empty( $settings['enabled'] ) && is_email( $settings['address'] );
    }

    public static function encrypt_password( string $password ): string {
        $iv     = random_bytes( 16 );
        $cipher = openssl_encrypt( $password, 'AES-256-CBC', self::crypto_key(), 0, $iv );
        return 'enc:' . base64_encode( $iv . $cipher );
    }

    private static function decrypt_password( string $stored ): string {
        if ( 0 !== strpos( $stored, 'enc:' ) ) {
            return $stored;
        }
        $raw = (string) base64_decode( substr( $stored, 4 ) );
        return (string) openssl_decrypt( substr( $raw, 16 ), 'AES-256-CBC', self::crypto_key(), 0, substr( $raw, 0, 16 ) );
    }

    private static function crypto_key(): string {
        return hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY, true );
    }

    // --- Referenz in ausgehenden Mails ---------------------------------

    public static function reference( int $ticket_id ): string {
        return sprintf( '[FGR-TS:%d:%s]', $ticket_id, self::reference_token( $ticket_id ) );
    }

    private static function reference_token( int $ticket_id ): string {
        return substr( hash_hmac( 'sha256', 'fgr-ts-reply|' . $ticket_id, wp_salt( 'auth' ) ), 0, 12 );
    }

    /** Ticket-ID aus der ersten gültigen Referenz, 0 = keine vorhanden, -1 = nur ungültige. */
    private static function find_ticket_reference( array $mail ): int {
        $haystack = $mail['subject'] . "\n" . $mail['text'];
        if ( ! preg_match_all( '/\[FGR-TS:(\d+):([0-9a-f]{12})\]/', $haystack, $matches, PREG_SET_ORDER ) ) {
            return 0;
        }
        foreach ( $matches as $match ) {
            if ( hash_equals( self::reference_token( (int) $match[1] ), $match[2] ) ) {
                return (int) $match[1];
            }
        }
        return -1;
    }

    // --- Entscheidung --------------------------------------------------

    /**
     * Entscheidet nur, schreibt nichts (auch die Grundlage für --dry-run).
     * Ergebnis: action = reply|new|review, dazu reason bzw. ticket_id, user_id, body.
     */
    public static function decide( array $mail ): array {
        $review = static function ( string $reason ): array {
            return [ 'action' => 'review', 'reason' => $reason ];
        };

        if ( ! is_email( $mail['from_email'] ) ) {
            return $review( 'Absender nicht eindeutig' );
        }
        if ( self::is_automated( $mail ) ) {
            return $review( 'Automatische Mail (Abwesenheit, Bounce, Newsletter)' );
        }
        if ( strtolower( self::settings()['address'] ) === $mail['from_email'] ) {
            return $review( 'Absender ist das Ticket-Postfach selbst' );
        }

        $spam_reason = self::spam_reason( $mail );
        if ( '' !== $spam_reason ) {
            return $review( $spam_reason );
        }

        $user = get_user_by( 'email', $mail['from_email'] );
        if ( ! $user || get_user_meta( $user->ID, FGR_TS_Registration::META_PENDING, true ) ) {
            return $review( 'Unbekannter Absender' );
        }
        $user_id = (int) $user->ID;

        if ( self::count_recent_imports( $mail['from_email'] ) >= self::MAX_MAILS_PER_HOUR ) {
            return $review( 'Zu viele Mails von diesem Absender in der letzten Stunde' );
        }

        $ticket_id = self::find_ticket_reference( $mail );
        if ( -1 === $ticket_id ) {
            return $review( 'Ungültige Ticket-Referenz' );
        }

        if ( $ticket_id > 0 ) {
            $ticket = FGR_TS_Ticket::get( $ticket_id );
            if ( ! $ticket ) {
                return $review( 'Ticket existiert nicht mehr' );
            }
            if ( ! FGR_TS_Capabilities::can_reply_ticket( $user_id, $ticket ) ) {
                return $review( 'Absender ist an diesem Ticket nicht beteiligt' );
            }

            $body = FGR_TS_Mail_Parser::strip_reply( $mail['text'], self::MARKER );
            if ( '' === $body ) {
                return $review( 'Antwort ohne eigenen Text' );
            }
            return [ 'action' => 'reply', 'ticket_id' => $ticket_id, 'user_id' => $user_id, 'body' => $body ];
        }

        // Agenten ohne Admin-Stufe sehen nur zugewiesene Tickets, ihr eigenes wäre für sie unsichtbar.
        if ( FGR_TS_Capabilities::is_agent( $user_id ) && ! FGR_TS_Capabilities::is_admin_tier( $user_id ) ) {
            return $review( 'Neues Ticket von einem Agenten ohne Admin-Stufe' );
        }

        // Kein Abschneiden von Zitaten: Bei Weiterleitungen ist das Zitat der eigentliche Inhalt.
        $body = trim( $mail['text'] );
        if ( '' === $body && ! $mail['attachments'] ) {
            return $review( 'Leere Mail' );
        }
        return [ 'action' => 'new', 'user_id' => $user_id, 'body' => $body ];
    }

    private static function is_automated( array $mail ): bool {
        $headers = $mail['headers'];

        $auto_submitted = strtolower( FGR_TS_Mail_Parser::header( $headers, 'auto-submitted' ) );
        if ( '' !== $auto_submitted && 0 !== strpos( $auto_submitted, 'no' ) ) {
            return true;
        }
        if ( in_array( strtolower( FGR_TS_Mail_Parser::header( $headers, 'precedence' ) ), [ 'bulk', 'junk', 'list', 'auto_reply' ], true ) ) {
            return true;
        }
        foreach ( [ 'x-autoreply', 'x-autorespond', 'list-id', 'list-unsubscribe' ] as $name ) {
            if ( isset( $headers[ $name ] ) ) {
                return true;
            }
        }
        if ( 0 === stripos( FGR_TS_Mail_Parser::header( $headers, 'content-type' ), 'multipart/report' ) ) {
            return true;
        }
        if ( '<>' === FGR_TS_Mail_Parser::header( $headers, 'return-path' ) ) {
            return true;
        }
        return (bool) preg_match( '/^(mailer-daemon|postmaster|no-?reply|do-?not-?reply)@/i', $mail['from_email'] );
    }

    /**
     * Wertet nur negative Signale aus: Einen gefälschten Fail-Header schadet
     * nur dem Fälscher selbst, ein gefälschtes "pass" wird hier nie geglaubt.
     * Das oberste Authentication-Results stammt vom eigenen Server (Rspamd).
     */
    private static function spam_reason( array $mail ): string {
        if ( 0 === stripos( FGR_TS_Mail_Parser::header( $mail['headers'], 'x-spam' ), 'yes' ) ) {
            return 'Von Rspamd als Spam markiert';
        }
        if ( preg_match( '/\b(dmarc|spf)=fail\b/i', FGR_TS_Mail_Parser::header( $mail['headers'], 'authentication-results' ), $match ) ) {
            return 'Absenderprüfung fehlgeschlagen (' . strtoupper( $match[1] ) . ')';
        }
        return '';
    }

    private static function count_recent_imports( string $sender ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . FGR_TS_Ticket::table( 'mail_log' ) . " WHERE sender = %s AND result = 'imported' AND date_created > %s",
            $sender,
            wp_date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS )
        ) );
    }

    // --- Verarbeitung ----------------------------------------------------

    /**
     * Verarbeitet eine rohe Mail. Ergebnis: action = imported|review|dry-run.
     * $forced_reason schickt die Mail ohne Prüfung in den Prüfordner (zu groß,
     * $raw enthält dann nur den Header).
     */
    public static function process( string $raw, bool $dry_run = false, string $forced_reason = '' ): array {
        $mail = FGR_TS_Mail_Parser::parse( $raw );
        $hash = hash( 'sha256', '' !== $mail['message_id'] ? $mail['from_email'] . '|' . $mail['message_id'] : $raw );

        $previous = self::log_result( $hash );
        if ( in_array( $previous, [ 'imported', 'processing' ], true ) ) {
            return [ 'action' => $dry_run ? 'dry-run' : 'review', 'decision' => 'review', 'reason' => 'Bereits verarbeitet (Duplikat)', 'ticket_id' => 0 ];
        }

        $decision = '' !== $forced_reason
            ? [ 'action' => 'review', 'reason' => $forced_reason ]
            : self::decide( $mail );

        if ( $dry_run ) {
            return [
                'action'    => 'dry-run',
                'decision'  => $decision['action'],
                'reason'    => $decision['reason'] ?? '',
                'ticket_id' => $decision['ticket_id'] ?? 0,
                'body'      => $decision['body'] ?? '',
            ];
        }

        if ( 'review' === $decision['action'] ) {
            self::write_log( $hash, $mail, 'review', $decision['reason'], 0 );
            return [ 'action' => 'review', 'reason' => $decision['reason'], 'ticket_id' => 0 ];
        }

        // Vor dem Import vormerken: Stürzt der Lauf danach ab, gibt es beim
        // nächsten Abruf kein zweites Ticket und keine zweite Benachrichtigung.
        self::write_log( $hash, $mail, 'processing', '', 0 );

        try {
            $ticket_id = self::apply( $decision, $mail );
        } catch ( Throwable $error ) {
            $reason = 'Fehler beim Import: ' . $error->getMessage();
            self::write_log( $hash, $mail, 'review', $reason, 0 );
            return [ 'action' => 'review', 'reason' => $reason, 'ticket_id' => 0 ];
        }

        self::write_log( $hash, $mail, 'imported', 'reply' === $decision['action'] ? 'Antwort' : 'Neues Ticket', $ticket_id );
        return [ 'action' => 'imported', 'reason' => '', 'ticket_id' => $ticket_id ];
    }

    private static function apply( array $decision, array $mail ): int {
        $user_id = (int) $decision['user_id'];
        $body    = sanitize_textarea_field( self::truncate( $decision['body'], self::MAX_BODY_CHARS ) );
        if ( '' === $body ) {
            $body = '(Nur Anhänge)';
        }

        if ( 'reply' === $decision['action'] ) {
            $ticket_id = (int) $decision['ticket_id'];
            $role      = FGR_TS_Capabilities::is_agent( $user_id ) ? 'agent' : 'customer';
            $thread_id = FGR_TS_Ticket::add_thread( $ticket_id, 'message', $user_id, $role, $body );
        } else {
            $subject = sanitize_text_field( self::truncate( $mail['subject'], 255 ) );
            $ticket_id = FGR_TS_Ticket::create(
                $user_id,
                '' !== $subject ? $subject : '(kein Betreff)',
                $body,
                self::default_category_id(),
                null,
                null,
                'email',
                false // keine Einwilligung abgefragt, anders als im Portal-Formular
            );
            if ( ! $ticket_id ) {
                throw new RuntimeException( 'Ticket konnte nicht angelegt werden.' );
            }
            $thread_id = (int) ( FGR_TS_Ticket::get_threads( $ticket_id )[0]['id'] ?? 0 );
        }

        // Ab hier existiert die Nachricht: Ein Anhang-Fehler darf die Mail nicht
        // in den Prüfordner schicken, sonst entstünde sie beim Freigeben doppelt.
        foreach ( $mail['attachments'] as $attachment ) {
            try {
                $error = FGR_TS_Attachment::store_content( $attachment['name'], $attachment['content'], $ticket_id, $thread_id, $user_id );
            } catch ( Throwable $exception ) {
                $error = $attachment['name'] . ': ' . $exception->getMessage();
            }
            if ( null !== $error ) {
                $role = FGR_TS_Capabilities::is_agent( $user_id ) ? 'agent' : 'customer';
                FGR_TS_Ticket::add_thread( $ticket_id, 'log', $user_id, $role, 'Anhang aus E-Mail nicht übernommen: ' . sanitize_text_field( $error ), null, false );
            }
        }

        return $ticket_id;
    }

    private static function default_category_id(): int {
        global $wpdb;
        $table = FGR_TS_Ticket::table( 'categories' );
        return (int) ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE name = %s", 'Allgemein' ) )
            ?: $wpdb->get_var( "SELECT id FROM {$table} ORDER BY sort_order ASC LIMIT 1" ) );
    }

    private static function truncate( string $value, int $length ): string {
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
    }

    private static function log_result( string $hash ): ?string {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SELECT result FROM ' . FGR_TS_Ticket::table( 'mail_log' ) . ' WHERE hash = %s', $hash ) );
    }

    private static function write_log( string $hash, array $mail, string $result, string $reason, int $ticket_id ): void {
        global $wpdb;
        $wpdb->replace( FGR_TS_Ticket::table( 'mail_log' ), [
            'hash'         => $hash,
            'sender'       => self::truncate( $mail['from_email'], 190 ),
            'subject'      => self::truncate( $mail['subject'], 190 ),
            'result'       => $result,
            'reason'       => self::truncate( $reason, 190 ),
            'ticket_id'    => $ticket_id ?: null,
            'date_created' => current_time( 'mysql' ),
        ] );
    }

    public static function recent_log( int $limit = 25 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . FGR_TS_Ticket::table( 'mail_log' ) . ' ORDER BY date_created DESC LIMIT %d', $limit ), ARRAY_A );
    }

    // --- Postfach ----------------------------------------------------------

    /** Ruft das Postfach ab. Wirft RuntimeException, wenn der Abruf selbst scheitert. */
    public static function fetch( bool $dry_run = false ): array {
        global $wpdb;

        if ( ! self::is_enabled() ) {
            throw new RuntimeException( 'E-Mail-Eingang ist nicht aktiviert.' );
        }
        if ( ! function_exists( 'imap_open' ) ) {
            throw new RuntimeException( 'Die PHP-Erweiterung imap fehlt.' );
        }

        // GET_LOCK endet mit der DB-Verbindung, bleibt also nach einem Absturz nicht hängen.
        $lock = $wpdb->prefix . 'fgr_ts_mail_fetch';
        if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
            return [ 'imported' => 0, 'review' => 0, 'skipped' => true, 'details' => [] ];
        }

        try {
            $stats = self::run_fetch( $dry_run );
            if ( ! $dry_run ) {
                self::save_status( $stats['imported'], $stats['review'], '' );
                self::purge_log();
            }
            return $stats;
        } catch ( Throwable $error ) {
            if ( ! $dry_run ) {
                self::save_status( 0, 0, $error->getMessage() );
            }
            throw $error;
        } finally {
            $wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
        }
    }

    private static function run_fetch( bool $dry_run ): array {
        $settings = self::settings();
        $server   = self::server_string( $settings );
        $stats    = [ 'imported' => 0, 'review' => 0, 'skipped' => false, 'details' => [] ];

        $imap = @imap_open( $server . 'INBOX', $settings['username'], self::decrypt_password( $settings['password'] ), 0, 1 );
        if ( ! $imap ) {
            $errors = imap_errors() ?: [];
            throw new RuntimeException( 'IMAP-Verbindung fehlgeschlagen: ' . ( end( $errors ) ?: 'unbekannter Fehler' ) );
        }

        try {
            $uids = imap_search( $imap, 'ALL', SE_UID ) ?: [];
            $uids = array_slice( $uids, 0, self::BATCH_SIZE );

            $done_folder   = '';
            $review_folder = '';
            if ( ! $dry_run && $uids ) {
                $done_folder   = self::ensure_folder( $imap, $server, self::FOLDER_DONE );
                $review_folder = self::ensure_folder( $imap, $server, self::FOLDER_REVIEW );
            }

            foreach ( $uids as $uid ) {
                $result = self::process_uid( $imap, (int) $uid, $dry_run );
                $stats['details'][] = $result + [ 'uid' => (int) $uid ];

                if ( $dry_run ) {
                    continue;
                }
                if ( 'imported' === $result['action'] ) {
                    $stats['imported']++;
                    $target = $done_folder;
                } else {
                    $stats['review']++;
                    $target = $review_folder;
                }
                // Scheitert das Verschieben, bleibt die Mail im Eingang. Der
                // Log-Eintrag verhindert dann einen zweiten Import.
                if ( ! imap_mail_move( $imap, (string) $uid, $target, CP_UID ) ) {
                    throw new RuntimeException( 'Mail konnte nicht nach ' . $target . ' verschoben werden.' );
                }
            }
        } finally {
            if ( ! $dry_run ) {
                imap_expunge( $imap );
            }
            imap_errors();
            imap_alerts();
            imap_close( $imap );
        }

        return $stats;
    }

    private static function process_uid( $imap, int $uid, bool $dry_run ): array {
        $overview = imap_fetch_overview( $imap, (string) $uid, FT_UID )[0] ?? null;
        $header   = (string) imap_fetchheader( $imap, $uid, FT_UID );

        if ( $overview && (int) $overview->size > self::MAX_MESSAGE_BYTES ) {
            return self::process( $header, $dry_run, 'Mail ist größer als 40 MB' );
        }

        // FT_PEEK: Bleibt die Mail wegen eines Fehlers im Eingang, gilt sie nicht als gelesen.
        return self::process( $header . imap_body( $imap, $uid, FT_UID | FT_PEEK ), $dry_run );
    }

    private static function server_string( array $settings ): string {
        $host  = (string) preg_replace( '/[^a-zA-Z0-9.\-]/', '', $settings['host'] );
        $flags = 'ssl' === $settings['encryption'] ? '/imap/ssl' : '/imap/tls';
        return sprintf( '{%s:%d%s}', $host, (int) $settings['port'], $flags );
    }

    /**
     * Dovecot legt Ordner je nach Namespace als "Name" oder "INBOX.Name" an.
     * Statt das vorauszusetzen, wird ein vorhandener Ordner gesucht und sonst
     * beide Varianten probiert. Gibt den Namen ohne Serverteil zurück.
     */
    private static function ensure_folder( $imap, string $server, string $name ): string {
        $existing = array_map(
            static function ( $full_name ) use ( $server ) {
                return substr( $full_name, strlen( $server ) );
            },
            imap_list( $imap, $server, '*' ) ?: []
        );

        $candidates = [ 'INBOX.' . $name, $name ];
        foreach ( $candidates as $candidate ) {
            if ( in_array( $candidate, $existing, true ) ) {
                return $candidate;
            }
        }
        foreach ( $candidates as $candidate ) {
            if ( @imap_createmailbox( $imap, $server . $candidate ) ) {
                imap_errors();
                return $candidate;
            }
        }
        imap_errors();
        throw new RuntimeException( 'Ordner ' . $name . ' konnte nicht angelegt werden.' );
    }

    private static function save_status( int $imported, int $review, string $error ): void {
        update_option( self::STATUS_OPTION, [
            'time'     => current_time( 'mysql' ),
            'imported' => $imported,
            'review'   => $review,
            'error'    => $error,
        ], false );
    }

    private static function purge_log(): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            'DELETE FROM ' . FGR_TS_Ticket::table( 'mail_log' ) . ' WHERE date_created < %s',
            wp_date( 'Y-m-d H:i:s', time() - self::LOG_RETENTION_DAYS * DAY_IN_SECONDS )
        ) );
    }

    // --- WP-CLI --------------------------------------------------------

    /**
     * Ruft das Ticket-Postfach ab.
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Nur anzeigen, was passieren würde. Schreibt nichts, verschiebt nichts.
     */
    public static function cli_fetch( $args, $assoc_args ): void {
        $dry_run = ! empty( $assoc_args['dry-run'] );
        try {
            $stats = self::fetch( $dry_run );
        } catch ( Throwable $error ) {
            WP_CLI::error( $error->getMessage() );
            return;
        }

        if ( $stats['skipped'] ) {
            WP_CLI::log( 'Ein anderer Abruf läuft noch, übersprungen.' );
            return;
        }
        foreach ( $stats['details'] as $detail ) {
            WP_CLI::log( sprintf( 'UID %d: %s %s %s', $detail['uid'], $detail['decision'] ?? $detail['action'], $detail['ticket_id'] ? '#' . $detail['ticket_id'] : '', $detail['reason'] ) );
        }
        WP_CLI::log( sprintf( 'Importiert: %d, Prüfordner: %d', $stats['imported'], $stats['review'] ) );
    }

    /**
     * Verarbeitet eine .eml-Datei wie eine Mail aus dem Postfach (zum Testen, ohne Postfach).
     *
     * ## OPTIONS
     *
     * <datei>
     * : Pfad zur .eml-Datei.
     *
     * [--dry-run]
     * : Nur die Entscheidung anzeigen, nichts schreiben.
     */
    public static function cli_process_eml( $args, $assoc_args ): void {
        $raw = file_get_contents( $args[0] );
        if ( false === $raw ) {
            WP_CLI::error( 'Datei nicht lesbar: ' . $args[0] );
            return;
        }
        WP_CLI::print_value( self::process( $raw, ! empty( $assoc_args['dry-run'] ) ), [ 'format' => 'json' ] );
    }
}
