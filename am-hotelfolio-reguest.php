<?php
/**
 * Plugin Name: AM Hotelfolio Reguest
 * Plugin URI: https://www.web-crossing.com
 * Description: Sends Contact Form 7 Fields to Reguest
 * Version: 2.8
 * Author: Ing. Christian Fohrmann
 * Author URI: https://www.alpinmarketing.at
 */
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );


/**
 * Simple class for Reguest Calls 
 */
class ReguestAPIClient {
    // Base Url
    private $baseUrl;
    // Client (current curl)
    private $client;
    // Options for client
    private $options;

    /**
     * __construct
     * 
     * @param string $url
     * @param string $username
     * @param string $password
     * 
     * @return void
     */
    public function __construct(string $url, string $username, string $password) {

        assert(!empty($url));
        assert(!empty($username));
        assert(!empty($password));

        $this->baseUrl = $url .'/v1/ReGuest/Requests';

        $this->options = [
            CURLOPT_URL => $this->baseUrl,
			CURLOPT_RETURNTRANSFER => 1,
			CURLOPT_POST => 1,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'User-Agent: ReguestWordpressApiClient/1.0',
                'Username: '.$username,
                'Password: '.$password,
                'ServiceAction: Add'
            ]
        ];
        $this->client = curl_init();
    }

    /**
     * send
     * 
     * @param array $form
     * 
     * @return bool
     */
    public function send(array $form, array $fields): bool {
        $roomOccupancies = ['Adults', 'Children', 'ChildrenAges'];

        $request = [
            'MealType'      => 0,
            'GuestUserType' => 0,
            'Gender'        => 0,
        ];

        foreach ($fields as $apiKey => $formFieldName) {
            // Skip if the form field name is empty or the field wasn't submitted in the form
            if (empty($formFieldName) || !isset($form[$formFieldName]) || $form[$formFieldName] === '') {
                continue;
            }

            $value = $form[$formFieldName];

            if ($apiKey === 'Anrede') {
                switch ($value) {
                    case 'Herr': case 'Mr':
                        $request['Gender'] = 1;
                        break;
                    case 'Frau': case 'Mrs':
                        $request['Gender'] = 2;
                        break;
                    case 'Firma': case 'Company':
                        // Corrected based on old code's logic and API expectation (2 = family/company)
                        $request['GuestUserType'] = 2;
                        break;
                }
            } elseif (in_array($apiKey, ['ArrivalDate', 'DepartureDate'])) {
                try {
                    $request[$apiKey] = (new DateTime($value))->format('Y-m-d');
                } catch (Exception $e) {
                    // Handle invalid date format gracefully
                    $request[$apiKey] = null;
                }
            } elseif ($apiKey === 'ChildrenAges') {
                // Clean the string and convert to an array of integers
                $agesArray = array_filter(preg_split('/[,\s\.]+/', $value), 'is_numeric');
                $request['RoomOccupancies'][0][$apiKey] = array_map('intval', $agesArray);
            } elseif (in_array($apiKey, $roomOccupancies)) { // Handles 'Adults' and 'Children'
                $request['RoomOccupancies'][0][$apiKey] = (int)$value;
            } else {
                $request[$apiKey] = $value;
            }
        }

        // Set LanguageCode as a fallback if not mapped
        if (!isset($request['LanguageCode'])) {
            if (isset($form['form_title']) && strpos($form['form_title'], 'EN') !== false) {
                $request['LanguageCode'] = 'en';
            } else {
                $request['LanguageCode'] = 'de';
            }
        }

        $this->options[CURLOPT_POSTFIELDS] = json_encode($request);
        curl_setopt_array($this->client,$this->options);
	    $return = json_decode(curl_exec($this->client),1);

        if($return['Success']) {
	        return true;
        } else {
	        return false;
        }
    }
}

/**
 *  Action send_to_reguest
 * 
 * @return void
 */
function send_to_reguest($contact_form) {
    $options = get_option('am_hotelfolio_reguest_options');

    // Exit if the plugin is not active or credentials are not set
    if (empty($options['active']) || empty($options['uri']) || empty($options['username']) || empty($options['password'])) {
        return;
    }
    
    $form_data = [];
    foreach($_POST as $k=>$v) {
        if(strpos($k,'_wpcf7') === false) {
            $form_data[$k] = $v;
        }
    }

    if( isset($form_data['reguest']) && strtolower($form_data['reguest']) !== 'false' ) {
        $form_data['form_title'] = strtoupper($contact_form->title);
        $apiClient = new ReguestAPIClient($options['uri'], $options['username'], $options['password']);
        return $apiClient->send($form_data, $options['form_mapping'] ?? []);
    }
}
add_action( 'wpcf7_before_send_mail', 'send_to_reguest', 10, 1 );


