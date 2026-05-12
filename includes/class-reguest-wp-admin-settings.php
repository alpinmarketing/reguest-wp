<?php
declare(strict_types=1);
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

function reguest_wp_add_admin_menu(): void {
    add_options_page( 'Reguest API Settings', 'Reguest', 'manage_options', 'reguest-wp', 'reguest_wp_options_page_html' );
}
add_action( 'admin_menu', 'reguest_wp_add_admin_menu' );

function reguest_wp_enqueue_admin_assets( string $hook ): void {
    if ( 'settings_page_reguest-wp' !== $hook ) {
        return;
    }
    // dirname() steps up from 'includes/' to the plugin root to locate admin assets.
    $plugin_url = dirname( plugin_dir_url( __FILE__ ) );

    wp_enqueue_style( 'reguest-wp-admin-style', $plugin_url . '/admin/css/reguest-wp-admin-style.css', [], '1.0' );
    wp_enqueue_script( 'reguest-wp-admin-script', $plugin_url . '/admin/js/reguest-wp-admin-script.js', [ 'jquery' ], '1.0', true );
}
add_action( 'admin_enqueue_scripts', 'reguest_wp_enqueue_admin_assets' );

function reguest_wp_settings_init(): void {
    register_setting(
        'reguest_wp_options_group',
        'reguest_wp_options',
        [
            'type'              => 'array',
            'sanitize_callback' => 'reguest_wp_sanitize_options',
            'default'           => [],
        ]
    );

    add_settings_section( 'reguest_wp_main_section', 'API Credentials', null, 'reguest_wp' );

    add_settings_field( 'reguest_wp_active',    'Plugin aktiv',  'reguest_wp_field_active_cb',    'reguest_wp', 'reguest_wp_main_section' );
    add_settings_field( 'reguest_wp_debug',     'Debug Modus',   'reguest_wp_field_debug_cb',     'reguest_wp', 'reguest_wp_main_section' );
    add_settings_field( 'reguest_wp_test_mode', 'Testmodus',     'reguest_wp_field_test_mode_cb', 'reguest_wp', 'reguest_wp_main_section' );
    add_settings_field( 'reguest_wp_username',  'Benutzername',  'reguest_wp_field_text_cb',      'reguest_wp', 'reguest_wp_main_section', [ 'id' => 'username', 'type' => 'text' ] );
    add_settings_field( 'reguest_wp_password',  'Passwort',      'reguest_wp_field_text_cb',      'reguest_wp', 'reguest_wp_main_section', [ 'id' => 'password', 'type' => 'password' ] );
    add_settings_field( 'reguest_wp_uri',       'API Link',      'reguest_wp_field_text_cb',      'reguest_wp', 'reguest_wp_main_section', [ 'id' => 'uri',      'type' => 'url' ] );

    add_settings_section( 'reguest_wp_form_section', 'Form Field Mapping', null, 'reguest_wp' );
    add_settings_field( 'reguest_wp_form_mapping', 'API Field => Form Field', 'reguest_wp_field_mapping_cb', 'reguest_wp', 'reguest_wp_form_section' );
}
add_action( 'admin_init', 'reguest_wp_settings_init' );

function reguest_wp_handle_admin_actions(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $page   = isset( $_GET['page'] )     ? sanitize_text_field( wp_unslash( $_GET['page'] ) )     : '';
    $action = isset( $_GET['action'] )   ? sanitize_text_field( wp_unslash( $_GET['action'] ) )   : '';
    $nonce  = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

    if ( $page === 'reguest-wp' && $action === 'clear_log' ) {
        if ( wp_verify_nonce( $nonce, 'reguest_wp_clear_log_action' ) ) {
            delete_transient( 'reguest_wp_debug_log' );
            wp_safe_redirect( admin_url( 'options-general.php?page=reguest-wp&log_cleared=1' ) );
            exit;
        }
    }
}
add_action( 'admin_init', 'reguest_wp_handle_admin_actions' );

