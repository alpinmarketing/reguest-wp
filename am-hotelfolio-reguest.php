<?php
/**
 * Plugin Name: AM Hotelfolio Reguest
 * Plugin URI: https://www.web-crossing.com
 * Description: Sends Contact Form 7 Fields to Reguest
 * Version: 3.0
 * Author: Ing. Christian Fohrmann
 * Author URI: https://www.alpinmarketing.at
 */
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

/**
 * Logs messages either to a transient for debugging or to the standard PHP error log.
 *
 * @param string $message The error message to log.
 */
function am_hotelfolio_reguest_log_error(string $message) {
    $options = get_option('am_hotelfolio_reguest_options');

    if (!empty($options['debug'])) {
        $log_transient_key = 'am_hotelfolio_reguest_debug_log';
        $logs = get_transient($log_transient_key);
        if (false === $logs || !is_array($logs)) {
            $logs = [];
        }

        // Add new log entry to the beginning of the array with a timestamp.
        array_unshift($logs, date('Y-m-d H:i:s') . ' - ' . $message);

        // Keep the log from growing indefinitely (e.g., max 100 entries).
        $logs = array_slice($logs, 0, 100);

        set_transient($log_transient_key, $logs, WEEK_IN_SECONDS);
    } else {
        error_log('ReGuest Plugin: ' . $message);
    }
}

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
    // A list of all known API keys in their correct PascalCase format.
    private static $knownApiKeys = [
        'EmailAddress', 'ArrivalDate', 'DepartureDate', 'MealType', 'GuestUserType',
        'Gender', 'Anrede', 'Title', 'FirstName', 'LastName', 'FullName', 'FamilyName',
        'CompanyName', 'BirthDate', 'StreetName', 'PostalCode', 'CityName', 'CountryCode',
        'PhoneNumber', 'MobileNumber', 'FaxNumber', 'Text', 'LanguageCode',
        'NewsletterSubscription', 'AlternativeArrivalDate', 'AlternativeDepartureDate',
        'OfferName', 'OfferCode', 'ThirdPartyNotes', 'ForeignId', 'SourceOfBusiness',
        'Adults', 'Children', 'ChildrenAges'
    ];

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
        if (empty($url) || empty($username) || empty($password)) {
            throw new InvalidArgumentException('URL, username, and password are required.');
        }

        // Ensure the base URL doesn't have a trailing slash before appending the path
        $this->baseUrl = rtrim($url, '/') . '/v1/ReGuest/Requests';

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

    public function __destruct() {
        if (is_resource($this->client)) {
            curl_close($this->client);
        }
    }

    /**
     * send
     * 
     * @param array $form The submitted form data.
     * @param array $fields The mapping of API keys to form field names.
     * @param array $meta_data Additional data to include in the request.
     * 
     * @return bool
     */
    public function send(array $form, array $fields, array $meta_data = [], bool $debug = false): bool {
        $roomOccupancies = ['Adults', 'Children', 'ChildrenAges'];
        $dateFields = ['ArrivalDate', 'DepartureDate', 'AlternativeArrivalDate', 'AlternativeDepartureDate', 'BirthDate'];
        $booleanFields = ['NewsletterSubscription'];

        $request = [
            // Set required fields with default values
            'MealType'      => 0,
            'GuestUserType' => 0,
            'Gender'        => 0,
        ];

        // Create a map of lowercase keys to their correct PascalCase version for normalization.
        // This makes the mapping in the admin settings case-insensitive.
        $keyMap = [];
        foreach (self::$knownApiKeys as $key) {
            $keyMap[strtolower($key)] = $key;
        }

        foreach ($fields as $apiKey => $formFieldName) {
            // Skip if the form field name is empty or the field wasn't submitted in the form
            if (empty($formFieldName) || !isset($form[$formFieldName]) || $form[$formFieldName] === '') {
                continue;
            }

            // Normalize the API key from the settings to the correct case (e.g., 'adults' -> 'Adults').
            // If the key is not in our known list, we use it as-is to allow for future API fields.
            $normalizedApiKey = $keyMap[strtolower($apiKey)] ?? $apiKey;

            $value = $form[$formFieldName];

            if ($normalizedApiKey === 'Anrede') {
                switch ($value) {
                    case 'Herr': case 'Mr':
                        $request['Gender'] = 1;
                        break;
                    case 'Frau': case 'Mrs':
                        $request['Gender'] = 2;
                        break;
                    case 'Firma': case 'Company': // Corrected mapping: 1 = company
                        $request['GuestUserType'] = 1;
                        break;
                }
            } elseif ($normalizedApiKey === 'CountryCode') {
                $countryCode = $this->get_country_code_from_name($value);
                if ($countryCode) { // Only set if a valid code was found
                    $request[$normalizedApiKey] = $countryCode;
                }
                // If no valid country code is found (e.g., for "Other Country"),
                // the field is simply not added to the request, preventing an API error.
            } elseif (in_array($normalizedApiKey, $dateFields)) {
                try {
                    $request[$normalizedApiKey] = (new DateTime($value))->format('Y-m-d');
                } catch (Exception $e) {
                    // Handle invalid date format gracefully
                    am_hotelfolio_reguest_log_error("Invalid date format for {$normalizedApiKey}: " . $value);
                    $request[$normalizedApiKey] = null;
                }
            } elseif ($normalizedApiKey === 'ChildrenAges') {
                // Clean the string and convert to an array of integers
                $agesArray = array_filter(preg_split('/[,\s\.]+/', $value), 'is_numeric');
                $request['RoomOccupancies'][0][$normalizedApiKey] = array_map('intval', $agesArray);
            } elseif (in_array($normalizedApiKey, $booleanFields)) {
                // Convert common string representations of 'true' to a boolean
                $request[$normalizedApiKey] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (in_array($normalizedApiKey, $roomOccupancies)) { // Handles 'Adults' and 'Children'
                $request['RoomOccupancies'][0][$normalizedApiKey] = (int)$value;
            } else {
                $request[$normalizedApiKey] = $value;
            }
        }

        // --- Pre-flight Validation based on API requirements ---

        // 1. Validate Email Address format
        if (isset($request['EmailAddress']) && !filter_var($request['EmailAddress'], FILTER_VALIDATE_EMAIL)) {
            am_hotelfolio_reguest_log_error("Aborting send due to invalid email address format: " . $request['EmailAddress']);
            return false; // Stop processing if email is invalid
        }

        // 2. Validate Date Plausibility
        try {
            if (isset($request['ArrivalDate'], $request['DepartureDate']) && new DateTime($request['ArrivalDate']) >= new DateTime($request['DepartureDate'])) {
                am_hotelfolio_reguest_log_error("Aborting send. DepartureDate must be after ArrivalDate.");
                return false;
            }
            if (isset($request['AlternativeArrivalDate'], $request['AlternativeDepartureDate']) && new DateTime($request['AlternativeArrivalDate']) >= new DateTime($request['AlternativeDepartureDate'])) {
                am_hotelfolio_reguest_log_error("Aborting send. AlternativeDepartureDate must be after AlternativeArrivalDate.");
                return false;
            }
        } catch (Exception $e) {
            // This case is already handled during date parsing, but serves as a safeguard.
            am_hotelfolio_reguest_log_error("Aborting send due to invalid date for comparison. " . $e->getMessage());
            return false;
        }

        // 3. Validate Room Occupancy consistency
        if (isset($request['RoomOccupancies'][0])) {
            $numChildren = $request['RoomOccupancies'][0]['Children'] ?? 0;
            $numAges = isset($request['RoomOccupancies'][0]['ChildrenAges']) ? count($request['RoomOccupancies'][0]['ChildrenAges']) : 0;
            if ($numChildren > 0 && $numChildren !== $numAges) {
                am_hotelfolio_reguest_log_error("Aborting send. Mismatch between number of children ({$numChildren}) and provided ages ({$numAges}).");
                return false;
            }
        }

        // Apply business rules based on GuestUserType after gathering all data.
        // The default is 0 (person) if not set otherwise.
        $guestType = $request['GuestUserType'] ?? 0;

        switch ($guestType) {
            case 1: // Company
                $request['Gender'] = 0;
                // Per API docs, for a company, only use CompanyName.
                unset($request['FirstName'], $request['LastName'], $request['FamilyName'], $request['BirthDate'], $request['Title'], $request['FullName']);
                break;

            case 2: // Family
                $request['Gender'] = 0;
                // Per API docs, for a family, use FamilyName or FirstName/LastName. Not CompanyName or a specific BirthDate.
                unset($request['CompanyName'], $request['BirthDate']);
                break;

            case 0: // Person
            default:
                // Per API docs, for a person, use FirstName/LastName or FullName. Not Company/Family name.
                unset($request['CompanyName'], $request['FamilyName']);
                // If FullName is provided, it takes precedence over FirstName/LastName.
                if (!isset($request['FirstName']) && !isset($request['LastName']) && !empty($request['FullName'])) {
                    // Re:Guest will split the name automatically.
                }
                break;
        }


        // Merge automatically populated metadata. Values from $request (form mapping) will overwrite metadata.
        $request = array_merge($meta_data, $request);

        // Set LanguageCode as a fallback if not set by mapping or metadata
        if (!isset($request['LanguageCode'])) {
            if (isset($form['form_title']) && strpos($form['form_title'], 'EN') !== false) {
                $request['LanguageCode'] = 'en';
            } else {
                $request['LanguageCode'] = 'de';
            }
        }

        // If debug mode is active, log the request payload and skip the actual API call.
        if ($debug) {
            // Use JSON_PRETTY_PRINT for better readability in the log.
            $json_payload = json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            am_hotelfolio_reguest_log_error("DEBUG MODUS: API-Aufruf übersprungen. Folgende Daten wären gesendet worden:\n" . $json_payload);
            return true; // Simulate a successful submission for debugging purposes.
        }

        $this->options[CURLOPT_POSTFIELDS] = json_encode($request);
        curl_setopt_array($this->client,$this->options);
        $response_body = curl_exec($this->client);

        // Check for cURL errors
        if (curl_errno($this->client)) {
            am_hotelfolio_reguest_log_error('cURL Error: ' . curl_error($this->client));
            return false;
        }

        // Check for non-successful HTTP status codes
        $http_code = curl_getinfo($this->client, CURLINFO_HTTP_CODE);
        if ($http_code < 200 || $http_code >= 300) {
            // Try to decode the error response for a more specific message
            $error_details = json_decode($response_body, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($error_details['ExceptionMessage'])) {
                $error_message = "API Error: " . $error_details['ExceptionMessage'];
            } else {
                $error_message = "Raw Response: " . $response_body;
            }
            am_hotelfolio_reguest_log_error("HTTP Error: Status code {$http_code}. " . $error_message);
            return false;
        }

        $return = json_decode($response_body, true);

        // Check for JSON decoding errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            am_hotelfolio_reguest_log_error('JSON Decode Error: ' . json_last_error_msg());
            return false;
        }

        // Check for the 'Success' flag in the API response
        $is_success = isset($return['Success']) && $return['Success'] === true;
        return $is_success;
    }

    /**
     * Converts a country name to its ISO 3166-1 alpha-2 code.
     *
     * @param string $name The full name of the country provided by the form.
     * @return string|null The two-letter country code or null if not found.
     */
    private function get_country_code_from_name(string $name): ?string
    {
        // Using a static variable to avoid re-creating the map on every call within the same request.
        static $countryMap = [
            'deutschland' => 'DE',
            'österreich' => 'AT',
            'schweiz' => 'CH',
            'belgien' => 'BE',
            'bulgarien' => 'BG',
            'kroatien' => 'HR',
            'tschechien' => 'CZ',
            'dänemark' => 'DK',
            'estland' => 'EE',
            'finnland' => 'FI',
            'frankreich' => 'FR',
            'griechenland' => 'GR',
            'ungarn' => 'HU',
            'irland' => 'IE',
            'italien' => 'IT',
            'lettland' => 'LV',
            'litauen' => 'LT',
            'luxemburg' => 'LU',
            'malta' => 'MT',
            'niederlande' => 'NL',
            'polen' => 'PL',
            'portugal' => 'PT',
            'rumänien' => 'RO',
            'slowakei' => 'SK',
            'slowenien' => 'SI',
            'spanien' => 'ES',
            'schweden' => 'SE',
            'zypern' => 'CY',
            'united kingdom' => 'GB',
            'usa' => 'US',
        ];

        $normalizedName = strtolower(trim($name));
        return $countryMap[$normalizedName] ?? null;
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

    // Automatically populate meta data supported by the API
    $meta_data = [];
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $meta_data['ClientIpAddress'] = sanitize_text_field($_SERVER['REMOTE_ADDR']);
    }
    if (!empty($_SERVER['HTTP_USER_AGENT'])) {
        $meta_data['UserAgent'] = sanitize_text_field($_SERVER['HTTP_USER_AGENT']);
    }
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $meta_data['OriginUrl'] = esc_url_raw($_SERVER['HTTP_REFERER']);
    }

    // Add language code from CF7 locale, e.g., 'de_DE' -> 'de'
    $locale = $contact_form->locale ?? '';
    if (!empty($locale)) {
        $meta_data['LanguageCode'] = substr($locale, 0, 2);
    }

    if( isset($form_data['reguest']) && strtolower($form_data['reguest']) !== 'false' ) {
        $form_data['form_title'] = strtoupper($contact_form->title());
        $apiClient = new ReguestAPIClient($options['uri'], $options['username'], $options['password']);
        $is_debug_mode = !empty($options['debug']);
        // Call the API but do not return its result to prevent blocking the CF7 submission.
        $apiClient->send($form_data, $options['form_mapping'] ?? [], $meta_data, $is_debug_mode);
    }
}
add_action( 'wpcf7_before_send_mail', 'send_to_reguest', 10, 1 );