/**
 * Admin Settings Section
 */

function am_hotelfolio_reguest_add_admin_menu() {
    add_submenu_page(
        'hotelfolio_settings',
        'Reguest API Settings',
        'Reguest',
        'manage_options',
        'am_reguest',
        'am_hotelfolio_reguest_options_page_html'
    );
}
add_action('admin_menu', 'am_hotelfolio_reguest_add_admin_menu');

function am_hotelfolio_reguest_enqueue_admin_assets($hook) {
    // The hook for a submenu page is {parent_slug}_page_{submenu_slug}
    if ('hotelfolio_settings_page_am_reguest' !== $hook) {
        return;
    }
    wp_enqueue_style('am-hotelfolio-reguest-admin-style', plugin_dir_url(__FILE__) . 'am-hotelfolio-reguest-admin-style.css', [], '1.0');
    wp_enqueue_script('am-hotelfolio-reguest-admin-script', plugin_dir_url(__FILE__) . 'am-hotelfolio-reguest-admin-script.js', ['jquery'], '1.0', true);
}
add_action('admin_enqueue_scripts', 'am_hotelfolio_reguest_enqueue_admin_assets');


function am_hotelfolio_reguest_settings_init() {
    register_setting('am_hotelfolio_reguest_options_group', 'am_hotelfolio_reguest_options', 'am_hotelfolio_reguest_sanitize_options');

    add_settings_section('am_hotelfolio_reguest_main_section', 'API Credentials', null, 'am_reguest');

    add_settings_field('am_hotelfolio_reguest_active', 'Plugin aktiv', 'am_hotelfolio_reguest_field_active_cb', 'am_reguest', 'am_hotelfolio_reguest_main_section');
    add_settings_field('am_hotelfolio_reguest_username', 'Benutzername', 'am_hotelfolio_reguest_field_text_cb', 'am_reguest', 'am_hotelfolio_reguest_main_section', ['id' => 'username', 'type' => 'text']);
    add_settings_field('am_hotelfolio_reguest_password', 'Passwort', 'am_hotelfolio_reguest_field_text_cb', 'am_reguest', 'am_hotelfolio_reguest_main_section', ['id' => 'password', 'type' => 'password']);
    add_settings_field('am_hotelfolio_reguest_uri', 'API Link', 'am_hotelfolio_reguest_field_text_cb', 'am_reguest', 'am_hotelfolio_reguest_main_section', ['id' => 'uri', 'type' => 'url']);

    add_settings_section('am_hotelfolio_reguest_form_section', 'Form Field Mapping', null, 'am_reguest');
    add_settings_field('am_hotelfolio_reguest_form_mapping', 'API Field => Form Field', 'am_hotelfolio_reguest_field_mapping_cb', 'am_reguest', 'am_hotelfolio_reguest_form_section');
}
add_action('admin_init', 'am_hotelfolio_reguest_settings_init');

/**
 * Run a one-time migration of old settings to the new options array.
 * This ensures that settings from versions before the Settings API are not lost.
 */
function am_hotelfolio_reguest_run_migration() {
    $migration_flag = 'am_hotelfolio_reguest_migrated_to_v2_5';

    // 1. Exit immediately if the migration has already been completed.
    if (get_option($migration_flag)) {
        return;
    }

    // 2. Check for the existence of at least one old option to trigger the migration.
    if (get_option('webx_reguest_username') !== false) {
        // Get any existing new options to merge with, ensuring no data is lost.
        $options = get_option('am_hotelfolio_reguest_options', []);

        // 3. Collect all old data with safe defaults.
        $migrated_data = [
            'active'       => get_option('webx_reguest_active', null),
            'username'     => get_option('webx_reguest_username', ''),
            'password'     => get_option('webx_reguest_password', ''),
            'uri'          => get_option('webx_reguest_uri', ''),
            'form_mapping' => get_option('webx_reguest_form', []),
        ];

        // 4. Merge old data into the new options array. Migrated data takes precedence.
        $final_options = array_merge($options, $migrated_data);
        update_option('am_hotelfolio_reguest_options', $final_options);

        // 5. Clean up by deleting all old, separate options.
        $old_options_to_delete = [
            'webx_reguest_active',
            'webx_reguest_username',
            'webx_reguest_password',
            'webx_reguest_uri',
            'webx_reguest_form'
        ];
        foreach ($old_options_to_delete as $old_option) {
            delete_option($old_option);
        }

        // 6. Set the flag to ensure this migration never runs again.
        update_option($migration_flag, true);
    }
}
add_action('admin_init', 'am_hotelfolio_reguest_run_migration');

