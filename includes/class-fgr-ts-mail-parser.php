<?php
defined( 'ABSPATH' ) || exit;

/**
 * Zerlegt eine rohe E-Mail (RFC 5322/MIME) in Absender, Betreff, Text und
 * Anhänge. Bewusst reines PHP ohne ext-imap/ext-mailparse: so lässt sich der
 * Parser auch ohne Postfach mit .eml-Dateien testen (wp fgr-ts process-eml).
 */
class FGR_TS_Mail_Parser {

    const MAX_DEPTH = 10;

    /** Inline-Bilder unter dieser Größe gelten als Signatur-Logo und werden nicht übernommen. */
    const MIN_INLINE_IMAGE_BYTES = 51200;

    /** Zitierweisen gängiger Mailprogramme. */
    const QUOTE_PATTERNS = [
        '/^[> ]*(Am|On)\s[^\n]{0,200}(\n[^\n]{0,200})?(schrieb|wrote)[^\n]*:[ \t]*$/mu',
        '/^[> ]*-{2,}\s*(Ursprüngliche Nachricht|Original Message)/miu',
        '/^[> ]*(Von|From):[^\n]*\n[> ]*(Gesendet|Sent|Datum|Date|An|To):/miu',
        '/^_{10,}[ \t]*$/m',
    ];

    const FORWARD_PATTERN = '/^[> ]*(-{2,}\s*(Weitergeleitete Nachricht|Forwarded Message)|Begin forwarded message|Anfang der weitergeleiteten Nachricht)/miu';

    /** Signatur-Trennzeile: "--", auch mit Leerzeichen dahinter (\x{00A0} aus HTML-Editoren). */
    const SIGNATURE_PATTERN = '/^--[ \t\x{00A0}]*$/mu';

    public static function parse( string $raw ): array {
        [ $header_block, $body ] = self::split_entity( $raw );
        $headers = self::parse_headers( $header_block );

        $parts = [ 'text' => null, 'html' => null, 'attachments' => [] ];
        self::walk( $headers, $body, $parts, 0 );

        $text = $parts['text'];
        if ( null === $text || '' === trim( $text ) ) {
            $text = null !== $parts['html'] ? self::html_to_text( $parts['html'] ) : '';
        }

        $from = self::parse_from( self::header( $headers, 'from' ) );

        return [
            'headers'     => $headers,
            'message_id'  => trim( self::header( $headers, 'message-id' ), " \t<>" ),
            'from_email'  => $from['email'],
            'from_name'   => $from['name'],
            'subject'     => trim( self::decode_header( self::header( $headers, 'subject' ) ) ),
            'text'        => str_replace( "\r\n", "\n", $text ),
            'attachments' => $parts['attachments'],
        ];
    }

    /** Erster Wert eines Headers (Name kleingeschrieben), '' wenn nicht vorhanden. */
    public static function header( array $headers, string $name ): string {
        return $headers[ $name ][0] ?? '';
    }

    /**
     * Schneidet zitierte Vorgänger-Mails und die Signatur ab. $marker ist die
     * Trennzeile aus unseren Benachrichtigungen, die restlichen Muster fangen
     * die Zitierweisen gängiger Mailprogramme ab, falls die Zeile fehlt.
     */
    public static function strip_reply( string $text, string $marker ): string {
        $text = str_replace( "\r\n", "\n", $text );

        $pos = strpos( $text, $marker );
        if ( false !== $pos ) {
            $line_start = strrpos( substr( $text, 0, $pos ), "\n" );
            $text       = false === $line_start ? '' : substr( $text, 0, $line_start );
        }

        foreach ( array_merge( self::QUOTE_PATTERNS, [ self::SIGNATURE_PATTERN ] ) as $pattern ) {
            if ( preg_match( $pattern, $text, $match, PREG_OFFSET_CAPTURE ) ) {
                $text = substr( $text, 0, $match[0][1] );
            }
        }

        // Zitatzeilen ganz am Ende entfernen, Antworten zwischen Zitaten bleiben stehen.
        $lines = explode( "\n", $text );
        while ( $lines && preg_match( '/^(\s*|>.*)$/', end( $lines ) ) ) {
            array_pop( $lines );
        }

        return trim( implode( "\n", $lines ) );
    }