function reguest_wp_sanitize_options( mixed $input ): array {
    $options          = (array) get_option( 'reguest_wp_options', [] );
    $sanitized_input  = [];
    $input            = is_array( $input ) ? $input : [];

    $sanitized_input['active']    = isset( $input['active'] )    ? '1' : null;
    $sanitized_input['debug']     = isset( $input['debug'] )     ? '1' : null;
    $sanitized_input['test_mode'] = isset( $input['test_mode'] ) ? '1' : null;
    $sanitized_input['username']  = isset( $input['username'] )  ? sanitize_text_field( $input['username'] ) : '';
    $sanitized_input['uri']       = isset( $input['uri'] )       ? esc_url_raw( $input['uri'] )              : '';

    // Only replace the stored password when a new value is explicitly submitted.
    $sanitized_input['password'] = ! empty( $input['password'] )
        ? $input['password']
        : ( $options['password'] ?? '' );

    if ( isset( $input['form_mapping'] ) && is_array( $input['form_mapping'] ) ) {
        $sanitized_input['form_mapping'] = [];
        foreach ( $input['form_mapping'] as $key => $value ) {
            // sanitize_text_field preserves case (e.g. 'ArrivalDate'); sanitize_key() would lowercase it.
            $sanitized_input['form_mapping'][ sanitize_text_field( (string) $key ) ] = sanitize_text_field( (string) $value );
        }
    }

    return $sanitized_input;
}

function reguest_wp_field_active_cb(): void {
    $options = (array) get_option( 'reguest_wp_options', [] );
    echo '<input type="checkbox" name="reguest_wp_options[active]" value="1" ' . checked( isset( $options['active'] ), true, false ) . ' />';
}

function reguest_wp_field_debug_cb(): void {
    $options = (array) get_option( 'reguest_wp_options', [] );
    echo '<input type="checkbox" name="reguest_wp_options[debug]" value="1" ' . checked( isset( $options['debug'] ), true, false ) . ' />';
    echo '<p class="description">Wenn aktiviert, werden detaillierte Fehler und Nachrichten auf dieser Seite protokolliert. Im Live-Betrieb sollte dies deaktiviert sein, Fehler werden dann im Standard-Server-Log gespeichert.</p>';
}

function reguest_wp_field_test_mode_cb(): void {
    $options = (array) get_option( 'reguest_wp_options', [] );
    echo '<input type="checkbox" name="reguest_wp_options[test_mode]" value="1" ' . checked( isset( $options['test_mode'] ), true, false ) . ' />';
    echo '<p class="description">Wenn aktiviert, wird der API-Aufruf nur simuliert und die Daten werden nicht gesendet. Die erstellten Daten werden im Debug-Log angezeigt (wenn der Debug-Modus ebenfalls aktiv ist).</p>';
}

function reguest_wp_field_text_cb( array $args ): void {
    $options     = (array) get_option( 'reguest_wp_options', [] );
    $id          = (string) ( $args['id'] ?? '' );
    $type        = (string) ( $args['type'] ?? 'text' );
    $value       = (string) ( $options[ $id ] ?? '' );
    $placeholder = ( $type === 'password' ) ? 'Zum Ändern neu eingeben' : '';
    $value_attr  = ( $type === 'password' ) ? '' : 'value="' . esc_attr( $value ) . '"';

    echo '<input type="' . esc_attr( $type ) . '" name="reguest_wp_options[' . esc_attr( $id ) . ']" ' . $value_attr . ' placeholder="' . esc_attr( $placeholder ) . '" class="regular-text" />';
}