function am_hotelfolio_reguest_sanitize_options($input) {
    $sanitized_input = [];
    $options = get_option('am_hotelfolio_reguest_options');

    $sanitized_input['active'] = isset($input['active']) ? '1' : null;
    $sanitized_input['username'] = isset($input['username']) ? sanitize_text_field($input['username']) : '';
    $sanitized_input['uri'] = isset($input['uri']) ? esc_url_raw($input['uri']) : '';

    // Only update password if a new one is provided
    if (!empty($input['password'])) {
        $sanitized_input['password'] = $input['password'];
    } else {
        $sanitized_input['password'] = $options['password'] ?? '';
    }

    if (isset($input['form_mapping']) && is_array($input['form_mapping'])) {
        $sanitized_input['form_mapping'] = [];
        foreach ($input['form_mapping'] as $key => $value) {
            // Sanitize key as text to preserve case (e.g., 'ArrivalDate'). sanitize_key() would incorrectly lowercase it.
            $sanitized_input['form_mapping'][sanitize_text_field($key)] = sanitize_text_field($value);
        }
    }

    return $sanitized_input;
}

function am_hotelfolio_reguest_field_active_cb() {
    $options = get_option('am_hotelfolio_reguest_options');
    $checked = isset($options['active']) ? 'checked' : '';
    echo "<input type='checkbox' name='am_hotelfolio_reguest_options[active]' value='1' {$checked} />";
}

function am_hotelfolio_reguest_field_text_cb($args) {
    $options = get_option('am_hotelfolio_reguest_options');
    $id = $args['id'];
    $type = $args['type'];
    $value = $options[$id] ?? '';
    $placeholder = ($type === 'password') ? 'Zum Ändern neu eingeben' : '';
    $value_attr = ($type === 'password') ? '' : 'value="' . esc_attr($value) . '"';

    echo "<input type='{$type}' name='am_hotelfolio_reguest_options[{$id}]' {$value_attr} placeholder='{$placeholder}' class='regular-text' />";
}

function am_hotelfolio_reguest_field_mapping_cb() {
    $options = get_option('am_hotelfolio_reguest_options');
    $mappings = $options['form_mapping'] ?? [];
    $api_fields = [
        'ArrivalDate' => 'Ankunft',
        'DepartureDate' => 'Abreise',
        'Anrede' => 'Anrede',
        'EmailAddress' => 'E-Mail',
        'Adults' => 'Erwachsene',
        'Children' => 'Kinder',
        'ChildrenAges' => 'Kinderalter',
        'FirstName' => 'Vorname',
        'LastName' => 'Nachname',
        'CompanyName' => 'Firma',
        'CountryCode' => 'Ländercode',
        'StreetName' => 'Straße',
        'PostalCode' => 'Postleitzahl',
        'CityName' => 'Stadt',
        'PhoneNumber' => 'Telefonnummer',
        'MobileNumber' => 'Mobilnummer',
        'Text' => 'Text',
        'LanguageCode' => 'Sprache',
    ];

    echo '<div id="am_hotelfolio_reguest_form_mapping">';
    if (!empty($mappings)) {
        foreach ($mappings as $key => $value) {
            echo '<div class="mapping-row">';
            echo '<label for="am_hotelfolio_reguest_options_form_mapping_' . esc_attr($key) . '">' . esc_html($key) . '</label>';
            echo '<input type="text" name="am_hotelfolio_reguest_options[form_mapping][' . esc_attr($key) . ']" value="' . esc_attr($value) . '" placeholder="Contact Form 7 field name" data-key="' . esc_attr($key) . '" class="regular-text" />';
            echo '<button type="button" class="button button-secondary remove-mapping-row">Entfernen</button>';
            echo '</div>';
        }
    }
    echo '</div>';

    echo '<div style="margin-top: 20px;">';
    echo '<select id="am_hotelfolio_reguest_prototypes">';
    echo '<option value="">-- API Feld auswählen --</option>';
    foreach ($api_fields as $key => $label) {
        echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
    }
    echo '</select> ';
    echo '<button type="button" class="button prototype-button" data-func="add">Hinzufügen</button> ';
    echo '</div>';
}


function am_hotelfolio_reguest_options_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap am-hotelfolio-reguest-settings-form">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('am_hotelfolio_reguest_options_group');
            do_settings_sections('am_reguest');
            submit_button('Änderungen speichern');
            ?>
        </form>
    </div>
    <?php
}