    /**
     * Für neue Tickets: Entfernt nur die Signatur des Absenders. Zitate bleiben,
     * weil bei Weiterleitungen das Zitat der eigentliche Inhalt ist. Eine
     * "--"-Zeile hinter dem Beginn des Zitats gehört zum Zitat und bleibt stehen.
     */
    public static function strip_signature( string $text ): string {
        $text = str_replace( "\r\n", "\n", $text );

        if ( ! preg_match( self::SIGNATURE_PATTERN, $text, $match, PREG_OFFSET_CAPTURE ) ) {
            return trim( $text );
        }
        $signature = $match[0][1];

        $quote = self::first_quote_offset( $text );
        if ( null !== $quote && $quote < $signature ) {
            return trim( $text );
        }

        $own_text = rtrim( substr( $text, 0, $signature ) );
        if ( null === $quote ) {
            return trim( $own_text );
        }
        return trim( $own_text . "\n\n" . substr( $text, $quote ) );
    }

    private static function first_quote_offset( string $text ): ?int {
        $first = null;
        foreach ( array_merge( self::QUOTE_PATTERNS, [ self::FORWARD_PATTERN ] ) as $pattern ) {
            if ( preg_match( $pattern, $text, $match, PREG_OFFSET_CAPTURE ) && ( null === $first || $match[0][1] < $first ) ) {
                $first = $match[0][1];
            }
        }
        return $first;
    }

    // ---------------------------------------------------------------------

    private static function walk( array $headers, string $body, array &$parts, int $depth ): void {
        if ( $depth > self::MAX_DEPTH ) {
            return;
        }

        $content_type = self::header( $headers, 'content-type' );
        $type         = strtolower( trim( explode( ';', $content_type )[0] ) );
        if ( '' === $type ) {
            $type = 'text/plain';
        }
        $type_params = self::parse_params( $content_type );

        if ( 0 === strpos( $type, 'multipart/' ) ) {
            $boundary = $type_params['boundary'] ?? '';
            if ( '' === $boundary ) {
                return;
            }
            foreach ( self::split_multipart( $body, $boundary ) as $part ) {
                [ $part_header_block, $part_body ] = self::split_entity( $part );
                self::walk( self::parse_headers( $part_header_block ), $part_body, $parts, $depth + 1 );
            }
            return;
        }

        $disposition        = self::header( $headers, 'content-disposition' );
        $disposition_type   = strtolower( trim( explode( ';', $disposition )[0] ) );
        $disposition_params = self::parse_params( $disposition );
        $filename           = $disposition_params['filename'] ?? ( $type_params['name'] ?? '' );
        $content            = self::decode_transfer( $body, self::header( $headers, 'content-transfer-encoding' ) );

        $is_body_text = in_array( $type, [ 'text/plain', 'text/html' ], true ) && 'attachment' !== $disposition_type && '' === $filename;
        if ( $is_body_text ) {
            $charset = $type_params['charset'] ?? 'UTF-8';
            $key     = 'text/plain' === $type ? 'text' : 'html';
            if ( null === $parts[ $key ] ) {
                $parts[ $key ] = self::to_utf8( $content, $charset );
            }
            return;
        }

        if ( 'message/rfc822' === $type && '' === $filename ) {
            $filename = 'weitergeleitete-nachricht.eml';
        }
        if ( '' === $filename ) {
            return; // z.B. text/calendar ohne Dateiname: kein Anhang, den jemand erwartet
        }

        $is_inline_image = 0 === strpos( $type, 'image/' )
            && 'attachment' !== $disposition_type
            && '' !== self::header( $headers, 'content-id' );
        if ( $is_inline_image && strlen( $content ) < self::MIN_INLINE_IMAGE_BYTES ) {
            return;
        }

        $parts['attachments'][] = [ 'name' => $filename, 'content' => $content ];
    }