/**
 * Admin Settings Section
 */

function am_hotelfolio_reguest_add_admin_menu() {
    add_options_page('Reguest API Settings', 'Reguest', 'manage_options', 'am-hotelfolio-reguest', 'am_hotelfolio_reguest_options_page_html');
}
add_action('admin_menu', 'am_hotelfolio_reguest_add_admin_menu');

function am_hotelfolio_reguest_enqueue_admin_assets($hook) {
    if ('settings_page_am-hotelfolio-reguest' !== $hook) {
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
    add_settings_field('am_hotelfolio_reguest_debug', 'Debug Modus', 'am_hotelfolio_reguest_field_debug_cb', 'am_reguest', 'am_hotelfolio_reguest_main_section');
    add_settings_field('am_hotelfolio_reguest_username', 'Benutzername', 'am_hotelfolio_reguest_field_text_cb', 'am_reguest', 'am_hotelfolio_reguest_main_section', ['id' => 'username', 'type' => 'text']);
    add_settings_field('am_hotelfolio_reguest_password', 'Passwort', 'am_hotelfolio_reguest_field_text_cb', 'am_reguest', 'am_hotelfolio_reguest_main_section', ['id' => 'password', 'type' => 'password']);
    add_settings_field('am_hotelfolio_reguest_uri', 'API Link', 'am_hotelfolio_reguest_field_text_cb', 'am_reguest', 'am_hotelfolio_reguest_main_section', ['id' => 'uri', 'type' => 'url']);

    add_settings_section('am_hotelfolio_reguest_form_section', 'Form Field Mapping', null, 'am_reguest');
    add_settings_field('am_hotelfolio_reguest_form_mapping', 'API Field => Form Field', 'am_hotelfolio_reguest_field_mapping_cb', 'am_reguest', 'am_hotelfolio_reguest_form_section');
}
add_action('admin_init', 'am_hotelfolio_reguest_settings_init');

/**
 * Handle admin actions, like clearing the debug log.
 */
function am_hotelfolio_reguest_handle_admin_actions() {
    if (isset($_GET['page']) && $_GET['page'] === 'am-hotelfolio-reguest' && isset($_GET['action']) && $_GET['action'] === 'clear_log') {
        if (isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_key($_GET['_wpnonce']), 'am_reguest_clear_log_action')) {
            delete_transient('am_hotelfolio_reguest_debug_log');
            // Redirect to the settings page with a success message.
            wp_safe_redirect(admin_url('admin.php?page=am-hotelfolio-reguest&log_cleared=1'));
            exit;
        }
    }
}
add_action('admin_init', 'am_hotelfolio_reguest_handle_admin_actions');

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
    $sanitized_input['debug'] = isset($input['debug']) ? '1' : null;
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

