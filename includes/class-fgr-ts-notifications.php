<?php
defined( 'ABSPATH' ) || exit;

/**
 * E-Mail-Benachrichtigungen. Ist der E-Mail-Eingang aktiv (siehe FGR_TS_Mail),
 * tragen sie zusätzlich Reply-To, Antwort-Trennzeile und Ticket-Referenz.
 *
 * Regeln (wie mit Marc abgesprochen):
 * - Neues Ticket (noch nicht zugewiesen) -> alle Agenten.
 * - Sobald Agenten zugewiesen sind (können mehrere sein) -> nur noch die zugewiesenen.
 * - Kundenantwort -> die zugewiesenen Agenten (oder alle, falls noch keiner da ist).
 * - Agentenantwort -> der Kunde.
 *
 * Jede Mail enthält den vollständigen Text (Ticket-Beschreibung bzw. die
 * konkrete Antwort), von wem sie stammt, und einen Link zum Ticket - nicht
 * nur einen Hinweis, dass "etwas Neues" da ist.
 */
class FGR_TS_Notifications {

    public function __construct() {
        add_action( 'fgr_ts_ticket_created', [ $this, 'on_ticket_created' ] );
        add_action( 'fgr_ts_ticket_assigned', [ $this, 'on_ticket_assigned' ], 10, 2 );
        add_action( 'fgr_ts_ticket_replied', [ $this, 'on_ticket_replied' ], 10, 3 );
        add_action( 'fgr_ts_ticket_opened_for_customer', [ $this, 'on_ticket_opened_for_customer' ], 10, 3 );
    }

    /** Ein Agent hat das Ticket für den Kunden eröffnet (FGR_TS_Ticket::create_for_customer()). */
    public function on_ticket_opened_for_customer( int $ticket_id, int $agent_id, bool $is_new_account ): void {
        $ticket   = FGR_TS_Ticket::get( $ticket_id );
        $customer = $ticket ? get_userdata( (int) $ticket['customer_id'] ) : null;
        if ( ! $customer ) {
            return;
        }

        $footer = [];
        if ( $is_new_account ) {
            $footer[] = '';
            $footer[] = 'Für dich wurde dazu ein Konto angelegt. Passwort festlegen (24 Stunden gültig, danach über "Passwort vergessen" beim Anmelden):';
            $footer[] = $this->set_password_url( $customer );
        }
        if ( FGR_TS_Mail::is_enabled() ) {
            $footer[] = '';
            $footer[] = 'Du kannst auch einfach auf diese E-Mail antworten.';
        }

        $agent_name = $this->display_name( $agent_id );
        $message    = $this->compose( "{$agent_name} hat für dich ein Ticket eröffnet:", $ticket_id, $ticket, $agent_name, $this->first_message_body( $ticket_id ), $footer );

        $this->send( $customer->user_email, "Neues Ticket #{$ticket_id}: {$ticket['subject']}", $message, $ticket_id );
    }

    private function set_password_url( WP_User $user ): string {
        $key = get_password_reset_key( $user );
        if ( is_wp_error( $key ) ) {
            return wp_lostpassword_url();
        }
        return network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
    }

    public function on_ticket_created( int $ticket_id ): void {
        $ticket = FGR_TS_Ticket::get( $ticket_id );
        if ( ! $ticket ) {
            return;
        }
        $customer_name = $this->display_name( (int) $ticket['customer_id'] );
        $body          = $this->first_message_body( $ticket_id );
        $message       = $this->compose( "Ein neues Ticket wurde erstellt von {$customer_name}:", $ticket_id, $ticket, $customer_name, $body );

        foreach ( $this->get_agent_emails() as $email ) {
            $this->send( $email, "Neues Ticket #{$ticket_id}: {$ticket['subject']}", $message, $ticket_id );
        }
    }

    /** $agent_ids: die NEU hinzugekommenen Agenten (siehe FGR_TS_Ticket::set_agents()). */
    public function on_ticket_assigned( int $ticket_id, array $agent_ids ): void {
        $ticket = FGR_TS_Ticket::get( $ticket_id );
        if ( ! $ticket ) {
            return;
        }
        $customer_name = $this->display_name( (int) $ticket['customer_id'] );
        $body          = $this->first_message_body( $ticket_id );
        $message       = $this->compose( "Dir wurde ein Ticket zugewiesen, erstellt von {$customer_name}:", $ticket_id, $ticket, $customer_name, $body );

        foreach ( $agent_ids as $agent_id ) {
            $agent = get_userdata( $agent_id );
            if ( $agent ) {
                $this->send( $agent->user_email, "Dir zugewiesen: Ticket #{$ticket_id}: {$ticket['subject']}", $message, $ticket_id );
            }
        }
    }

