<?php
defined( 'ABSPATH' ) || exit;

/**
 * Seite "Tickets → E-Mail-Eingang": Postfach-Zugang, Status des letzten
 * Abrufs und Protokoll. Nur für WordPress-Administratoren, weil hier das
 * IMAP-Passwort hinterlegt wird.
 */
class FGR_TS_Mail_Settings {

    const PAGE_SLUG = 'fgr-ticketsystem-mail';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_post_fgr_ts_mail_save', [ $this, 'handle_save' ] );
    }

    public function add_menu(): void {
        add_submenu_page( FGR_TS_Admin::PAGE_SLUG, 'E-Mail-Eingang', 'E-Mail-Eingang', 'manage_options', self::PAGE_SLUG, [ $this, 'render' ] );
    }

    public function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Keine Berechtigung.' );
        }
        check_admin_referer( 'fgr_ts_mail_save', 'fgr_ts_nonce' );

        $current    = FGR_TS_Mail::settings();
        $encryption = sanitize_key( $_POST['encryption'] ?? 'ssl' );
        $password   = (string) wp_unslash( $_POST['password'] ?? '' );

        update_option( FGR_TS_Mail::OPTION, [
            'enabled'    => empty( $_POST['enabled'] ) ? 0 : 1,
            'address'    => sanitize_email( wp_unslash( $_POST['address'] ?? '' ) ),
            'host'       => sanitize_text_field( wp_unslash( $_POST['host'] ?? '' ) ),
            'port'       => absint( $_POST['port'] ?? 993 ) ?: 993,
            'encryption' => 'tls' === $encryption ? 'tls' : 'ssl',
            'username'   => sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) ),
            // Leeres Feld behält das gespeicherte Passwort, es wird nie zurück ins Formular geschrieben.
            'password'   => '' !== $password ? FGR_TS_Mail::encrypt_password( $password ) : $current['password'],
        ], false );

        wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE_SLUG, 'saved' => 1 ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Keine Berechtigung.' );
        }

        $settings = FGR_TS_Mail::settings();
        $status   = (array) get_option( FGR_TS_Mail::STATUS_OPTION, [] );
        $log      = FGR_TS_Mail::recent_log();
        $cron     = sprintf( '*/2 * * * * wp fgr-ts fetch-mail --path=%s --quiet', untrailingslashit( ABSPATH ) );
        ?>
        <div class="wrap">
            <h1>E-Mail-Eingang</h1>

            <?php if ( ! empty( $_GET['saved'] ) ) : ?>
                <div class="notice notice-success"><p>Einstellungen gespeichert.</p></div>
            <?php endif; ?>
            <?php if ( ! function_exists( 'imap_open' ) ) : ?>
                <div class="notice notice-warning"><p>Für diese PHP-Version fehlt hier die Erweiterung <code>imap</code>. Entscheidend ist die PHP-Version, mit der der Cronjob läuft.</p></div>
            <?php endif; ?>

            <h2>Letzter Abruf</h2>
            <?php if ( empty( $status['time'] ) ) : ?>
                <p>Noch kein Abruf gelaufen.</p>
            <?php elseif ( ! empty( $status['error'] ) ) : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html( $status['time'] . ': ' . $status['error'] ); ?></p></div>
            <?php else : ?>
                <p><?php echo esc_html( sprintf( '%s: %d importiert, %d im Prüfordner', $status['time'], $status['imported'], $status['review'] ) ); ?></p>
            <?php endif; ?>

            <h2>Postfach</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="fgr_ts_mail_save">
                <?php wp_nonce_field( 'fgr_ts_mail_save', 'fgr_ts_nonce' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Aktiv</th>
                        <td>
                            <label><input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> Mails abrufen und in Benachrichtigungen auf dieses Postfach verweisen</label>
                            <p class="description">Erst einschalten, wenn Postfach und Cronjob stehen: Ab dann gehen Antworten auf Benachrichtigungen an dieses Postfach.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fgr-ts-address">Adresse des Postfachs</label></th>
                        <td><input type="email" class="regular-text" id="fgr-ts-address" name="address" value="<?php echo esc_attr( $settings['address'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fgr-ts-host">IMAP-Server</label></th>
                        <td><input type="text" class="regular-text" id="fgr-ts-host" name="host" value="<?php echo esc_attr( $settings['host'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fgr-ts-port">Port</label></th>
                        <td><input type="number" class="small-text" id="fgr-ts-port" name="port" value="<?php echo esc_attr( (string) $settings['port'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fgr-ts-encryption">Verschlüsselung</label></th>
                        <td>
                            <select id="fgr-ts-encryption" name="encryption">
                                <option value="ssl" <?php selected( $settings['encryption'], 'ssl' ); ?>>SSL/TLS (meist Port 993)</option>
                                <option value="tls" <?php selected( $settings['encryption'], 'tls' ); ?>>STARTTLS (meist Port 143)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fgr-ts-username">Benutzername</label></th>
                        <td><input type="text" class="regular-text" id="fgr-ts-username" name="username" autocomplete="off" value="<?php echo esc_attr( $settings['username'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="fgr-ts-password">Passwort</label></th>
                        <td>
                            <input type="password" class="regular-text" id="fgr-ts-password" name="password" autocomplete="new-password">
                            <p class="description"><?php echo $settings['password'] ? 'Gespeichert. Leer lassen, um es zu behalten.' : 'Noch nicht gesetzt.'; ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Speichern' ); ?>
            </form>

            <h2>Cronjob</h2>
            <p>Der Abruf läuft über einen System-Cronjob als Website-Benutzer. Zugang testen, ohne etwas zu verändern: <code>wp fgr-ts fetch-mail --dry-run</code></p>
            <p><code><?php echo esc_html( $cron ); ?></code></p>

            <h2>Protokoll</h2>
            <p>Die letzten Mails, Einträge bleiben <?php echo (int) FGR_TS_Mail::LOG_RETENTION_DAYS; ?> Tage. Mails im Ordner <code><?php echo esc_html( FGR_TS_Mail::FOLDER_REVIEW ); ?></code> lassen sich erneut verarbeiten, indem du sie zurück in den Eingang verschiebst.</p>
            <table class="widefat striped">
                <thead><tr><th>Datum</th><th>Absender</th><th>Betreff</th><th>Ergebnis</th><th>Ticket</th></tr></thead>
                <tbody>
                <?php if ( ! $log ) : ?>
                    <tr><td colspan="5">Noch keine Einträge.</td></tr>
                <?php endif; ?>
                <?php foreach ( $log as $row ) : ?>
                    <tr>
                        <td><?php echo esc_html( $row['date_created'] ); ?></td>
                        <td><?php echo esc_html( $row['sender'] ); ?></td>
                        <td><?php echo esc_html( $row['subject'] ); ?></td>
                        <td><?php echo esc_html( 'imported' === $row['result'] ? $row['reason'] : 'Prüfordner: ' . $row['reason'] ); ?></td>
                        <td>
                            <?php if ( $row['ticket_id'] ) : ?>
                                <a href="<?php echo esc_url( add_query_arg( [ 'page' => FGR_TS_Admin::PAGE_SLUG, 'ticket' => (int) $row['ticket_id'] ], admin_url( 'admin.php' ) ) ); ?>">#<?php echo (int) $row['ticket_id']; ?></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
