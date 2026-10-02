<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin-Oberfläche für Agenten: Ticketliste + Ticket-Detailansicht mit
 * Antwortformular. Rein funktional gehalten (bewusst kein eigenes Design,
 * das kommt mit dem Kunden-Frontend später) - nutzt WordPress-Admin-Optik.
 *
 * Hängt sich als Untermenü an das gemeinsame "FGR Plugins"-Menü (siehe
 * fgr-plugin-overview MU-Plugin), wie die anderen FGR-Plugins auch.
 */
class FGR_TS_Admin {

    const PAGE_SLUG = 'fgr-ticketsystem';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'admin_post_fgr_ts_reply', [ $this, 'handle_reply' ] );
        add_action( 'admin_post_fgr_ts_update', [ $this, 'handle_update' ] );
        add_action( 'admin_init', [ $this, 'maybe_redirect_customer' ] );
    }

    /**
     * Kunden landen (per "read"-Capability) ebenfalls auf diesem Menüpunkt
     * im wp-admin - statt eines Fehlers bekommen sie einfach ihr eigenes
     * Ticket-Portal im Frontend gezeigt. Muss auf admin_init passieren,
     * NICHT erst im render()-Callback: admin.php hat zu dem Zeitpunkt
     * schon Header/Menü ausgegeben, ein redirect() dort bleibt wirkungslos
     * (leere Seite statt Weiterleitung).
     */
    public function maybe_redirect_customer(): void {
        if ( ( $_GET['page'] ?? '' ) !== self::PAGE_SLUG ) {
            return;
        }
        if ( FGR_TS_Capabilities::is_agent( get_current_user_id() ) ) {
            return;
        }
        wp_safe_redirect( get_permalink( (int) get_option( 'fgr_ts_portal_page_id', 0 ) ) ?: home_url( '/' ) );
        exit;
    }

    public function add_menu(): void {
        // Eigener Menüpunkt auf oberster Ebene (nicht unter "FGR Plugins"),
        // da die Ticketliste ein tägliches Arbeitswerkzeug für Agenten ist,
        // keine gelegentlich aufgerufene Einstellungsseite.
        add_menu_page(
            'Tickets',
            'Tickets',
            'read', // Sichtbarkeit wird im Render selbst per is_agent() geprüft, siehe FGR_MS_Settings als Vorbild
            self::PAGE_SLUG,
            [ $this, 'render' ],
            'dashicons-tickets-alt',
            26
        );
    }

    public function enqueue( string $hook ): void {
        if ( strpos( $hook, self::PAGE_SLUG ) === false ) {
            return;
        }
        wp_enqueue_style( 'fgr-ts-admin', FGR_TS_URL . 'assets/css/admin.css', [], FGR_TS_VERSION );
    }

    /**
     * Kunden landen (per "read"-Capability) ebenfalls auf diesem Menüpunkt
     * im wp-admin - statt eines Fehlers bekommen sie einfach ihr eigenes
     * Ticket-Portal im Frontend gezeigt.
     */
    private function require_agent(): int {
        $user_id = get_current_user_id();
        if ( ! FGR_TS_Capabilities::is_agent( $user_id ) ) {
            wp_safe_redirect( get_permalink( (int) get_option( 'fgr_ts_portal_page_id', 0 ) ) ?: home_url( '/' ) );
            exit;
        }
        return $user_id;
    }

    public function render(): void {
        $user_id = $this->require_agent();

        $ticket_id = isset( $_GET['ticket'] ) ? (int) $_GET['ticket'] : 0;
        if ( $ticket_id ) {
            $this->render_detail( $user_id, $ticket_id );
        } else {
            $this->render_list( $user_id );
        }
    }

    // ---------------------------------------------------------------------

    private function render_list( int $user_id ): void {
        global $wpdb;

        $filters = [];
        if ( ! empty( $_GET['status'] ) ) {
            $filters['status_id'] = (int) $_GET['status'];
        }

        $tickets   = FGR_TS_Ticket::get_for_user( $user_id, $filters );
        $statuses  = $wpdb->get_results( 'SELECT * FROM ' . FGR_TS_Ticket::table( 'statuses' ) . ' ORDER BY sort_order', ARRAY_A );
        $priorities = $this->index_by_id( $wpdb->get_results( 'SELECT * FROM ' . FGR_TS_Ticket::table( 'priorities' ), ARRAY_A ) );
        $categories = $this->index_by_id( $wpdb->get_results( 'SELECT * FROM ' . FGR_TS_Ticket::table( 'categories' ), ARRAY_A ) );
        $status_by_id = $this->index_by_id( $statuses );
        ?>
        <div class="wrap fgr-ts">
            <h1>Tickets</h1>

            <ul class="subsubsub fgr-ts-filters">
                <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="<?php echo empty( $_GET['status'] ) ? 'current' : ''; ?>">Alle</a></li>
                <?php foreach ( $statuses as $s ) : ?>
                    | <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&status=' . $s['id'] ) ); ?>"
                        class="<?php echo ( isset( $_GET['status'] ) && (int) $_GET['status'] === (int) $s['id'] ) ? 'current' : ''; ?>"><?php echo esc_html( $s['name'] ); ?></a></li>
                <?php endforeach; ?>
            </ul>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>#</th><th>Betreff</th><th>Kunde</th><th>Status</th><th>Priorität</th><th>Kategorie</th><th>Agent</th><th>Aktualisiert</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( ! $tickets ) : ?>
                    <tr><td colspan="8">Keine Tickets gefunden.</td></tr>
                <?php endif; ?>
                <?php foreach ( $tickets as $t ) :
                    $customer    = get_userdata( (int) $t['customer_id'] );
                    $agent_names = array_filter( array_map( function ( $id ) {
                        $u = get_userdata( $id );
                        return $u ? $u->display_name : null;
                    }, FGR_TS_Ticket::get_agents( (int) $t['id'] ) ) );
                    $status      = $status_by_id[ $t['status_id'] ] ?? null;
                    ?>
                    <tr>
                        <td><?php echo (int) $t['id']; ?></td>
                        <td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ticket=' . $t['id'] ) ); ?>"><?php echo esc_html( $t['subject'] ); ?></a></td>
                        <td><?php echo esc_html( $customer ? $customer->display_name : '–' ); ?></td>
                        <td><?php echo $this->badge( $status['name'] ?? '–', $status['color'] ?? '', $status['bg_color'] ?? '' ); ?></td>
                        <td><?php echo esc_html( $priorities[ $t['priority_id'] ]['name'] ?? '–' ); ?></td>
                        <td><?php echo esc_html( $categories[ $t['category_id'] ]['name'] ?? '–' ); ?></td>
                        <td><?php echo esc_html( $agent_names ? implode( ', ', $agent_names ) : '– nicht zugewiesen –' ); ?></td>
                        <td><?php echo esc_html( $this->format_date( $t['date_updated'] ) ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------

    private function render_detail( int $user_id, int $ticket_id ): void {
        global $wpdb;

        $ticket = FGR_TS_Ticket::get( $ticket_id );
        if ( ! $ticket || ! FGR_TS_Capabilities::can_view_ticket( $user_id, $ticket ) ) {
            wp_die( 'Ticket nicht gefunden oder keine Berechtigung.' );
        }

        $customer   = get_userdata( (int) $ticket['customer_id'] );
        $threads    = FGR_TS_Ticket::get_threads( $ticket_id );
        $statuses   = $wpdb->get_results( 'SELECT * FROM ' . FGR_TS_Ticket::table( 'statuses' ) . ' ORDER BY sort_order', ARRAY_A );
        $priorities = $wpdb->get_results( 'SELECT * FROM ' . FGR_TS_Ticket::table( 'priorities' ) . ' ORDER BY sort_order', ARRAY_A );
        $categories = $wpdb->get_results( 'SELECT * FROM ' . FGR_TS_Ticket::table( 'categories' ) . ' ORDER BY sort_order', ARRAY_A );
        $agents     = get_users( [ 'meta_key' => 'fgr_ts_is_agent', 'meta_value' => 1 ] );
        $attachments = $this->attachments_by_thread( $ticket_id );
        ?>
        <div class="wrap fgr-ts">
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">&larr; Zurück zur Übersicht</a></p>
            <h1>#<?php echo (int) $ticket['id']; ?> – <?php echo esc_html( $ticket['subject'] ); ?></h1>
            <p class="description">Kunde: <strong><?php echo esc_html( $customer ? $customer->display_name : '–' ); ?></strong>
                (<?php echo esc_html( $customer ? $customer->user_email : '–' ); ?>)</p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fgr-ts-meta-form">
                <?php wp_nonce_field( 'fgr_ts_update_' . $ticket_id, 'fgr_ts_nonce' ); ?>
                <input type="hidden" name="action" value="fgr_ts_update">
                <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket_id; ?>">

                <label>Status
                    <select name="status_id">
                        <?php foreach ( $statuses as $s ) : ?>
                            <option value="<?php echo (int) $s['id']; ?>" <?php selected( $ticket['status_id'], $s['id'] ); ?>><?php echo esc_html( $s['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Priorität
                    <select name="priority_id">
                        <?php foreach ( $priorities as $p ) : ?>
                            <option value="<?php echo (int) $p['id']; ?>" <?php selected( $ticket['priority_id'], $p['id'] ); ?>><?php echo esc_html( $p['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>Kategorie
                    <select name="category_id">
                        <?php foreach ( $categories as $c ) : ?>
                            <option value="<?php echo (int) $c['id']; ?>" <?php selected( $ticket['category_id'], $c['id'] ); ?>><?php echo esc_html( $c['name'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <?php if ( FGR_TS_Capabilities::is_admin_tier( $user_id ) ) :
                    $current_agent_ids = FGR_TS_Ticket::get_agents( $ticket_id );
                    ?>
                <label>Agent(en) <span class="description">(Strg/Cmd gedrückt halten für mehrere)</span>
                    <select name="assigned_agents[]" multiple size="<?php echo min( 5, max( 2, count( $agents ) ) ); ?>">
                        <?php foreach ( $agents as $a ) : ?>
                            <option value="<?php echo (int) $a->ID; ?>" <?php selected( in_array( $a->ID, $current_agent_ids, true ) ); ?>><?php echo esc_html( $a->display_name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>

                <button type="submit" class="button">Übernehmen</button>
            </form>

            <hr>

            <?php if ( ! empty( $_GET['upload_errors'] ) ) : ?>
                <div class="notice notice-error">
                    <?php foreach ( explode( '||', wp_unslash( $_GET['upload_errors'] ) ) as $err ) : ?>
                        <p><?php echo esc_html( $err ); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fgr-ts-reply-form" enctype="multipart/form-data">
                <?php wp_nonce_field( 'fgr_ts_reply_' . $ticket_id, 'fgr_ts_nonce' ); ?>
                <input type="hidden" name="action" value="fgr_ts_reply">
                <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket_id; ?>">
                <h2>Antworten</h2>
                <textarea name="body" rows="6" class="large-text" required></textarea>
                <p>
                    <label><input type="checkbox" name="is_note" value="1"> Interne Notiz (für den Kunden nicht sichtbar)</label>
                </p>
                <p>
                    <label for="fgr-ts-attachments">Anhänge (max. 20 MB pro Datei)</label><br>
                    <input type="file" id="fgr-ts-attachments" name="attachments[]" multiple>
                </p>
                <button type="submit" class="button button-primary">Senden</button>
            </form>

            <hr>

            <!-- Wie bei SupportCandy gewohnt: neueste Nachricht oben, älteste unten. -->
            <div class="fgr-ts-thread">
                <?php foreach ( array_reverse( $threads ) as $th ) :
                    $author = $th['author_id'] ? get_userdata( (int) $th['author_id'] ) : null;

                    if ( 'log' === $th['type'] ) : ?>
                        <div class="fgr-ts-log-entry">
                            <?php echo esc_html( $th['body'] ); ?>
                            &ndash; <?php echo esc_html( $author ? $author->display_name : 'System' ); ?>,
                            <?php echo esc_html( $this->format_date( $th['date_created'] ) ); ?>
                        </div>
                        <?php continue; ?>
                    <?php endif;

                    $css_role = 'note' === $th['type'] ? 'note' : $th['author_role'];
                    ?>
                    <div class="fgr-ts-message fgr-ts-message--<?php echo esc_attr( $css_role ); ?>">
                        <div class="fgr-ts-message-meta">
                            <strong><?php echo esc_html( $author ? $author->display_name : 'System' ); ?></strong>
                            <?php if ( 'note' === $th['type'] ) : ?><span class="fgr-ts-note-label">Interne Notiz</span><?php endif; ?>
                            <span class="fgr-ts-message-date"><?php echo esc_html( $this->format_date( $th['date_created'] ) ); ?></span>
                        </div>
                        <div class="fgr-ts-message-body"><?php echo FGR_TS_Ticket::format_body( $th['body'] ); ?></div>
                        <?php if ( ! empty( $attachments[ $th['id'] ] ) ) : ?>
                            <ul class="fgr-ts-attachments">
                                <?php foreach ( $attachments[ $th['id'] ] as $att ) : ?>
                                    <li><a href="<?php echo esc_url( content_url( 'uploads' . $att['file_path'] ) ); ?>" target="_blank"><?php echo esc_html( $att['file_name'] ); ?></a></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------

    public function handle_reply(): void {
        $user_id   = $this->require_agent();
        $ticket_id = (int) ( $_POST['ticket_id'] ?? 0 );
        check_admin_referer( 'fgr_ts_reply_' . $ticket_id, 'fgr_ts_nonce' );

        $ticket = FGR_TS_Ticket::get( $ticket_id );
        if ( ! $ticket || ! FGR_TS_Capabilities::can_reply_ticket( $user_id, $ticket ) ) {
            wp_die( 'Keine Berechtigung.' );
        }

        $body    = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );
        $is_note = ! empty( $_POST['is_note'] );
        $errors  = [];

        if ( $body !== '' ) {
            $thread_id = FGR_TS_Ticket::add_thread( $ticket_id, $is_note ? 'note' : 'message', $user_id, 'agent', $body, $_SERVER['REMOTE_ADDR'] ?? null );

            if ( ! empty( $_FILES['attachments'] ) ) {
                $errors = FGR_TS_Attachment::handle_uploads( $_FILES['attachments'], $ticket_id, $thread_id, $user_id );
            }
        }

        $redirect = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ticket=' . $ticket_id );
        if ( $errors ) {
            $redirect = add_query_arg( 'upload_errors', rawurlencode( implode( '||', $errors ) ), $redirect );
        }
        wp_safe_redirect( $redirect );
        exit;
    }

    public function handle_update(): void {
        $user_id   = $this->require_agent();
        $ticket_id = (int) ( $_POST['ticket_id'] ?? 0 );
        check_admin_referer( 'fgr_ts_update_' . $ticket_id, 'fgr_ts_nonce' );

        $ticket = FGR_TS_Ticket::get( $ticket_id );
        if ( ! $ticket || ! FGR_TS_Capabilities::can_view_ticket( $user_id, $ticket ) ) {
            wp_die( 'Keine Berechtigung.' );
        }

        global $wpdb;
        $wpdb->update( FGR_TS_Ticket::table( 'tickets' ), [
            'priority_id'  => (int) $_POST['priority_id'],
            'category_id'  => (int) $_POST['category_id'],
            'date_updated' => current_time( 'mysql' ),
        ], [ 'id' => $ticket_id ] );

        if ( (int) $_POST['status_id'] !== (int) $ticket['status_id'] ) {
            FGR_TS_Ticket::set_status( $ticket_id, (int) $_POST['status_id'], $user_id );
        }

        if ( FGR_TS_Capabilities::is_admin_tier( $user_id ) ) {
            $new_agents = array_map( 'intval', (array) ( $_POST['assigned_agents'] ?? [] ) );
            FGR_TS_Ticket::set_agents( $ticket_id, $new_agents );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ticket=' . $ticket_id ) );
        exit;
    }

    // ---------------------------------------------------------------------

    private function index_by_id( array $rows ): array {
        $out = [];
        foreach ( $rows as $row ) {
            $out[ (int) $row['id'] ] = $row;
        }
        return $out;
    }

    private function attachments_by_thread( int $ticket_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . FGR_TS_Ticket::table( 'attachments' ) . ' WHERE ticket_id = %d',
            $ticket_id
        ), ARRAY_A );
        $out = [];
        foreach ( $rows as $row ) {
            $out[ (int) $row['thread_id'] ][] = $row;
        }
        return $out;
    }

    private function badge( string $label, string $color, string $bg ): string {
        if ( ! $color && ! $bg ) {
            return esc_html( $label );
        }
        return sprintf(
            '<span class="fgr-ts-badge" style="color:%s;background:%s;">%s</span>',
            esc_attr( $color ), esc_attr( $bg ), esc_html( $label )
        );
    }

    private function format_date( ?string $mysql_date ): string {
        if ( ! $mysql_date ) return '–';
        $ts = strtotime( $mysql_date );
        return $ts ? date_i18n( 'd.m.Y H:i', $ts ) : '–';
    }
}