    public function on_ticket_replied( int $ticket_id, string $author_role, int $thread_id ): void {
        $ticket = FGR_TS_Ticket::get( $ticket_id );
        $thread = FGR_TS_Ticket::get_thread( $thread_id );
        if ( ! $ticket || ! $thread ) {
            return;
        }
        $body = $this->plain_text( $thread['body'] );

        if ( 'customer' === $author_role ) {
            // Autor statt Ticket-Ersteller: Es kann auch ein weiterer Teilnehmer geantwortet haben.
            $customer_name   = $this->display_name( (int) $thread['author_id'] );
            $message         = $this->compose( "Der Kunde {$customer_name} hat geantwortet:", $ticket_id, $ticket, $customer_name, $body );
            $assigned_agents = FGR_TS_Ticket::get_agents( $ticket_id );

            if ( $assigned_agents ) {
                foreach ( $assigned_agents as $agent_id ) {
                    $agent = get_userdata( $agent_id );
                    if ( $agent ) {
                        $this->send( $agent->user_email, "Neue Antwort zu Ticket #{$ticket_id}: {$ticket['subject']}", $message, $ticket_id );
                    }
                }
            } else {
                foreach ( $this->get_agent_emails() as $email ) {
                    $this->send( $email, "Neue Antwort zu Ticket #{$ticket_id}: {$ticket['subject']}", $message, $ticket_id );
                }
            }
        } elseif ( 'agent' === $author_role ) {
            $customer = get_userdata( (int) $ticket['customer_id'] );
            if ( ! $customer ) {
                return;
            }
            $agent_name = $thread['author_id'] ? $this->display_name( (int) $thread['author_id'] ) : 'FGR';
            $message    = $this->compose( "Es gibt eine neue Antwort von {$agent_name} zu deinem Ticket:", $ticket_id, $ticket, $agent_name, $body );
            $this->send( $customer->user_email, "Antwort zu deinem Ticket #{$ticket_id}: {$ticket['subject']}", $message, $ticket_id );

            // Weitere Teilnehmer (siehe FGR_TS_Ticket::add_watcher()) sollen
            // genauso wie der Ersteller über neue Antworten informiert werden.
            foreach ( FGR_TS_Ticket::get_watchers( $ticket_id ) as $watcher_id ) {
                $watcher = get_userdata( $watcher_id );
                if ( $watcher ) {
                    $this->send( $watcher->user_email, "Antwort zu Ticket #{$ticket_id}: {$ticket['subject']}", $message, $ticket_id );
                }
            }
        }
    }

    /** Baut den vollständigen Mailtext: Einleitung, Ticket-Angaben, kompletter Text, Link. */
    private function compose( string $headline, int $ticket_id, array $ticket, string $author_label, string $body_text, array $footer_lines = [] ): string {
        $mail_enabled = FGR_TS_Mail::is_enabled();

        $lines   = [];
        if ( $mail_enabled ) {
            $lines[] = FGR_TS_Mail::MARKER;
            $lines[] = '';
        }
        $lines[] = $headline;
        $lines[] = '';
        $lines[] = "Ticket #{$ticket_id}: {$ticket['subject']}";
        $lines[] = "Von: {$author_label}";
        $lines[] = '';
        $lines[] = ( '' !== $body_text ) ? $body_text : '(kein Text)';
        $lines[] = '';
        $lines[] = '---';
        $lines[] = 'Ticket ansehen: ' . $this->ticket_url( $ticket_id );
        $lines   = array_merge( $lines, $footer_lines );
        if ( $mail_enabled ) {
            $lines[] = 'Referenz: ' . FGR_TS_Mail::reference( $ticket_id );
        }
        return implode( "\n", $lines );
    }

    private function first_message_body( int $ticket_id ): string {
        $threads = FGR_TS_Ticket::get_threads( $ticket_id );
        foreach ( $threads as $thread ) {
            if ( 'message' === $thread['type'] ) {
                return $this->plain_text( $thread['body'] );
            }
        }
        return '';
    }

    private function display_name( int $user_id ): string {
        $user = get_userdata( $user_id );
        return $user ? $user->display_name : 'Unbekannt';
    }

    /** Mailtext ist Plaintext - HTML (v.a. bei migrierten Nachrichten) wird entfernt. */
    private function plain_text( string $body ): string {
        // Zeilenumbrüche vorher übernehmen, sonst kleben die Zeilen aus dem Editor aneinander.
        $with_breaks = (string) preg_replace( '#<br\s*/?>|</p>#i', "\n", $body );
        return trim( html_entity_decode( wp_strip_all_tags( $with_breaks ), ENT_QUOTES, 'UTF-8' ) );
    }

    private function get_agent_emails(): array {
        $users = get_users( [ 'meta_key' => 'fgr_ts_is_agent', 'meta_value' => 1, 'fields' => [ 'user_email' ] ] );
        return wp_list_pluck( $users, 'user_email' );
    }

    private function ticket_url( int $ticket_id ): string {
        $page_id = (int) get_option( 'fgr_ts_portal_page_id', 0 );
        return $page_id ? add_query_arg( 'ticket', $ticket_id, get_permalink( $page_id ) ) : home_url( '/' );
    }

    private function send( string $to, string $subject, string $body, int $ticket_id ): void {
        $headers = [];
        if ( FGR_TS_Mail::is_enabled() ) {
            $subject  .= ' ' . FGR_TS_Mail::reference( $ticket_id );
            $headers[] = 'Reply-To: ' . FGR_TS_Mail::settings()['address'];
            $headers[] = 'Auto-Submitted: auto-generated';
        }
        wp_mail( $to, $subject, $body, $headers );
    }
}
