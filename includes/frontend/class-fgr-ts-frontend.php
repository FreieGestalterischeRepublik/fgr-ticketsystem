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
        add_action( 'admin_post_fgr_ts_set_status', [ $this, 'handle_set_status' ] );
        add_action( 'admin_post_nopriv_fgr_ts_register', [ $this, 'handle_register' ] );
        add_action( 'admin_post_fgr_ts_register', [ $this, 'handle_register' ] );
        add_filter( 'login_redirect', [ $this, 'login_redirect' ], 10, 3 );
    }

    /**
     * Kunden landen nach dem Login direkt im Ticket-Portal statt im
     * wp-admin-Dashboard. Das Login-Formular trägt als Default-Ziel
     * unsichtbar admin_url() ein, auch wenn kein redirect_to in der URL
     * stand - "leer" allein reicht als Check also nicht, es wird explizit
     * auf ein wp-admin-Ziel geprüft.
     */
    public function login_redirect( string $redirect_to, string $requested_redirect_to, $user ) {
        if ( $user instanceof WP_User && ! FGR_TS_Capabilities::is_agent( $user->ID ) ) {
            if ( empty( $requested_redirect_to ) || false !== strpos( $requested_redirect_to, '/wp-admin' ) ) {
                return $this->portal_url();
            }
        }
        return $redirect_to;
    }

    public function enqueue(): void {
        // Der Shortcode steckt in einem ACF-Feld, nicht im post_content,
        // daher kein has_shortcode()-Check möglich - pauschal auf der
        // bekannten Portal-Seite laden.
        $portal_page_id = (int) get_option( 'fgr_ts_portal_page_id', 0 );
        if ( $portal_page_id && is_page( $portal_page_id ) ) {
            // WP_DEBUG lokal: immer frisch laden statt gecachter Version,
            // sonst sieht man CSS-Änderungen im Browser nicht sofort.
            $css_version = WP_DEBUG ? (string) filemtime( FGR_TS_DIR . 'assets/css/frontend.css' ) : FGR_TS_VERSION;
            wp_enqueue_style( 'fgr-ts-frontend', FGR_TS_URL . 'assets/css/frontend.css', [], $css_version );
        }
    }

    public function render_shortcode(): string {
        if ( ! is_user_logged_in() ) {
            if ( isset( $_GET['view'] ) && 'register' === $_GET['view'] ) {
                ob_start();
                $this->render_register_form();
                return ob_get_clean();
            }
            if ( isset( $_GET['view'] ) && 'check-email' === $_GET['view'] ) {
                return $this->render_check_email();
            }
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
        <div id="fgr_ts_portal" class="fgr-ts-portal fgr-ts-login-prompt">
            <?php if ( ! empty( $_GET['confirm_error'] ) ) : ?>
                <p class="fgr-ts-error">
                    <?php if ( 'expired' === $_GET['confirm_error'] ) : ?>
                        Der Bestätigungslink ist abgelaufen (nach 30 Minuten) und das Konto wurde entfernt. Bitte registriere dich erneut.
                    <?php else : ?>
                        Dieser Bestätigungslink ist ungültig oder wurde bereits verwendet.
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <p class="fgr-ts-login-message">Um Tickets zu sehen oder zu erstellen, melde dich bitte an.</p>
            <a class="cta-primary" href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><span>Anmelden</span></a>
            <p class="fgr-ts-register-hint">Noch kein Konto? <a href="<?php echo esc_url( $this->portal_url( [ 'view' => 'register' ] ) ); ?>">Jetzt registrieren</a></p>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_check_email(): string {
        ob_start();
        ?>
        <div id="fgr_ts_portal" class="fgr-ts-portal fgr-ts-login-prompt">
            <p class="fgr-ts-login-message">Fast geschafft!</p>
            <p>Wir haben dir eine E-Mail mit einem Bestätigungslink geschickt. Bitte klicke den Link an, um dein Konto zu aktivieren - er ist 30 Minuten gültig.</p>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_register_form(): void {
        ?>
        <div id="fgr_ts_portal" class="fgr-ts-portal">
            <p class="fgr-ts-back-link"><a href="<?php echo esc_url( $this->portal_url() ); ?>">&larr; Zurück zur Anmeldung</a></p>
            <h2>Konto erstellen</h2>

            <?php if ( ! empty( $_GET['error'] ) ) : ?>
                <p class="fgr-ts-error"><?php echo esc_html( wp_unslash( $_GET['error'] ) ); ?></p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fgr-ts-form fgr-ts-register-form">
                <?php wp_nonce_field( 'fgr_ts_register', 'fgr_ts_nonce' ); ?>
                <input type="hidden" name="action" value="fgr_ts_register">

                <label for="fgr-ts-reg-name">Name</label>
                <input type="text" id="fgr-ts-reg-name" name="name" required value="<?php echo esc_attr( wp_unslash( $_GET['name'] ?? '' ) ); ?>">

                <label for="fgr-ts-reg-email">E-Mail-Adresse</label>
                <input type="email" id="fgr-ts-reg-email" name="email" required value="<?php echo esc_attr( wp_unslash( $_GET['email'] ?? '' ) ); ?>">

                <label for="fgr-ts-reg-pass">Passwort</label>
                <input type="password" id="fgr-ts-reg-pass" name="password" required minlength="12">

                <label for="fgr-ts-reg-pass2">Passwort wiederholen</label>
                <input type="password" id="fgr-ts-reg-pass2" name="password2" required minlength="12">

                <p class="fgr-ts-gdpr">
                    <label>
                        <input type="checkbox" name="gdpr_consent" value="1" required>
                        <span><?php echo wp_kses_post( get_option( 'fgr_ts_gdpr_text', FGR_TS_DB::default_gdpr_text() ) ); ?></span>
                    </label>
                </p>

                <button type="submit" class="fgr-ts-submit">Konto erstellen</button>
            </form>
        </div>
        <?php
    }

    public function handle_register(): void {
        check_admin_referer( 'fgr_ts_register', 'fgr_ts_nonce' );

        $name     = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $email    = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $password = (string) ( $_POST['password'] ?? '' );
        $password2 = (string) ( $_POST['password2'] ?? '' );
        $consent  = ! empty( $_POST['gdpr_consent'] );

        $error = '';
        if ( '' === $name || ! is_email( $email ) || '' === $password ) {
            $error = 'Bitte alle Pflichtfelder gültig ausfüllen.';
        } elseif ( $password !== $password2 ) {
            $error = 'Die Passwörter stimmen nicht überein.';
        } elseif ( strlen( $password ) < 12 ) {
            $error = 'Das Passwort muss mindestens 12 Zeichen lang sein.';
        } elseif ( ! $consent ) {
            $error = 'Bitte der Datenschutzerklärung zustimmen.';
        } elseif ( email_exists( $email ) ) {
            $error = 'Zu dieser E-Mail-Adresse existiert bereits ein Konto. Bitte melde dich stattdessen an.';
        }

        if ( $error ) {
            wp_safe_redirect( $this->portal_url( [ 'view' => 'register', 'error' => rawurlencode( $error ), 'name' => rawurlencode( $name ), 'email' => rawurlencode( $email ) ] ) );
            exit;
        }

        $user_id = wp_insert_user( [
            'user_login'   => $email,
            'user_email'   => $email,
            'user_pass'    => $password,
            'display_name' => $name,
            'first_name'   => $name,
            'role'         => 'subscriber',
        ] );

        if ( is_wp_error( $user_id ) ) {
            wp_safe_redirect( $this->portal_url( [ 'view' => 'register', 'error' => rawurlencode( $user_id->get_error_message() ) ] ) );
            exit;
        }

        // Double-Opt-in: Konto ist erstmal gesperrt, bis der Link in der
        // Bestätigungs-Mail angeklickt wurde (siehe FGR_TS_Registration).
        FGR_TS_Registration::register_pending( $user_id );

        wp_safe_redirect( $this->portal_url( [ 'view' => 'check-email' ] ) );
        exit;
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
                       href="<?php echo esc_url( $this->portal_url( [ 'status' => $s['id'] ] ) ); ?>"><?php echo esc_html( $this->display_status_name( $s['name'] ) ); ?></a>
                <?php endforeach; ?>
            </div>

            <div class="fgr-ts-ticket-list">
                <?php if ( ! $tickets && empty( $_GET['status'] ) ) : ?>
                    <p>Keine Tickets gefunden. <a href="<?php echo esc_url( $this->portal_url( [ 'view' => 'new' ] ) ); ?>">Erstelle jetzt Dein erstes Ticket!</a></p>
                <?php elseif ( ! $tickets ) : ?>
                    <p>Keine Tickets in diesem Status gefunden.</p>
                <?php endif; ?>
                <?php foreach ( $tickets as $t ) :
                    $status = $status_by_id[ (int) $t['status_id'] ] ?? null;
                    ?>
                    <a class="fgr-ts-ticket-row" href="<?php echo esc_url( $this->portal_url( [ 'ticket' => $t['id'] ] ) ); ?>">
                        <span class="fgr-ts-ticket-subject">#<?php echo (int) $t['id']; ?> &ndash; <?php echo esc_html( $t['subject'] ); ?></span>
                        <span class="fgr-ts-badge" style="color:<?php echo esc_attr( $status['color'] ?? '' ); ?>;background:<?php echo esc_attr( $status['bg_color'] ?? '' ); ?>;"><?php echo esc_html( $status ? $this->display_status_name( $status['name'] ) : '' ); ?></span>
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
        $priorities = $wpdb->get_results( 'SELECT * FROM ' . FGR_TS_Ticket::table( 'priorities' ) . ' ORDER BY sort_order', ARRAY_A );
        ?>
        <div id="fgr_ts_portal" class="fgr-ts-portal">
            <p class="fgr-ts-back-link"><a href="<?php echo esc_url( $this->portal_url() ); ?>">&larr; Zurück zur Übersicht</a></p>
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

                <label for="fgr-ts-priority">Priorität</label>
                <select id="fgr-ts-priority" name="priority_id" required>
                    <?php foreach ( $priorities as $p ) : ?>
                        <option value="<?php echo (int) $p['id']; ?>"><?php echo esc_html( $p['name'] ); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="fgr-ts-body">Beschreibung</label>
                <textarea id="fgr-ts-body" name="body" required placeholder="Bitte beschreibe dein Anliegen möglichst genau. Ein Screenshot des Fehlers oder die genaue Fehlermeldung sind dabei sehr hilfreich – idealerweise ergänzt um einen Link zur betroffenen Seite."></textarea>

                <label for="fgr-ts-attachments">Anhänge (max. 20 MB pro Datei)</label>
                <input type="file" id="fgr-ts-attachments" name="attachments[]" multiple>

                <p class="fgr-ts-gdpr">
                    <label>
                        <input type="checkbox" name="gdpr_consent" value="1" required>
                        <span><?php echo wp_kses_post( get_option( 'fgr_ts_gdpr_text', FGR_TS_DB::default_gdpr_text() ) ); ?></span>
                    </label>
                </p>

                <button type="submit" class="fgr-ts-submit">Ticket absenden</button>
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
        $priority_id = (int) ( $_POST['priority_id'] ?? 0 );
        $consent     = ! empty( $_POST['gdpr_consent'] );

        if ( '' === $subject || '' === $body || ! $category_id || ! $consent ) {
            wp_safe_redirect( $this->portal_url( [ 'view' => 'new', 'error' => rawurlencode( 'Bitte alle Pflichtfelder ausfüllen und der Datenschutzerklärung zustimmen.' ) ] ) );
            exit;
        }

        $ticket_id = FGR_TS_Ticket::create( $user_id, $subject, $body, $category_id, $_SERVER['REMOTE_ADDR'] ?? null, $priority_id );

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
            <span class="fgr-ts-badge" style="color:<?php echo esc_attr( $status['color'] ?? '' ); ?>;background:<?php echo esc_attr( $status['bg_color'] ?? '' ); ?>;"><?php echo esc_html( $status ? $this->display_status_name( $status['name'] ) : '' ); ?></span>
            <p class="fgr-ts-back-link"><a href="<?php echo esc_url( $this->portal_url() ); ?>">&larr; Zurück zur Übersicht</a></p>
            <div class="fgr-ts-detail-head">
                <h2>#<?php echo (int) $ticket['id']; ?> &ndash; <?php echo esc_html( $ticket['subject'] ); ?></h2>
            </div>

            <?php if ( $status['is_closed'] ?? false ) : ?>
                <p class="fgr-ts-closed-note">Dieses Ticket ist geschlossen, du kannst aber weiterhin antworten.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fgr-ts-status-actions">
                <?php wp_nonce_field( 'fgr_ts_set_status_' . $ticket_id, 'fgr_ts_nonce' ); ?>
                <input type="hidden" name="action" value="fgr_ts_set_status">
                <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket_id; ?>">
                <?php foreach ( [ 'offen' => 'Ticket öffnen', 'In Wartestellung' => 'Auf Wartestellung setzen', 'geschlossen' => 'Ticket schließen' ] as $target => $label ) :
                    $is_current = $status && $status['name'] === $target;
                    ?>
                    <button type="submit" name="status_name" value="<?php echo esc_attr( $target ); ?>"
                        class="cta-tabs<?php echo $is_current ? ' active' : ''; ?>" <?php disabled( $is_current ); ?>><?php echo esc_html( $label ); ?></button>
                <?php endforeach; ?>
            </form>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="fgr-ts-form">
                <?php wp_nonce_field( 'fgr_ts_frontend_reply_' . $ticket_id, 'fgr_ts_nonce' ); ?>
                <input type="hidden" name="action" value="fgr_ts_frontend_reply">
                <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket_id; ?>">

                <label for="fgr-ts-reply-body">Antworten</label>
                <textarea id="fgr-ts-reply-body" name="body" required></textarea>

                <label for="fgr-ts-reply-attachments">Anhänge (max. 20 MB pro Datei)</label>
                <input type="file" id="fgr-ts-reply-attachments" name="attachments[]" multiple>

                <button type="submit" class="fgr-ts-submit">Antwort senden</button>
            </form>

            <!-- Wie im Backend: neueste Nachricht oben, älteste unten. -->
            <div class="fgr-ts-thread">
                <?php foreach ( array_reverse( $threads ) as $th ) :
                    $is_own = (int) $th['author_id'] === $user_id;
                    $author_label = 'agent' === $th['author_role']
                        ? $this->agent_display_label( (int) $th['author_id'] )
                        : ( $is_own ? 'Du' : '–' );
                    ?>
                    <div class="fgr-ts-message <?php echo $is_own ? 'fgr-ts-message--own' : 'fgr-ts-message--other'; ?>">
                        <div class="fgr-ts-message-meta">
                            <strong><?php echo esc_html( $author_label ); ?></strong>
                            <span><?php echo esc_html( $this->format_date( $th['date_created'] ) ); ?></span>
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

    /**
     * Status-Wechsel durch den Kunden (oder Agenten) direkt im Frontend -
     * bewusst nur die drei "groben" Zustände, nicht die internen
     * Konversations-Status (die laufen automatisch über Antworten, siehe
     * FGR_TS_Ticket::auto_advance_status()).
     */
    public function handle_set_status(): void {
        if ( ! is_user_logged_in() ) {
            wp_die( 'Bitte zuerst anmelden.' );
        }
        $user_id   = get_current_user_id();
        $ticket_id = (int) ( $_POST['ticket_id'] ?? 0 );
        check_admin_referer( 'fgr_ts_set_status_' . $ticket_id, 'fgr_ts_nonce' );

        $ticket = FGR_TS_Ticket::get( $ticket_id );
        if ( ! $ticket || ! FGR_TS_Capabilities::can_view_ticket( $user_id, $ticket ) ) {
            wp_die( 'Keine Berechtigung.' );
        }

        $allowed = [ 'offen', 'geschlossen', 'In Wartestellung' ];
        $status_name = sanitize_text_field( wp_unslash( $_POST['status_name'] ?? '' ) );
        if ( in_array( $status_name, $allowed, true ) ) {
            FGR_TS_Ticket::set_status_by_name( $ticket_id, $status_name, $user_id );
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

    /**
     * Kundenfreundlichere Formulierung für den Status - nur die
     * Anzeige im Frontend, der Name in der Datenbank bleibt
     * "Warten auf Kundenantwort" (wird für die Status-Automatik in
     * FGR_TS_Ticket::auto_advance_status() über den Namen abgeglichen).
     */
    /** "Mario aus der FGR" statt nur "FGR" - persönlicher, der Kunde weiß so wer antwortet. */
    private function agent_display_label( int $user_id ): string {
        $user = $user_id ? get_userdata( $user_id ) : null;
        if ( ! $user ) {
            return 'FGR';
        }
        $first_name = $user->first_name ?: ( explode( ' ', trim( $user->display_name ) )[0] ?? '' );
        return $first_name ? $first_name . ' aus der FGR' : 'FGR';
    }

    private function display_status_name( string $name ): string {
        $map = [
            'Warten auf Kundenantwort' => 'Wir warten auf Deine Antwort',
        ];
        return $map[ $name ] ?? $name;
    }

    private function format_date( ?string $mysql_date ): string {
        if ( ! $mysql_date ) return '–';
        $ts = strtotime( $mysql_date );
        return $ts ? date_i18n( 'd.m.Y H:i', $ts ) : '–';
    }
}
