<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kunden-Frontend: Shortcode [fgr_tickets], ersetzt das bisherige
 * [supportcandy] auf der "Tickets"-Seite. Bewusst mit den vorhandenen
 * Theme-Klassen/CSS-Variablen der FGR-Seite gebaut (cta-primary, cta-tabs,
 * globale input/textarea-Stile) statt einem eigenen Design - siehe
 * Planungs-Notizen: "am Design der Seite orientieren".
 *
 * Views (über ?view= gesteuert, innerhalb derselben Seite):
 * - (keine)  Ticketliste
 * - new      Neues Ticket erstellen
 * - ticket=N Ticket-Detail + Antworten
 */
class FGR_TS_Frontend {

    public function __construct() {
        add_shortcode( 'fgr_tickets', [ $this, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'admin_post_fgr_ts_create', [ $this, 'handle_create' ] );
        add_action( 'admin_post_fgr_ts_frontend_reply', [ $this, 'handle_reply' ] );
    }

    public function enqueue(): void {
        // Der Shortcode steckt in einem ACF-Feld, nicht im post_content,
        // daher kein has_shortcode()-Check möglich - pauschal auf der
        // bekannten Portal-Seite laden.
        $portal_page_id = (int) get_option( 'fgr_ts_portal_page_id', 0 );
        if ( $portal_page_id && is_page( $portal_page_id ) ) {
            wp_enqueue_style( 'fgr-ts-frontend', FGR_TS_URL . 'assets/css/frontend.css', [], FGR_TS_VERSION );
        }
    }

    public function render_shortcode(): string {
        if ( ! is_user_logged_in() ) {
            return $this->render_login_prompt();
        }

        $user_id = get_current_user_id();

        ob_start();
        if ( isset( $_GET['ticket'] ) ) {
            $this->render_detail( $user_id, (int) $_GET['ticket'] );
        } elseif ( isset( $_GET['view'] ) && 'new' === $_GET['view'] ) {
            $this->render_new_form( $user_id );
        } else {
            $this->render_list( $user_id );
        }
        return ob_get_clean();
    }

    // ---------------------------------------------------------------------

    private function render_login_prompt(): string {
        ob_start();
        ?>
        <div id="fgr_ts_portal" class="fgr-ts-portal">
            <p>Um Tickets zu sehen oder zu erstellen, melde dich bitte an.</p>
            <a class="cta-primary" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><span>Anmelden</span></a>
        </div>
        <?php
        return ob_get_clean();
    }

    private function portal_url( array $args = [] ): string {
        $base = get_permalink( (int) get_option( 'fgr_ts_portal_page_id', 0 ) );
        return $args ? add_query_arg( $args, $base ) : $base;
    }

    // ---------------------------------------------------------------------

    private function render_list( int $user_id ): void {
        global $wpdb;

        $filters = [];
        if ( ! empty( $_GET['status'] ) ) {
            $filters['status_id'] = (int) $_GET['status'];
        }

        $tickets  = FGR_TS_Ticket::get_for_user( $user_id, $filters );
        $statuses = $wpdb->get_results( 'SELECT * FROM ' . FGR_TS_Ticket::table( 'statuses' ) . ' ORDER BY sort_order', ARRAY_A );
        $status_by_id = [];
        foreach ( $statuses as $s ) { $status_by_id[ (int) $s['id'] ] = $s; }
        ?>
        <div id="fgr_ts_portal" class="fgr-ts-portal">
            <div class="fgr-ts-list-head">
                <h2>Meine Tickets</h2>
                <a class="cta-primary" href="<?php echo esc_url( $this->portal_url( [ 'view' => 'new' ] ) ); ?>"><span>Neues Ticket erstellen</span></a>
            </div>

            <div class="fgr-ts-tabs">
                <a class="cta-tabs <?php echo empty( $_GET['status'] ) ? 'active' : ''; ?>" href="<?php echo esc_url( $this->portal_url() ); ?>">Alle</a>
                <?php foreach ( $statuses as $s ) : ?>
                    <a class="cta-tabs <?php echo ( isset( $_GET['status'] ) && (int) $_GET['status'] === (int) $s['id'] ) ? 'active' : ''; ?>"
                       href="<?php echo esc_url( $this->portal_url( [ 'status' => $s['id'] ] ) ); ?>"><?php echo esc_html( $s['name'] ); ?></a>
                <?php endforeach; ?>
            </div>

            <div class="fgr-ts-ticket-list">
                <?php if ( ! $tickets ) : ?>
                    <p>Keine Tickets gefunden.</p>
                <?php endif; ?>
                <?php foreach ( $tickets as $t ) :
                    $status = $status_by_id[ (int) $t['status_id'] ] ?? null;
                    ?>
                    <a class="fgr-ts-ticket-row" href="<?php echo esc_url( $this->portal_url( [ 'ticket' => $t['id'] ] ) ); ?>">
                        <span class="fgr-ts-ticket-subject">#<?php echo (int) $t['id']; ?> &ndash; <?php echo esc_html( $t['subject'] ); ?></span>
                        <span class="fgr-ts-badge" style="color:<?php echo esc_attr( $status['color'] ?? '' ); ?>;background:<?php echo esc_attr( $status['bg_color'] ?? '' ); ?>;"><?php echo esc_html( $status['name'] ?? '' ); ?></span>
                        <span class="fgr-ts-ticket-date"><?php echo esc_html( $this->format_date( $t['date_updated'] ) ); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    // ---------------------------------------------------------------------

    private function render_new_form( int $user_id ): void {
        global $wpdb;
        $categories = $wpdb->get_results( 'SELECT * FROM ' . FGR_TS_Ticket::table( 'categories' ) . ' ORDER BY sort_order', ARRAY_A );
        ?>
        <div id="fgr_ts_portal" class="fgr-ts-portal">
            <p><a href="<?php echo esc_url( $this->portal_url() ); ?>">&larr; Zurück zur Übersicht</a></p>
            <h2>Neues Ticket erstellen</h2>

            <?php if ( ! empty( $_GET['error'] ) ) : ?>
                <p class="fgr-ts-error"><?php echo esc_html( wp_unslash( $_GET['error'] ) ); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="fgr-ts-form">
                <?php wp_nonce_field( 'fgr_ts_create', 'fgr_ts_nonce' ); ?>
                <input type="hidden" name="action" value="fgr_ts_create">

                <label for="fgr-ts-subject">Betreff</label>
                <input type="text" id="fgr-ts-subject" name="subject" required>

                <label for="fgr-ts-category">Kategorie</label>
                <select id="fgr-ts-category" name="category_id" required>
                    <?php foreach ( $categories as $c ) : ?>
                        <option value="<?php echo (int) $c['id']; ?>"><?php echo esc_html( $c['name'] ); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="fgr-ts-body">Beschreibung</label>
                <textarea id="fgr-ts-body" name="body" required></textarea>

                <label for="fgr-ts-attachments">Anhänge (max. 20 MB pro Datei)</label>
                <input type="file" id="fgr-ts-attachments" name="attachments[]" multiple>

                <p class="fgr-ts-gdpr">
                    <label>
                        <input type="checkbox" name="gdpr_consent" value="1" required>
                        <?php echo wp_kses_post( get_option( 'fgr_ts_gdpr_text', FGR_TS_DB::default_gdpr_text() ) ); ?>
                    </label>
                </p>

                <input type="submit" value="Ticket absenden">
            </form>
        </div>
        <?php
    }

    public function handle_create(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( 'Bitte zuerst anmelden.' );
        }
        check_admin_referer( 'fgr_ts_create', 'fgr_ts_nonce' );

        $user_id     = get_current_user_id();
        $subject     = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
        $body        = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );
        $category_id = (int) ( $_POST['category_id'] ?? 0 );
        $consent     = ! empty( $_POST['gdpr_consent'] );

        if ( '' === $subject || '' === $body || ! $category_id || ! $consent ) {
            wp_safe_redirect( $this->portal_url( [ 'view' => 'new', 'error' => rawurlencode( 'Bitte alle Pflichtfelder ausfüllen und der Datenschutzerklärung zustimmen.' ) ] ) );
            exit;
        }

        $ticket_id = FGR_TS_Ticket::create( $user_id, $subject, $body, $category_id, $_SERVER['REMOTE_ADDR'] ?? null );

        if ( ! empty( $_FILES['attachments'] ) ) {
            $threads = FGR_TS_Ticket::get_threads( $ticket_id );
            $first_thread_id = $threads[0]['id'] ?? null;
            FGR_TS_Attachment::handle_uploads( $_FILES['attachments'], $ticket_id, $first_thread_id, $user_id );
        }

        wp_safe_redirect( $this->portal_url( [ 'ticket' => $ticket_id ] ) );
        exit;
    }