    /** Trennt Header und Inhalt einer Mail oder eines MIME-Teils. */
    private static function split_entity( string $raw ): array {
        if ( preg_match( '/^\r?\n/', $raw, $match ) ) {
            return [ '', substr( $raw, strlen( $match[0] ) ) ];
        }
        $split = preg_split( '/\r?\n\r?\n/', $raw, 2 );
        return [ $split[0], $split[1] ?? '' ];
    }

    private static function parse_headers( string $block ): array {
        $unfolded = preg_replace( '/\r?\n[ \t]+/', ' ', $block );
        $headers  = [];
        foreach ( preg_split( '/\r?\n/', (string) $unfolded ) as $line ) {
            $colon = strpos( $line, ':' );
            if ( false === $colon || 0 === $colon ) {
                continue;
            }
            $headers[ strtolower( substr( $line, 0, $colon ) ) ][] = trim( substr( $line, $colon + 1 ) );
        }
        return $headers;
    }

    /**
     * Teilt einen Multipart-Inhalt zeilenweise statt per Regex: PCRE scheitert
     * an großen Anhängen sonst am Backtrack-Limit.
     */
    private static function split_multipart( string $body, string $boundary ): array {
        $delimiter = '--' . $boundary;
        $length    = strlen( $delimiter );
        $parts     = [];
        $start     = null;
        $offset    = 0;

        while ( false !== ( $pos = strpos( $body, $delimiter, $offset ) ) ) {
            $offset = $pos + $length;
            if ( 0 !== $pos && "\n" !== $body[ $pos - 1 ] ) {
                continue;
            }
            $is_closing = '--' === substr( $body, $pos + $length, 2 );

            if ( null !== $start ) {
                $end = $pos - 1;
                if ( $end > 0 && "\r" === $body[ $end - 1 ] ) {
                    $end--;
                }
                $parts[] = substr( $body, $start, max( 0, $end - $start ) );
            }
            if ( $is_closing ) {
                return $parts;
            }

            $line_end = strpos( $body, "\n", $pos );
            if ( false === $line_end ) {
                return $parts;
            }
            $start = $line_end + 1;
        }

        // Abschluss-Trennzeile fehlt (abgeschnittene Mail): Rest trotzdem verwenden.
        if ( null !== $start ) {
            $parts[] = substr( $body, $start );
        }
        return $parts;
    }

    /** Parameter eines Headers wie Content-Type, inkl. RFC-2231-Form (filename*=UTF-8''...). */
    private static function parse_params( string $header ): array {
        preg_match_all( '/;\s*([\w\-*]+)\s*=\s*("(?:[^"\\\\]|\\\\.)*"|[^;\s]+)/', $header, $matches, PREG_SET_ORDER );

        $plain    = [];
        $extended = [];
        foreach ( $matches as $match ) {
            $key   = strtolower( $match[1] );
            $value = $match[2];
            if ( '"' === ( $value[0] ?? '' ) ) {
                $value = stripslashes( substr( $value, 1, -1 ) );
            }

            if ( preg_match( '/^(.+?)\*(\d+)?(\*)?$/', $key, $key_match ) ) {
                $extended[ $key_match[1] ][ (int) ( $key_match[2] ?? 0 ) ] = [ $value, isset( $key_match[3] ) || ! isset( $key_match[2] ) || '' === $key_match[2] ];
            } else {
                $plain[ $key ] = self::decode_header( $value );
            }
        }

        foreach ( $extended as $key => $segments ) {
            ksort( $segments );
            $charset = 'UTF-8';
            $joined  = '';
            foreach ( $segments as $index => $segment ) {
                [ $value, $is_encoded ] = $segment;
                if ( $is_encoded ) {
                    if ( 0 === $index && 2 === substr_count( $value, "'" ) ) {
                        [ $charset, , $value ] = explode( "'", $value, 3 );
                    }
                    $value = rawurldecode( $value );
                }
                $joined .= $value;
            }
            $plain[ $key ] = self::to_utf8( $joined, '' !== $charset ? $charset : 'UTF-8' );
        }

        return $plain;
    }