function am_hotelfolio_reguest_field_debug_cb() {
    $options = get_option('am_hotelfolio_reguest_options');
    $checked = isset($options['debug']) ? 'checked' : '';
    echo "<input type='checkbox' name='am_hotelfolio_reguest_options[debug]' value='1' {$checked} /> <p class='description'>Wenn aktiviert, werden Fehler auf dieser Seite protokolliert, anstatt im Server-Log. Deaktivieren Sie dies im Live-Betrieb.</p>";
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
    // Expanded list of API fields based on the documentation
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
    $options = get_option('am_hotelfolio_reguest_options');
    ?>
    <div class="wrap am-hotelfolio-reguest-settings-form">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <?php if (isset($_GET['log_cleared'])) : ?>
            <div id="message" class="updated notice is-dismissible"><p>Debug-Log wurde geleert.</p></div>
        <?php endif; ?>
        <form action="options.php" method="post">
            <?php
            settings_fields('am_hotelfolio_reguest_options_group');
            do_settings_sections('am_reguest');
            submit_button('Änderungen speichern');
            ?>
        </form>
        <?php if (!empty($options['debug'])) : ?>
            <hr>
            <h2>Debug Log</h2>
            <p>Hier werden die letzten 100 Fehler angezeigt, die bei der Kommunikation mit der Re:Guest API aufgetreten sind.</p>
            <?php
            $logs = get_transient('am_hotelfolio_reguest_debug_log');
            if (!empty($logs) && is_array($logs)) {
                echo '<textarea readonly style="width: 100%; height: 300px; background: #f0f0f0; font-family: monospace; white-space: pre; color: #333;">';
                echo esc_textarea(implode("\n", $logs));
                echo '</textarea>';
                $clear_log_url = wp_nonce_url(admin_url('admin.php?page=am-hotelfolio-reguest&action=clear_log'), 'am_reguest_clear_log_action');
                echo '<p><a href="' . esc_url($clear_log_url) . '" class="button button-secondary">Log leeren</a></p>';
            } else {
                echo '<p>Das Log ist leer.</p>';
            }
            ?>
        <?php endif; ?>
    </div>
    <?php
}