    // ---------------------------------------------------------------------

    private function render_detail( int $user_id, int $ticket_id ): void {
        global $wpdb;
        $ticket = FGR_TS_Ticket::get( $ticket_id );
        if ( ! $ticket || ! FGR_TS_Capabilities::can_view_ticket( $user_id, $ticket ) ) {
            echo '<div id="fgr_ts_portal" class="fgr-ts-portal"><p>Ticket nicht gefunden.</p></div>';
            return;
        }

        $threads     = FGR_TS_Ticket::get_threads( $ticket_id, false ); // nur Nachrichten, keine internen Notizen/Logs
        $status      = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . FGR_TS_Ticket::table( 'statuses' ) . ' WHERE id = %d', $ticket['status_id'] ), ARRAY_A );
        $attachments = $this->attachments_by_thread( $ticket_id );
        $is_agent    = FGR_TS_Capabilities::is_agent( $user_id );
        ?>
        <div id="fgr_ts_portal" class="fgr-ts-portal">
            <p><a href="<?php echo esc_url( $this->portal_url() ); ?>">&larr; Zurück zur Übersicht</a></p>
            <div class="fgr-ts-detail-head">
                <h2>#<?php echo (int) $ticket['id']; ?> &ndash; <?php echo esc_html( $ticket['subject'] ); ?></h2>
                <span class="fgr-ts-badge" style="color:<?php echo esc_attr( $status['color'] ?? '' ); ?>;background:<?php echo esc_attr( $status['bg_color'] ?? '' ); ?>;"><?php echo esc_html( $status['name'] ?? '' ); ?></span>
            </div>

            <div class="fgr-ts-thread">
                <?php foreach ( $threads as $th ) :
                    $is_own = (int) $th['author_id'] === $user_id;
                    ?>
                    <div class="fgr-ts-message <?php echo $is_own ? 'fgr-ts-message--own' : 'fgr-ts-message--other'; ?>">
                        <div class="fgr-ts-message-meta">
                            <strong><?php echo esc_html( 'agent' === $th['author_role'] ? 'FGR' : ( $is_own ? 'Du' : '–' ) ); ?></strong>
                            <span><?php echo esc_html( $this->format_date( $th['date_created'] ) ); ?></span>
                        </div>
                        <div class="fgr-ts-message-body"><?php echo wp_kses_post( wpautop( $th['body'] ) ); ?></div>
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

            <?php if ( ! $status['is_closed'] || $is_agent ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="fgr-ts-form">
                <?php wp_nonce_field( 'fgr_ts_frontend_reply_' . $ticket_id, 'fgr_ts_nonce' ); ?>
                <input type="hidden" name="action" value="fgr_ts_frontend_reply">
                <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket_id; ?>">

                <label for="fgr-ts-reply-body">Antworten</label>
                <textarea id="fgr-ts-reply-body" name="body" required></textarea>

                <label for="fgr-ts-reply-attachments">Anhänge (max. 20 MB pro Datei)</label>
                <input type="file" id="fgr-ts-reply-attachments" name="attachments[]" multiple>

                <input type="submit" value="Antwort senden">
            </form>
            <?php else : ?>
                <p>Dieses Ticket ist geschlossen.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_reply(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( 'Bitte zuerst anmelden.' );
        }
        $user_id   = get_current_user_id();
        $ticket_id = (int) ( $_POST['ticket_id'] ?? 0 );
        check_admin_referer( 'fgr_ts_frontend_reply_' . $ticket_id, 'fgr_ts_nonce' );

        $ticket = FGR_TS_Ticket::get( $ticket_id );
        if ( ! $ticket || ! FGR_TS_Capabilities::can_reply_ticket( $user_id, $ticket ) ) {
            wp_die( 'Keine Berechtigung.' );
        }

        $role = FGR_TS_Capabilities::is_agent( $user_id ) ? 'agent' : 'customer';
        $body = sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) );

        if ( $body !== '' ) {
            $thread_id = FGR_TS_Ticket::add_thread( $ticket_id, 'message', $user_id, $role, $body, $_SERVER['REMOTE_ADDR'] ?? null );

            if ( ! empty( $_FILES['attachments'] ) ) {
                FGR_TS_Attachment::handle_uploads( $_FILES['attachments'], $ticket_id, $thread_id, $user_id );
            }
        }

        wp_safe_redirect( $this->portal_url( [ 'ticket' => $ticket_id ] ) );
        exit;
    }

    // ---------------------------------------------------------------------

    private function attachments_by_thread( int $ticket_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . FGR_TS_Ticket::table( 'attachments' ) . ' WHERE ticket_id = %d', $ticket_id
        ), ARRAY_A );
        $out = [];
        foreach ( $rows as $row ) {
            $out[ (int) $row['thread_id'] ][] = $row;
        }
        return $out;
    }

    private function format_date( ?string $mysql_date ): string {
        if ( ! $mysql_date ) return '–';
        $ts = strtotime( $mysql_date );
        return $ts ? date_i18n( 'd.m.Y H:i', $ts ) : '–';
    }
}
