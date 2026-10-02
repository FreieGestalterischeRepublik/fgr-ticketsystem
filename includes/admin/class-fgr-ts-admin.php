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
        add_action( 'admin_post_fgr_ts_bulk', [ $this, 'handle_bulk' ] );
        add_action( 'admin_post_fgr_ts_edit_thread', [ $this, 'handle_edit_thread' ] );
        add_action( 'admin_post_fgr_ts_add_watcher', [ $this, 'handle_add_watcher' ] );
        add_action( 'admin_post_fgr_ts_remove_watcher', [ $this, 'handle_remove_watcher' ] );
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
        $current_orderby = in_array( $_GET['orderby'] ?? '', [ 'customer', 'agent' ], true ) ? $_GET['orderby'] : '';
        $current_order   = 'asc' === strtolower( $_GET['order'] ?? '' ) ? 'asc' : 'desc';
        if ( $current_orderby ) {
            $filters['orderby'] = $current_orderby;
            $filters['order']   = $current_order;
        }

        $tickets   = FGR_TS_Ticket::get_for_user( $user_id, $filters );
        $statuses  = $wpdb->get_results( 'SELECT * FROM ' . FGR_TS_Ticket::table( 'statuses' ) . ' ORDER BY sort_order', ARRAY_A );
        $priorities = $this->index_by_id( $wpdb->get_results( 'SELECT * FROM ' . FGR_TS_Ticket::table( 'priorities' ), ARRAY_A ) );
        $categories = $this->index_by_id( $wpdb->get_results( 'SELECT * FROM ' . FGR_TS_Ticket::table( 'categories' ), ARRAY_A ) );
        $status_by_id = $this->index_by_id( $statuses );
        $is_admin_tier = FGR_TS_Capabilities::is_admin_tier( $user_id );
        ?>
        <div class="wrap fgr-ts">
            <h1>Tickets</h1>

            <?php if ( isset( $_GET['bulk_done'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo (int) $_GET['bulk_done']; ?> Ticket(s) aktualisiert.</p></div>
            <?php endif; ?>

            <ul class="subsubsub fgr-ts-filters">
                <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>" class="<?php echo empty( $_GET['status'] ) ? 'current' : ''; ?>">Alle</a></li>
                <?php foreach ( $statuses as $s ) : ?>
                    | <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&status=' . $s['id'] ) ); ?>"
                        class="<?php echo ( isset( $_GET['status'] ) && (int) $_GET['status'] === (int) $s['id'] ) ? 'current' : ''; ?>"><?php echo esc_html( $s['name'] ); ?></a></li>
                <?php endforeach; ?>
            </ul>

            <form id="fgr-ts-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'fgr_ts_bulk', 'fgr_ts_bulk_nonce' ); ?>
                <input type="hidden" name="action" value="fgr_ts_bulk">
                <?php if ( ! empty( $_GET['status'] ) ) : ?>
                    <input type="hidden" name="return_status" value="<?php echo (int) $_GET['status']; ?>">
                <?php endif; ?>

                <div class="tablenav top">
                    <div class="alignleft actions">
                        <select name="bulk_action">
                            <option value="">Sammelaktion</option>
                            <option value="close">Schließen</option>
                            <?php if ( $is_admin_tier ) : ?>
                                <option value="delete">Löschen</option>
                            <?php endif; ?>
                        </select>
                        <button type="submit" class="button">Anwenden</button>
                    </div>
                </div>

                <table class="widefat striped">
                    <thead>
                        <tr>
                            <td class="manage-column column-cb check-column"><input type="checkbox" id="fgr-ts-select-all"></td>
                            <th>#</th><th>Betreff</th>
                            <th><?php echo $this->sortable_column_header( 'Kunde', 'customer', $current_orderby, $current_order ); ?></th>
                            <th>Status</th><th>Priorität</th><th>Kategorie</th>
                            <th><?php echo $this->sortable_column_header( 'Agent', 'agent', $current_orderby, $current_order ); ?></th>
                            <th>Aktualisiert</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( ! $tickets ) : ?>
                        <tr><td colspan="9">Keine Tickets gefunden.</td></tr>
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
                            <th class="check-column"><input type="checkbox" class="fgr-ts-row-checkbox" name="ticket_ids[]" value="<?php echo (int) $t['id']; ?>"></th>
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
            </form>
        </div>
        <script>
        (function() {
            var selectAll = document.getElementById( 'fgr-ts-select-all' );
            if ( selectAll ) {
                selectAll.addEventListener( 'change', function() {
                    document.querySelectorAll( '.fgr-ts-row-checkbox' ).forEach( function( cb ) {
                        cb.checked = selectAll.checked;
                    } );
                } );
            }
            var bulkForm = document.getElementById( 'fgr-ts-bulk-form' );
            if ( bulkForm ) {
                bulkForm.addEventListener( 'submit', function( e ) {
                    var action = bulkForm.querySelector( '[name="bulk_action"]' ).value;
                    var checked = bulkForm.querySelectorAll( '.fgr-ts-row-checkbox:checked' ).length;
                    if ( ! action || ! checked ) {
                        e.preventDefault();
                        return;
                    }
                    if ( 'delete' === action && ! confirm( checked + ' Ticket(s) wirklich endgültig löschen? Das kann nicht rückgängig gemacht werden.' ) ) {
                        e.preventDefault();
                    }
                } );
            }
        })();
        </script>
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
        $is_admin_tier = FGR_TS_Capabilities::is_admin_tier( $user_id );
        ?>
        <div class="wrap fgr-ts">
            <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>">&larr; Zurück zur Übersicht</a></p>
            <h1>#<?php echo (int) $ticket['id']; ?> – <?php echo esc_html( $ticket['subject'] ); ?></h1>
            <p class="description">Kunde: <strong><?php echo esc_html( $customer ? $customer->display_name : '–' ); ?></strong>
                (<?php echo esc_html( $customer ? $customer->user_email : '–' ); ?>)</p>

            <div class="fgr-ts-meta-bar">
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

                <?php if ( $is_admin_tier ) :
                    $current_agent_ids = FGR_TS_Ticket::get_agents( $ticket_id );
                    $summary_label     = $current_agent_ids
                        ? sprintf( '%d Agent(en) ausgewählt', count( $current_agent_ids ) )
                        : 'Keiner ausgewählt';
                    ?>
                <label>Agent(en)
                    <details class="fgr-ts-agent-picker">
                        <summary><?php echo esc_html( $summary_label ); ?></summary>
                        <div class="fgr-ts-agent-list">
                            <?php foreach ( $agents as $a ) : ?>
                                <label class="fgr-ts-agent-option">
                                    <input type="checkbox" name="assigned_agents[]" value="<?php echo (int) $a->ID; ?>" <?php checked( in_array( $a->ID, $current_agent_ids, true ) ); ?>>
                                    <?php echo esc_html( $a->display_name ); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </label>
                <?php endif; ?>

                <button type="submit" class="button">Übernehmen</button>
            </form>

            <?php if ( $is_admin_tier ) :
                $watcher_ids   = FGR_TS_Ticket::get_watchers( $ticket_id );
                $summary_label = $watcher_ids ? count( $watcher_ids ) . ' ausgewählt' : 'Keiner ausgewählt';
                // Nur Kunden-Accounts zur Auswahl anbieten (keine Agenten -
                // die haben ohnehin schon Zugriff auf alles/ihre Tickets),
                // und keine, die schon Ersteller oder bereits Teilnehmer sind.
                $excluded_ids  = array_merge( [ (int) $ticket['customer_id'] ], $watcher_ids );
                $addable_users = array_filter( get_users( [ 'orderby' => 'display_name', 'order' => 'ASC' ] ), function ( $u ) use ( $excluded_ids ) {
                    return ! FGR_TS_Capabilities::is_agent( $u->ID ) && ! in_array( $u->ID, $excluded_ids, true );
                } );
                ?>
                <label>Weitere Teilnehmer
                    <details class="fgr-ts-agent-picker">
                        <summary><?php echo esc_html( $summary_label ); ?></summary>
                        <div class="fgr-ts-agent-list">
                            <?php if ( $watcher_ids ) : ?>
                                <ul class="fgr-ts-watcher-list">
                                    <?php foreach ( $watcher_ids as $watcher_id ) :
                                        $watcher = get_userdata( $watcher_id );
                                        if ( ! $watcher ) {
                                            continue;
                                        }
                                        ?>
                                        <li>
                                            <?php echo esc_html( $watcher->display_name ); ?>
                                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fgr-ts-watcher-remove-form">
                                                <?php wp_nonce_field( 'fgr_ts_remove_watcher_' . $ticket_id, 'fgr_ts_nonce' ); ?>
                                                <input type="hidden" name="action" value="fgr_ts_remove_watcher">
                                                <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket_id; ?>">
                                                <input type="hidden" name="watcher_id" value="<?php echo (int) $watcher_id; ?>">
                                                <button type="submit" class="button-link fgr-ts-watcher-remove">entfernen</button>
                                            </form>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else : ?>
                                <span class="description">Keine</span>
                            <?php endif; ?>

                            <?php if ( ! empty( $_GET['watcher_error'] ) ) : ?>
                                <p class="fgr-ts-watcher-error"><?php echo esc_html( wp_unslash( $_GET['watcher_error'] ) ); ?></p>
                            <?php endif; ?>

                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fgr-ts-watcher-add-form">
                                <?php wp_nonce_field( 'fgr_ts_add_watcher_' . $ticket_id, 'fgr_ts_nonce' ); ?>
                                <input type="hidden" name="action" value="fgr_ts_add_watcher">
                                <input type="hidden" name="ticket_id" value="<?php echo (int) $ticket_id; ?>">
                                <select name="watcher_id">
                                    <option value="">— Benutzer wählen —</option>
                                    <?php foreach ( $addable_users as $u ) : ?>
                                        <option value="<?php echo (int) $u->ID; ?>"><?php echo esc_html( $u->display_name . ' (' . $u->user_email . ')' ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="button">Hinzufügen</button>
                            </form>
                        </div>
                    </details>
                </label>
            <?php endif; ?>
            </div>

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
                <?php echo $this->wysiwyg_field( '', 160 ); ?>
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
                        <?php if ( $is_admin_tier ) : ?>
                            <details class="fgr-ts-edit-toggle">
                                <summary>Bearbeiten</summary>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="fgr-ts-edit-form">
                                    <?php wp_nonce_field( 'fgr_ts_edit_thread_' . $th['id'], 'fgr_ts_nonce' ); ?>
                                    <input type="hidden" name="action" value="fgr_ts_edit_thread">
                                    <input type="hidden" name="thread_id" value="<?php echo (int) $th['id']; ?>">
                                    <?php echo $this->wysiwyg_field( $th['body'], 90 ); ?>
                                    <button type="submit" class="button">Speichern</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <script>
        (function() {
            try { document.execCommand( 'defaultParagraphSeparator', false, 'br' ); } catch ( e ) {}

            document.querySelectorAll( '.fgr-ts-wysiwyg' ).forEach( function( wrap ) {
                var editor = wrap.querySelector( '.fgr-ts-wysiwyg-editor' );
                var hidden = wrap.querySelector( '.fgr-ts-wysiwyg-hidden' );
                if ( ! editor || ! hidden ) {
                    return;
                }

                hidden.value = editor.innerHTML;
                editor.addEventListener( 'input', function() {
                    hidden.value = editor.innerHTML;
                } );

                wrap.querySelectorAll( '.fgr-ts-wysiwyg-toolbar button[data-cmd]' ).forEach( function( btn ) {
                    btn.addEventListener( 'click', function() {
                        editor.focus();
                        document.execCommand( btn.getAttribute( 'data-cmd' ), false, null );
                        hidden.value = editor.innerHTML;
                    } );
                } );

                var form = wrap.closest( 'form' );
                if ( form ) {
                    form.addEventListener( 'submit', function() {
                        hidden.value = editor.innerHTML;
                    } );
                }
            } );
        })();
        </script>
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

        $body    = $this->sanitize_rich_body( wp_unslash( $_POST['body'] ?? '' ) );
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
            FGR_TS_Ticket::set_agents( $ticket_id, $new_agents, $user_id );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ticket=' . $ticket_id ) );
        exit;
    }

    /**
     * Weiteren bestehenden Benutzer zum Ticket hinzufügen (z.B. ein
     * weiterer Ansprechpartner derselben Firma) - nur Admin-Tier. Auswahl
     * per Dropdown (siehe render_detail()) statt Freitext, da es nur
     * bestehende Accounts sein können (Konto muss vorher angelegt sein).
     */
    public function handle_add_watcher(): void {
        $user_id   = $this->require_agent();
        $ticket_id = (int) ( $_POST['ticket_id'] ?? 0 );
        check_admin_referer( 'fgr_ts_add_watcher_' . $ticket_id, 'fgr_ts_nonce' );

        if ( ! FGR_TS_Capabilities::is_admin_tier( $user_id ) ) {
            wp_die( 'Keine Berechtigung.' );
        }

        $ticket = FGR_TS_Ticket::get( $ticket_id );
        if ( ! $ticket ) {
            wp_die( 'Ticket nicht gefunden.' );
        }

        $watcher_id = (int) ( $_POST['watcher_id'] ?? 0 );
        $new_user   = $watcher_id ? get_userdata( $watcher_id ) : false;
        $error      = '';

        if ( ! $watcher_id ) {
            $error = 'Bitte einen Benutzer auswählen.';
        } elseif ( ! $new_user ) {
            $error = 'Benutzer nicht gefunden.';
        } elseif ( $watcher_id === (int) $ticket['customer_id'] ) {
            $error = 'Dieser Benutzer ist bereits der Ersteller des Tickets.';
        } else {
            FGR_TS_Ticket::add_watcher( $ticket_id, $watcher_id );
        }

        $redirect = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ticket=' . $ticket_id );
        if ( $error ) {
            $redirect = add_query_arg( 'watcher_error', rawurlencode( $error ), $redirect );
        }
        wp_safe_redirect( $redirect );
        exit;
    }

    public function handle_remove_watcher(): void {
        $user_id   = $this->require_agent();
        $ticket_id = (int) ( $_POST['ticket_id'] ?? 0 );
        check_admin_referer( 'fgr_ts_remove_watcher_' . $ticket_id, 'fgr_ts_nonce' );

        if ( ! FGR_TS_Capabilities::is_admin_tier( $user_id ) ) {
            wp_die( 'Keine Berechtigung.' );
        }

        FGR_TS_Ticket::remove_watcher( $ticket_id, (int) ( $_POST['watcher_id'] ?? 0 ) );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ticket=' . $ticket_id ) );
        exit;
    }

    /** Nachträgliches Bearbeiten einer Nachricht/Notiz - nur Admin-Tier. */
    public function handle_edit_thread(): void {
        $user_id   = $this->require_agent();
        $thread_id = (int) ( $_POST['thread_id'] ?? 0 );
        check_admin_referer( 'fgr_ts_edit_thread_' . $thread_id, 'fgr_ts_nonce' );

        if ( ! FGR_TS_Capabilities::is_admin_tier( $user_id ) ) {
            wp_die( 'Keine Berechtigung.' );
        }

        $thread = FGR_TS_Ticket::get_thread( $thread_id );
        $ticket = $thread ? FGR_TS_Ticket::get( (int) $thread['ticket_id'] ) : null;
        if ( ! $thread || ! $ticket ) {
            wp_die( 'Nachricht nicht gefunden.' );
        }

        $body = $this->sanitize_rich_body( wp_unslash( $_POST['body'] ?? '' ) );
        if ( '' !== $body ) {
            FGR_TS_Ticket::update_thread_body( $thread_id, $body, $user_id );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&ticket=' . $ticket['id'] ) );
        exit;
    }

    /**
     * Sammelverarbeitung aus der Übersicht: Schließen (alle Agenten,
     * jeweils nur für Tickets mit Zugriff) oder Löschen (nur Admin-Tier,
     * siehe FGR_TS_Capabilities - Löschen ist eine destruktive Aktion,
     * die normale Agenten nicht auslösen können sollen).
     */
    public function handle_bulk(): void {
        $user_id = $this->require_agent();
        check_admin_referer( 'fgr_ts_bulk', 'fgr_ts_bulk_nonce' );

        $bulk_action = sanitize_key( $_POST['bulk_action'] ?? '' );
        $ticket_ids  = array_map( 'intval', (array) ( $_POST['ticket_ids'] ?? [] ) );
        $count       = 0;

        if ( $ticket_ids && 'close' === $bulk_action ) {
            global $wpdb;
            $closed_status_id = (int) $wpdb->get_var(
                "SELECT id FROM " . FGR_TS_Ticket::table( 'statuses' ) . " WHERE is_closed = 1 ORDER BY sort_order ASC LIMIT 1"
            );
            if ( $closed_status_id ) {
                foreach ( $ticket_ids as $ticket_id ) {
                    $ticket = FGR_TS_Ticket::get( $ticket_id );
                    if ( $ticket && FGR_TS_Capabilities::can_reply_ticket( $user_id, $ticket ) ) {
                        FGR_TS_Ticket::set_status( $ticket_id, $closed_status_id, $user_id );
                        $count++;
                    }
                }
            }
        } elseif ( $ticket_ids && 'delete' === $bulk_action && FGR_TS_Capabilities::is_admin_tier( $user_id ) ) {
            foreach ( $ticket_ids as $ticket_id ) {
                if ( FGR_TS_Ticket::get( $ticket_id ) ) {
                    FGR_TS_Ticket::delete( $ticket_id );
                    $count++;
                }
            }
        }

        $redirect = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
        if ( ! empty( $_POST['return_status'] ) ) {
            $redirect = add_query_arg( 'status', (int) $_POST['return_status'], $redirect );
        }
        $redirect = add_query_arg( 'bulk_done', $count, $redirect );
        wp_safe_redirect( $redirect );
        exit;
    }

    // ---------------------------------------------------------------------

    /**
     * Nachrichtentext aus dem WYSIWYG-Feld (siehe wysiwyg_field()) säubern:
     * sanitize_textarea_field() würde ALLE HTML-Tags entfernen (auch die
     * gewünschten Fett/Kursiv/Unterstrichen-Tags) - hier bewusst nur genau
     * diese drei plus Zeilenumbrüche erlauben, alles andere raus.
     */
    private function sanitize_rich_body( string $raw ): string {
        return trim( wp_kses( $raw, [
            'strong' => [],
            'b'      => [],
            'em'     => [],
            'i'      => [],
            'u'      => [],
            'br'     => [],
        ] ) );
    }

    /**
     * Echtes WYSIWYG statt rohem HTML im Textfeld: ein contenteditable-Div
     * mit Fett/Kursiv/Unterstrichen-Buttons (document.execCommand - bewusst
     * kein TinyMCE/voller Editor). Der Inhalt wird per JS laufend in die
     * eigentlich abgeschickte (versteckte) Textarea gespiegelt, damit am
     * Server weiterhin ganz normal $_POST['body'] ankommt.
     *
     * $initial_html: bestehender Inhalt (Bearbeiten) oder leer (Antworten) -
     * schon sicheres HTML (kommt entweder leer oder aus der eigenen DB, die
     * nur über sanitize_rich_body() befüllt wird).
     */
    private function wysiwyg_field( string $initial_html, int $min_height ): string {
        return '<div class="fgr-ts-wysiwyg">'
            . '<div class="fgr-ts-wysiwyg-toolbar">'
            . '<button type="button" data-cmd="bold" title="Fett"><strong>F</strong></button>'
            . '<button type="button" data-cmd="italic" title="Kursiv"><em>K</em></button>'
            . '<button type="button" data-cmd="underline" title="Unterstrichen"><u>U</u></button>'
            . '</div>'
            . '<div class="fgr-ts-wysiwyg-editor" contenteditable="true" style="min-height:' . (int) $min_height . 'px;">' . $initial_html . '</div>'
            . '<textarea name="body" class="fgr-ts-wysiwyg-hidden"></textarea>'
            . '</div>';
    }

    /** Klickbarer Spaltenkopf zum Sortieren (Kunde/Agent) - erster Klick aufsteigend, erneuter Klick dreht um. */
    private function sortable_column_header( string $label, string $column, string $current_orderby, string $current_order ): string {
        $is_active = $current_orderby === $column;
        $next_order = ( $is_active && 'asc' === $current_order ) ? 'desc' : 'asc';

        $args = [ 'orderby' => $column, 'order' => $next_order ];
        if ( ! empty( $_GET['status'] ) ) {
            $args['status'] = (int) $_GET['status'];
        }
        $url = add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );

        $arrow = '';
        if ( $is_active ) {
            $arrow = ' <span aria-hidden="true">' . ( 'asc' === $current_order ? '&#9650;' : '&#9660;' ) . '</span>';
        }

        return sprintf(
            '<a href="%s">%s%s</a>',
            esc_url( $url ),
            esc_html( $label ),
            $arrow
        );
    }

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