function reguest_wp_field_mapping_cb(): void {
    $options  = (array) get_option( 'reguest_wp_options', [] );
    $mappings = isset( $options['form_mapping'] ) && is_array( $options['form_mapping'] ) ? $options['form_mapping'] : [];

    $api_fields = [
        'EmailAddress'             => 'E-Mail (Required)',
        'ArrivalDate'              => 'Ankunft (Required)',
        'DepartureDate'            => 'Abreise (Required)',
        'MealType'                 => 'Verpflegung (Required, 0-6)',
        'GuestUserType'            => 'Gast-Typ (0: Person, 1: Firma, 2: Familie)',
        'Gender'                   => 'Geschlecht (0: unb, 1: m, 2: w, 3: d)',
        'Anrede'                   => 'Anrede (setzt Gender/GuestUserType)',
        'Title'                    => 'Titel',
        'FirstName'                => 'Vorname',
        'LastName'                 => 'Nachname',
        'FullName'                 => 'Vollständiger Name (ersetzt Vor-/Nachname)',
        'FamilyName'               => 'Familienname',
        'CompanyName'              => 'Firma',
        'BirthDate'                => 'Geburtsdatum',
        'StreetName'               => 'Straße',
        'PostalCode'               => 'Postleitzahl',
        'CityName'                 => 'Stadt',
        'CountryCode'              => 'Ländercode (ISO 3166-1 alpha-2)',
        'PhoneNumber'              => 'Telefonnummer',
        'MobileNumber'             => 'Mobilnummer',
        'FaxNumber'                => 'Faxnummer',
        'Text'                     => 'Nachricht / Text',
        'LanguageCode'             => 'Sprache (ISO 639-1)',
        'NewsletterSubscription'   => 'Newsletter-Anmeldung (true/false)',
        'AlternativeArrivalDate'   => 'Alternative Ankunft',
        'AlternativeDepartureDate' => 'Alternative Abreise',
        'OfferName'                => 'Angebotsname',
        'OfferCode'                => 'Angebots-Code',
        'ThirdPartyNotes'          => 'Notizen Dritter',
        'ForeignId'                => 'Externe Referenz-ID',
        'SourceOfBusiness'         => 'Herkunft (z.B. Website)',
        'Adults'                   => 'Erwachsene (für Zimmer)',
        'Children'                 => 'Kinder-Anzahl (für Zimmer)',
        'ChildrenAges'             => 'Alter der Kinder (kommagetrennt, für Zimmer)',
    ];

    echo '<div id="reguest_wp_form_mapping">';
    foreach ( $mappings as $key => $value ) {
        echo '<div class="mapping-row">';
        echo '<label for="reguest_wp_options_form_mapping_' . esc_attr( (string) $key ) . '">' . esc_html( (string) $key ) . '</label>';
        echo '<input type="text" name="reguest_wp_options[form_mapping][' . esc_attr( (string) $key ) . ']" value="' . esc_attr( (string) $value ) . '" placeholder="Contact Form 7 field name" data-key="' . esc_attr( (string) $key ) . '" class="regular-text" />';
        echo '<button type="button" class="button button-secondary remove-mapping-row">Entfernen</button>';
        echo '</div>';
    }
    echo '</div>';

    echo '<div style="margin-top: 20px;">';
    echo '<select id="reguest_wp_prototypes">';
    echo '<option value="">-- API Feld auswählen --</option>';
    foreach ( $api_fields as $key => $label ) {
        echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</option>';
    }
    echo '</select> ';
    echo '<button type="button" class="button prototype-button" data-func="add">Hinzufügen</button> ';
    echo '</div>';
}

function reguest_wp_options_page_html(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $options = (array) get_option( 'reguest_wp_options', [] );
    ?>
    <div class="wrap reguest-wp-settings-form">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <?php if ( isset( $_GET['log_cleared'] ) ) : ?>
            <div id="message" class="updated notice is-dismissible"><p>Debug-Log wurde geleert.</p></div>
        <?php endif; ?>
        <form action="options.php" method="post">
            <?php
            settings_fields( 'reguest_wp_options_group' );
            do_settings_sections( 'reguest_wp' );
            submit_button( 'Änderungen speichern' );
            ?>
        </form>
        <?php if ( ! empty( $options['debug'] ) ) : ?>
            <hr>
            <h2>Debug Log</h2>
            <p>Hier werden die letzten 100 Fehler angezeigt, die bei der Kommunikation mit der Re:Guest API aufgetreten sind.</p>
            <?php
            $logs = get_transient( 'reguest_wp_debug_log' );
            if ( ! empty( $logs ) && is_array( $logs ) ) {
                echo '<textarea readonly style="width: 100%; height: 300px; background: #f0f0f0; font-family: monospace; white-space: pre; color: #333;">';
                echo esc_textarea( implode( "\n", $logs ) );
                echo '</textarea>';
                $clear_log_url = wp_nonce_url( admin_url( 'options-general.php?page=reguest-wp&action=clear_log' ), 'reguest_wp_clear_log_action' );
                echo '<p><a href="' . esc_url( $clear_log_url ) . '" class="button button-secondary">Log leeren</a></p>';
            } else {
                echo '<p>Das Log ist leer.</p>';
            }
            ?>
        <?php endif; ?>
    </div>
    <?php
}