    /**
     * Genau eine Adresse oder gar keine: Ein From mit mehreren Adressen ist
     * legal, aber nicht eindeutig zuzuordnen. Quoted Strings werden zuerst
     * entfernt, damit "kunde@firma.de" <angreifer@x.de> als angreifer@x.de zählt.
     */
    private static function parse_from( string $value ): array {
        $without_quoted = (string) preg_replace( '/"(?:[^"\\\\]|\\\\.)*"/', '""', $value );

        if ( substr_count( $without_quoted, '@' ) !== 1 ) {
            return [ 'email' => '', 'name' => '' ];
        }

        if ( preg_match( '/<([^<>\s]+@[^<>\s]+)>/', $without_quoted, $match ) ) {
            $email = $match[1];
            $name  = trim( (string) preg_replace( '/<[^>]*>.*$/s', '', $value ), " \t\"" );
        } elseif ( preg_match( '/[^\s<>,;]+@[^\s<>,;]+/', $without_quoted, $match ) ) {
            $email = $match[0];
            $name  = '';
        } else {
            return [ 'email' => '', 'name' => '' ];
        }

        return [ 'email' => strtolower( $email ), 'name' => self::decode_header( $name ) ];
    }

    private static function decode_transfer( string $body, string $encoding ): string {
        $encoding = strtolower( trim( $encoding ) );
        if ( 'base64' === $encoding ) {
            return (string) base64_decode( $body );
        }
        if ( 'quoted-printable' === $encoding ) {
            return quoted_printable_decode( $body );
        }
        return $body;
    }

    private static function decode_header( string $value ): string {
        if ( false === strpos( $value, '=?' ) ) {
            return self::to_utf8( $value, 'UTF-8' );
        }
        $decoded = function_exists( 'iconv_mime_decode' )
            ? @iconv_mime_decode( $value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' )
            : false;
        if ( false === $decoded && function_exists( 'mb_decode_mimeheader' ) ) {
            $decoded = mb_decode_mimeheader( $value );
        }
        return self::to_utf8( false === $decoded ? $value : $decoded, 'UTF-8' );
    }

    private static function to_utf8( string $value, string $charset ): string {
        $charset = strtoupper( trim( $charset ) );
        if ( ! in_array( $charset, [ '', 'UTF-8', 'UTF8', 'US-ASCII', 'ASCII' ], true ) && function_exists( 'iconv' ) ) {
            $converted = @iconv( $charset, 'UTF-8//IGNORE', $value );
            if ( false !== $converted ) {
                $value = $converted;
            }
        }
        // Ungültige Bytes würden sonst das INSERT in die utf8mb4-Tabelle scheitern lassen.
        return function_exists( 'mb_scrub' ) ? mb_scrub( $value, 'UTF-8' ) : $value;
    }

    private static function html_to_text( string $html ): string {
        $without_noise = preg_replace( '#<(head|style|script)\b.*?</\1>#is', '', $html );
        if ( null === $without_noise ) {
            $without_noise = $html; // PCRE-Limit bei sehr großem HTML
        }

        do {
            $previous      = $without_noise;
            $without_noise = (string) preg_replace( '#<blockquote\b(?:(?!<blockquote\b).)*?</blockquote>#is', '', $previous );
        } while ( $without_noise !== $previous && '' !== $without_noise );

        $with_breaks = preg_replace( '#<br\s*/?>|</(p|div|tr|li|h[1-6])>#i', "\n", $without_noise );
        $text        = html_entity_decode( strip_tags( (string) $with_breaks ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        return trim( (string) preg_replace( "/\n{3,}/", "\n\n", str_replace( [ "\r\n", "\xC2\xA0" ], [ "\n", ' ' ], $text ) ) );
    }
}
