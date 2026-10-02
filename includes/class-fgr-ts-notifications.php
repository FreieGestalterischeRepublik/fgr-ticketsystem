<?php
defined( 'ABSPATH' ) || exit;

/**
 * E-Mail-Benachrichtigungen. Bewusst nur ausgehend (siehe Planungs-Notizen:
 * Antwort-per-E-Mail kommt erst später) - kein IMAP/Mail-Piping.
 *
 * Regeln (wie mit Marc abgesprochen):
 * - Neues Ticket (noch nicht zugewiesen) -> alle Agenten.
 * - Sobald ein Agent zugewiesen ist -> nur noch dieser Agent.
 * - Kundenantwort -> der zugewiesene Agent (oder alle, falls noch keiner da ist).
 * - Agentenantwort -> der Kunde.
 */
class FGR_TS_Notifications {

    public function __construct() {
        add_action( 'fgr_ts_ticket_created', [ $this, 'on_ticket_created' ] );
        add_action( 'fgr_ts_ticket_assigned', [ $this, 'on_ticket_assigned' ], 10, 2 );
        add_action( 'fgr_ts_ticket_replied', [ $this, 'on_ticket_replied' ], 10, 3 );
    }

    public function on_ticket_created( int $ticket_id ): void {
        $ticket = FGR_TS_Ticket::get( $ticket_id );
        if ( ! $ticket ) {
            return;
        }
        foreach ( $this->get_agent_emails() as $email ) {
            $this->send( $email, "Neues Ticket #{$ticket_id}: {$ticket['subject']}", $this->ticket_link( $ticket_id, "Ein neues Ticket wurde erstellt:" ) );
        }
    }

    public function on_ticket_assigned( int $ticket_id, int $agent_id ): void {
        $ticket = FGR_TS_Ticket::get( $ticket_id );
        $agent  = get_userdata( $agent_id );
        if ( ! $ticket || ! $agent ) {
            return;
        }
        $this->send( $agent->user_email, "Dir zugewiesen: Ticket #{$ticket_id}: {$ticket['subject']}", $this->ticket_link( $ticket_id, "Dir wurde ein Ticket zugewiesen:" ) );
    }

    public function on_ticket_replied( int $ticket_id, string $author_role, int $thread_id ): void {
        $ticket = FGR_TS_Ticket::get( $ticket_id );
        if ( ! $ticket ) {
            return;
        }

        if ( 'customer' === $author_role ) {
            if ( $ticket['assigned_agent'] ) {
                $agent = get_userdata( (int) $ticket['assigned_agent'] );
                if ( $agent ) {
                    $this->send( $agent->user_email, "Neue Antwort zu Ticket #{$ticket_id}: {$ticket['subject']}", $this->ticket_link( $ticket_id, "Der Kunde hat geantwortet:" ) );
                }
            } else {
                foreach ( $this->get_agent_emails() as $email ) {
                    $this->send( $email, "Neue Antwort zu Ticket #{$ticket_id}: {$ticket['subject']}", $this->ticket_link( $ticket_id, "Der Kunde hat geantwortet (noch kein Agent zugewiesen):" ) );
                }
            }
        } elseif ( 'agent' === $author_role ) {
            $customer = get_userdata( (int) $ticket['customer_id'] );
            if ( $customer ) {
                $this->send( $customer->user_email, "Antwort zu deinem Ticket #{$ticket_id}: {$ticket['subject']}", $this->ticket_link( $ticket_id, "Es gibt eine neue Antwort zu deinem Ticket:" ) );
            }
        }
    }

    private function get_agent_emails(): array {
        $users = get_users( [ 'meta_key' => 'fgr_ts_is_agent', 'meta_value' => 1, 'fields' => [ 'user_email' ] ] );
        return wp_list_pluck( $users, 'user_email' );
    }

    private function ticket_link( int $ticket_id, string $intro ): string {
        $page_id = (int) get_option( 'fgr_ts_portal_page_id', 0 );
        $url     = $page_id ? add_query_arg( 'ticket', $ticket_id, get_permalink( $page_id ) ) : home_url( '/' );
        return "{$intro}\n\n{$url}";
    }

    private function send( string $to, string $subject, string $body ): void {
        wp_mail( $to, $subject, $body );
    }
}
