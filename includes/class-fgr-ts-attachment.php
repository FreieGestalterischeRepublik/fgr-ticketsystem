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

    public static function filter_upload_dir( array $dirs ): array {
        $sub = '/fgr-ticketsystem/' . current_time( 'Y' ) . '/' . current_time( 'm' );
        $dirs['path']   = $dirs['basedir'] . $sub;
        $dirs['url']    = $dirs['baseurl'] . $sub;
        $dirs['subdir'] = $sub;
        return $dirs;
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
