<?php
defined( 'ABSPATH' ) || exit;

/**
 * Datei-Upload für Ticket-Nachrichten. Gleiche Grenzen wie bisher in
 * SupportCandy konfiguriert (siehe Planungs-Notizen): 20 MB, feste Liste
 * erlaubter Dateitypen. Dateien landen unter einem eigenen Unterordner
 * (wp-content/uploads/fgr-ticketsystem/JJJJ/MM/), damit sie getrennt von
 * den alten SupportCandy-Anhängen (.../uploads/wpsc/...) bleiben.
 */
class FGR_TS_Attachment {

    public static function init(): void {
        add_action( 'admin_post_fgr_ts_download', [ __CLASS__, 'handle_download' ] );
        self::protect_existing_dirs();
    }

    /**
     * Ergänzt die .htaccess-Sperre auch in bereits vorhandenen
     * Jahr/Monat-Ordnern (z.B. durch schon hochgeladene Dateien, bevor
     * dieser Schutz eingebaut wurde) - ensure_dir_protected() in
     * filter_upload_dir() greift sonst nur bei neuen Uploads. Läuft bei
     * jedem Laden, ist aber durch den file_exists()-Check in
     * ensure_dir_protected() billig.
     */
    private static function protect_existing_dirs(): void {
        $base = wp_get_upload_dir()['basedir'] . '/fgr-ticketsystem';
        if ( ! is_dir( $base ) ) {
            return;
        }
        foreach ( glob( $base . '/*/*', GLOB_ONLYDIR ) ?: [] as $month_dir ) {
            self::ensure_dir_protected( $month_dir );
        }
    }

    const MAX_SIZE_BYTES = 20 * 1024 * 1024; // 20 MB

    private static function allowed_mimes(): array {
        return [
            'jpg|jpeg' => 'image/jpeg',
            'png'      => 'image/png',
            'gif'      => 'image/gif',
            'pdf'      => 'application/pdf',
            'doc'      => 'application/msword',
            'docx'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'ppt'      => 'application/vnd.ms-powerpoint',
            'pptx'     => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'pps'      => 'application/vnd.ms-powerpoint',
            'ppsx'     => 'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
            'odt'      => 'application/vnd.oasis.opendocument.text',
            'xls'      => 'application/vnd.ms-excel',
            'xlsx'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'mp3'      => 'audio/mpeg',
            'm4a'      => 'audio/mp4',
            'ogg'      => 'audio/ogg',
            'wav'      => 'audio/x-wav',
            'mp4'      => 'video/mp4',
            'm4v'      => 'video/x-m4v',
            'mov'      => 'video/quicktime',
            'wmv'      => 'video/x-ms-wmv',
            'avi'      => 'video/x-msvideo',
            'mpg'      => 'video/mpeg',
            'ogv'      => 'video/ogg',
            '3gp'      => 'video/3gpp',
            '3g2'      => 'video/3gpp2',
            'zip'      => 'application/zip',
            'eml'      => 'message/rfc822',
        ];
    }

    /**
     * Verarbeitet die Dateien eines $_FILES-Feldes (z.B. $_FILES['attachments'],
     * mit name="attachments[]" im Formular). Gibt eine Liste von
     * Fehlermeldungen zurück (leer = alles hochgeladen).
     */
    public static function handle_uploads( array $files_field, int $ticket_id, ?int $thread_id, int $uploaded_by ): array {
        $errors = [];
        foreach ( self::reshape_files_array( $files_field ) as $file ) {
            if ( UPLOAD_ERR_NO_FILE === $file['error'] ) {
                continue; // leeres Feld, z.B. wenn nur 2 von 5 Datei-Inputs genutzt wurden
            }
            if ( UPLOAD_ERR_OK !== $file['error'] ) {
                $errors[] = sprintf( '%s: Upload-Fehler.', $file['name'] );
                continue;
            }
            if ( $file['size'] > self::MAX_SIZE_BYTES ) {
                $errors[] = sprintf( '%s: Datei ist größer als 20 MB.', $file['name'] );
                continue;
            }

            add_filter( 'upload_dir', [ __CLASS__, 'filter_upload_dir' ] );
            $result = wp_handle_upload( $file, [
                'test_form' => false,
                'mimes'     => self::allowed_mimes(),
            ] );
            remove_filter( 'upload_dir', [ __CLASS__, 'filter_upload_dir' ] );

            if ( isset( $result['error'] ) ) {
                $errors[] = sprintf( '%s: %s', $file['name'], $result['error'] );
                continue;
            }

            global $wpdb;
            $relative_path = str_replace( wp_get_upload_dir()['basedir'], '', $result['file'] );
            $wpdb->insert( FGR_TS_Ticket::table( 'attachments' ), [
                'thread_id'    => $thread_id,
                'ticket_id'    => $ticket_id,
                'file_path'    => $relative_path,
                'file_name'    => $file['name'],
                'uploaded_by'  => $uploaded_by,
                'date_created' => current_time( 'mysql' ),
            ] );
        }
        return $errors;
    }

