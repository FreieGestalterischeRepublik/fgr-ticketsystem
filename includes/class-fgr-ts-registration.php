<?php
defined( 'ABSPATH' ) || exit;

/**
 * Double-Opt-in für die Kunden-Selbstregistrierung (siehe FGR_TS_Frontend::
 * handle_register()): Konto wird sofort angelegt, aber als "unbestätigt"
 * markiert und kann sich nicht einloggen, bis der Link in der
 * Bestätigungs-Mail angeklickt wurde. Wird der Link nicht innerhalb von
 * 30 Minuten angeklickt, wird das Konto automatisch wieder gelöscht
 * (Cron-Job alle 10 Minuten, siehe register_activation_hook in der
 * Hauptdatei - "hourly" wäre bei einer 30-Minuten-Frist zu grob).
 */
class FGR_TS_Registration {

    const META_PENDING = 'fgr_ts_pending_confirmation';
    const META_TOKEN    = 'fgr_ts_confirm_token';
    const META_EXPIRES  = 'fgr_ts_confirm_expires';
    const CRON_HOOK      = 'fgr_ts_cleanup_unconfirmed';
    const CRON_INTERVAL  = 'fgr_ts_every_10_min';
    const EXPIRY_SECONDS = 30 * MINUTE_IN_SECONDS;

    public function __construct() {
        add_filter( 'cron_schedules', [ $this, 'register_cron_interval' ] );
        add_action( 'template_redirect', [ $this, 'maybe_confirm' ] );
        add_filter( 'authenticate', [ $this, 'block_unconfirmed_login' ], 30, 1 );
        add_action( self::CRON_HOOK, [ $this, 'cleanup_unconfirmed' ] );
    }

    public function register_cron_interval( array $schedules ): array {
        $schedules[ self::CRON_INTERVAL ] = [
            'interval' => 10 * MINUTE_IN_SECONDS,
            'display'  => 'Alle 10 Minuten (FGR Ticketsystem)',
        ];
        return $schedules;
    }

    public static function activate(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
        }
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Legt das Konto als unbestätigt an (statt es sofort aktiv zu
     * schalten) und verschickt die Bestätigungs-Mail. Gibt die neue
     * User-ID zurück.
     */
    public static function register_pending( int $user_id ): void {
        $token = wp_generate_password( 32, false );
        update_user_meta( $user_id, self::META_PENDING, 1 );
        update_user_meta( $user_id, self::META_TOKEN, $token );
        update_user_meta( $user_id, self::META_EXPIRES, time() + self::EXPIRY_SECONDS );

        $user = get_userdata( $user_id );
        $link = add_query_arg( 'fgr_ts_confirm', $token, get_permalink( (int) get_option( 'fgr_ts_portal_page_id', 0 ) ) );

        wp_mail(
            $user->user_email,
            'Bitte bestätige deine E-Mail-Adresse',
            "Hallo {$user->display_name},\n\nbitte bestätige deine E-Mail-Adresse, um dein Konto zu aktivieren:\n\n{$link}\n\nDer Link ist 30 Minuten gültig. Falls du dich nicht registriert hast, kannst du diese E-Mail ignorieren - das Konto wird dann automatisch wieder gelöscht."
        );
    }

    /**
     * Wird aufgerufen, wenn sich jemand mit einer E-Mail-Adresse registrieren
     * will, zu der schon ein Konto existiert (siehe FGR_TS_Frontend::
     * handle_register() - bewusst KEINE Fehlermeldung an den Absender, sonst
     * ließe sich darüber durchprobieren, welche E-Mail-Adressen ein Konto
     * haben). Der tatsächliche Kontoinhaber bekommt stattdessen einen
     * Hinweis, falls der Registrierungsversuch nicht von ihm selbst war.
     */
    public static function notify_existing_account( string $email ): void {
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return;
        }
        wp_mail(
            $email,
            'Registrierungsversuch mit deiner E-Mail-Adresse',
            "Hallo {$user->display_name},\n\njemand hat versucht, sich mit deiner E-Mail-Adresse neu zu registrieren - du hast aber bereits ein Konto. Falls das du warst, melde dich einfach ganz normal an. Falls du dein Passwort vergessen hast, kannst du es über \"Passwort vergessen\" beim Anmelden zurücksetzen.\n\nFalls du das nicht warst, kannst du diese E-Mail ignorieren - es wurde nichts an deinem Konto verändert."
        );
    }

    public static function is_pending( int $user_id ): bool {
        return (bool) get_user_meta( $user_id, self::META_PENDING, true );
    }

    /** Verarbeitet einen Klick auf den Bestätigungslink (?fgr_ts_confirm=TOKEN). */
    public function maybe_confirm(): void {
        if ( empty( $_GET['fgr_ts_confirm'] ) ) {
            return;
        }
        $portal_page_id = (int) get_option( 'fgr_ts_portal_page_id', 0 );
        if ( ! $portal_page_id || ! is_page( $portal_page_id ) ) {
            return;
        }

        $token = sanitize_text_field( wp_unslash( $_GET['fgr_ts_confirm'] ) );
        $users = get_users( [
            'meta_key'   => self::META_TOKEN,
            'meta_value' => $token,
            'number'     => 1,
        ] );
        $user = $users[0] ?? null;

        $base_url = get_permalink( $portal_page_id );

        if ( ! $user || ! self::is_pending( $user->ID ) ) {
            wp_safe_redirect( add_query_arg( 'confirm_error', '1', $base_url ) );
            exit;
        }

        $expires = (int) get_user_meta( $user->ID, self::META_EXPIRES, true );
        if ( $expires < time() ) {
            // Abgelaufen - Aufräum-Cron hätte es sowieso bald gelöscht.
            wp_delete_user( $user->ID );
            wp_safe_redirect( add_query_arg( 'confirm_error', 'expired', $base_url ) );
            exit;
        }

        delete_user_meta( $user->ID, self::META_PENDING );
        delete_user_meta( $user->ID, self::META_TOKEN );
        delete_user_meta( $user->ID, self::META_EXPIRES );

        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID );

        wp_safe_redirect( $base_url );
        exit;
    }

    /** Verhindert den Login, solange das Konto nicht bestätigt wurde. */
    public function block_unconfirmed_login( $user ) {
        if ( $user instanceof WP_User && self::is_pending( $user->ID ) ) {
            return new WP_Error( 'fgr_ts_unconfirmed', 'Bitte bestätige zuerst deine E-Mail-Adresse. Wir haben dir dafür einen Link per E-Mail geschickt.' );
        }
        return $user;
    }

    /** Cron (alle 10 Min.): löscht Konten, deren 30-Minuten-Bestätigungsfrist abgelaufen ist. */
    public function cleanup_unconfirmed(): void {
        $users = get_users( [
            'meta_key' => self::META_PENDING,
            'meta_value' => 1,
        ] );
        foreach ( $users as $user ) {
            $expires = (int) get_user_meta( $user->ID, self::META_EXPIRES, true );
            if ( $expires && $expires < time() ) {
                wp_delete_user( $user->ID );
            }
        }
    }
}
