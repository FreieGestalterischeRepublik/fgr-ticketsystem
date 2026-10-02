<?php
defined( 'ABSPATH' ) || exit;

/**
 * Einfaches 2-Stufen-Rechtemodell (siehe Planungs-Notizen: reicht aus,
 * SupportCandys ~57 Einzel-Capabilities werden nicht nachgebaut):
 *
 * - "admin"-Agenten sehen und beantworten ALLE Tickets.
 * - "agent"-Agenten sehen und beantworten NUR ihnen zugewiesene Tickets.
 * - Kunden sehen nur ihre eigenen Tickets.
 *
 * Gespeichert als User-Meta (fgr_ts_is_agent, fgr_ts_tier), nicht als
 * eigene WP-Rolle - die Agenten sind bei FGR ohnehin WordPress-Administratoren.
 */
class FGR_TS_Capabilities {

    public static function is_agent( int $user_id ): bool {
        return (bool) get_user_meta( $user_id, 'fgr_ts_is_agent', true );
    }

    public static function is_admin_tier( int $user_id ): bool {
        return self::is_agent( $user_id ) && get_user_meta( $user_id, 'fgr_ts_tier', true ) === 'admin';
    }

    public static function can_view_ticket( int $user_id, array $ticket ): bool {
        if ( self::is_admin_tier( $user_id ) ) {
            return true;
        }
        if ( self::is_agent( $user_id ) ) {
            return in_array( $user_id, FGR_TS_Ticket::get_agents( (int) $ticket['id'] ), true );
        }
        if ( (int) $ticket['customer_id'] === $user_id ) {
            return true;
        }
        // Weitere Teilnehmer, die ein Admin manuell hinzugefügt hat (z.B.
        // mehrere Ansprechpartner einer Firma), siehe FGR_TS_Ticket::add_watcher().
        return in_array( $user_id, FGR_TS_Ticket::get_watchers( (int) $ticket['id'] ), true );
    }

    public static function can_reply_ticket( int $user_id, array $ticket ): bool {
        return self::can_view_ticket( $user_id, $ticket );
    }

    public static function can_manage_tickets( int $user_id ): bool {
        return self::is_agent( $user_id );
    }
}