    /** Löscht alle Anhänge eines Tickets - DB-Zeilen UND die Dateien selbst. */
    public static function delete_for_ticket( int $ticket_id ): void {
        global $wpdb;
        $basedir = wp_get_upload_dir()['basedir'];
        $rows    = $wpdb->get_results( $wpdb->prepare(
            'SELECT file_path FROM ' . FGR_TS_Ticket::table( 'attachments' ) . ' WHERE ticket_id = %d', $ticket_id
        ), ARRAY_A );

        foreach ( $rows as $row ) {
            $full_path = $basedir . $row['file_path'];
            if ( is_file( $full_path ) ) {
                @unlink( $full_path );
            }
        }

        $wpdb->delete( FGR_TS_Ticket::table( 'attachments' ), [ 'ticket_id' => $ticket_id ] );
    }

    public static function filter_upload_dir( array $dirs ): array {
        $sub = '/fgr-ticketsystem/' . current_time( 'Y' ) . '/' . current_time( 'm' );
        $dirs['path']   = $dirs['basedir'] . $sub;
        $dirs['url']    = $dirs['baseurl'] . $sub;
        $dirs['subdir'] = $sub;

        // Der Upload-Ordner liegt unter wp-content/uploads und wäre ohne
        // Rechteprüfung direkt per URL abrufbar (Tickets können persönliche
        // Daten enthalten) - daher pro Monatsordner eine .htaccess anlegen,
        // die den direkten Zugriff sperrt. Anhänge werden stattdessen nur
        // noch über download_url()/handle_download() ausgeliefert, die
        // FGR_TS_Capabilities::can_view_ticket() prüfen. Greift nur unter
        // Apache (mod_authz_core/mod_access_compat) - die eigentliche
        // Absicherung ist der PHP-Download-Handler, das .htaccess ist
        // zusätzliche Tiefenverteidigung.
        self::ensure_dir_protected( $dirs['path'] );

        return $dirs;
    }

    private static function ensure_dir_protected( string $dir ): void {
        $htaccess = $dir . '/.htaccess';
        if ( file_exists( $htaccess ) ) {
            return;
        }
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        file_put_contents( $htaccess, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
    }

    /** Download-Link für einen Anhang - läuft über handle_download(), nicht über die direkte Upload-URL. */
    public static function download_url( array $attachment ): string {
        $attachment_id = (int) $attachment['id'];
        return add_query_arg(
            [
                'action'        => 'fgr_ts_download',
                'attachment_id' => $attachment_id,
                '_wpnonce'      => wp_create_nonce( 'fgr_ts_download_' . $attachment_id ),
            ],
            admin_url( 'admin-post.php' )
        );
    }

    /**
     * Liefert eine Datei nur aus, wenn der eingeloggte Benutzer laut
     * FGR_TS_Capabilities::can_view_ticket() auf das zugehörige Ticket
     * zugreifen darf - verhindert, dass Anhänge über die rohe Upload-URL
     * ohne jede Rechteprüfung abrufbar sind.
     */
    public static function handle_download(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( 'Bitte zuerst anmelden.' );
        }

        $attachment_id = (int) ( $_GET['attachment_id'] ?? 0 );
        if ( ! $attachment_id || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'fgr_ts_download_' . $attachment_id ) ) {
            wp_die( 'Ungültige oder abgelaufene Anfrage.' );
        }

        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . FGR_TS_Ticket::table( 'attachments' ) . ' WHERE id = %d', $attachment_id
        ), ARRAY_A );
        if ( ! $row ) {
            wp_die( 'Datei nicht gefunden.' );
        }

        $ticket = FGR_TS_Ticket::get( (int) $row['ticket_id'] );
        if ( ! $ticket || ! FGR_TS_Capabilities::can_view_ticket( get_current_user_id(), $ticket ) ) {
            wp_die( 'Keine Berechtigung.' );
        }

        $full_path = wp_get_upload_dir()['basedir'] . $row['file_path'];
        if ( ! is_file( $full_path ) ) {
            wp_die( 'Datei nicht gefunden.' );
        }

        nocache_headers();
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $row['file_name'] ) . '"' );
        header( 'Content-Length: ' . filesize( $full_path ) );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $full_path );
        exit;
    }

    /** Bringt $_FILES['feld'] (mit [] im Namen, mehrere Dateien) in eine Liste einzelner Datei-Arrays. */
    private static function reshape_files_array( array $files_field ): array {
        if ( ! is_array( $files_field['name'] ) ) {
            return [ $files_field ];
        }
        $out = [];
        foreach ( $files_field['name'] as $i => $name ) {
            if ( $name === '' ) {
                continue;
            }
            $out[] = [
                'name'     => $files_field['name'][ $i ],
                'type'     => $files_field['type'][ $i ],
                'tmp_name' => $files_field['tmp_name'][ $i ],
                'error'    => $files_field['error'][ $i ],
                'size'     => $files_field['size'][ $i ],
            ];
        }
        return $out;
    }
}
