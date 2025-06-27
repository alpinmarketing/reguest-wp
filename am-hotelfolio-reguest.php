<?php
/**
 * Plugin Name: AM Hotelfolio Reguest
 * Plugin URI: https://www.web-crossing.com
 * Description: Sends Contact Form 7 Fields to Reguest
 * Version: 2.0
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

        if (empty($url)) {
            throw new InvalidArgumentException(__('API URL cannot be empty.', 'webx-reguest'), 0);
        }
        if (empty($username)) {
            throw new InvalidArgumentException(__('API username cannot be empty.', 'webx-reguest'), 0);
        }
        if (empty($password)) {
            throw new InvalidArgumentException(__('API password cannot be empty.', 'webx-reguest'), 0);
        }

        // Ensure base URL is clean and ends correctly
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
            ],
            CURLOPT_TIMEOUT => 30, // Max seconds to allow cURL functions to execute
            CURLOPT_CONNECTTIMEOUT => 10, // Max seconds to wait for a connection
            // CURLOPT_SSL_VERIFYPEER => true, // Enable SSL certificate verification
            // CURLOPT_SSL_VERIFYHOST => 2, // Verify the common name exists and matches the hostname
        ];
        $this->client = curl_init();
        curl_setopt_array($this->client, $this->options); // Set initial options
    }

    /**
     * send
     * 
     * @param array $form
     * 
     * @return bool
     */
    public function send($form) {
        $fields = get_option('webx_reguest_form', []); // Default to empty array if not set
        $roomOccupancies = ['Adults','Children','ChildrenAges'];
        
        // Robust processing for 'kinderalter' (children's ages)
        if (isset($form['kinderalter'])) {
            // Replace any non-digit sequence with a single comma, then trim leading/trailing commas
            $cleaned_ages_string = trim(preg_replace('/[^0-9]+/', ',', $form['kinderalter']), ',');
            $ages_array = explode(',', $cleaned_ages_string);
            
            $valid_ages = [];
            foreach ($ages_array as $age) {
                if (is_numeric($age) && (int)$age >= 0) { // Ensure it's a non-negative integer
                    $valid_ages[] = (int) $age;
                }
            }
            $form['kinderalter'] = $valid_ages;
        } else {
            $form['kinderalter'] = []; // Ensure it's an array even if not set
        }

        $request = [
            'MealType' => 0,            // Currently not avail. 
                                        // values if implemented
                                        // 0: n/a
                                        // 1: bed & breakfast
                                        // 2: half board
                                        // 3: 3/4 board
                                        // 4: full board
                                        // 5: overnight stay only
                                        // 6: all inclusive
            'GuestUserType' => 0,       // Currently not avail.
                                        // values if implemented
                                        // 0: person
                                        // 1: company
                                        // 2: family
            'Gender' => 0,              // Currently not avail. 
                                        // values if implemented
                                        // 0: unknown
                                        // 1: male
                                        // 2: female
        ];

        // Process 'anrede' (salutation) directly from the form data
        if (isset($form['anrede'])) {
            switch(strtolower($form['anrede'])) { // Use strtolower for case-insensitivity
                case 'herr': case 'mr': $request['Gender'] = 1; break;
                case 'frau': case 'mrs': $request['Gender'] = 2; break;
                case 'firma': case 'company': $request['GuestUserType'] = 2; break;
                default: break;
            }
        }


        foreach($fields as $k=>$v) {
            if (!isset($form[$v])) { // Skip if the form field was not submitted
                continue;
            }

            if(in_array($k, $roomOccupancies)) { // Handle RoomOccupancies fields
                if ($k === 'ChildrenAges') {
                    // Assuming $form[$v] (which is $form['kinderalter']) is already processed into an array of integers
                    $request['RoomOccupancies'][0]['ChildrenAges'] = $form[$v];
                } else {
                    // For 'Adults', 'Children' - ensure they are integers
                    $request['RoomOccupancies'][0][$k] = (int) $form[$v];
                }
            } else if(in_array($k,['ArrivalDate','DepartureDate'])) { // Handle date fields
                try {
                    $request[$k] = (new DateTime($form[$v]))->format('Y-m-d');
                } catch (Exception $e) {
                    error_log('Webx Reguest: Invalid date format for ' . $k . ': ' . $form[$v] . ' - ' . $e->getMessage());
                    $request[$k] = null; // Or handle as appropriate for the API
                }
            } else {
                $request[$k]=$form[$v];
            }
        }

        if(!isset($request['LanguageCode'])) {
            if(isset($form['form_title']) && strpos(strtoupper($form['form_title']),'EN') !== false) {
                $request['LanguageCode'] = 'en';
            } else {
                $request['LanguageCode'] = 'de';
            }
        }  

        // Set the POST fields for the current request
        curl_setopt($this->client, CURLOPT_POSTFIELDS, json_encode($request));
        
	    $response = curl_exec($this->client);
        
        if ($response === false) {
            $error_msg = curl_error($this->client);
            $error_code = curl_errno($this->client);
            error_log('Webx Reguest cURL Error: [' . $error_code . '] ' . $error_msg);
            return false;
        }

        $return = json_decode($response, true); // Use true for associative array

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('Webx Reguest JSON Decode Error: ' . json_last_error_msg() . ' - Response: ' . $response);
            return false;
        }

        if(isset($return['Success']) && $return['Success']) {
	        return true;
        } else {
            error_log('Webx Reguest API Error: ' . json_encode($return)); // Log the full response for debugging
	        return false;
        }
    }

    /**
     * Close cURL handle when the object is destroyed.
     */
    public function __destruct() {
        if (is_resource($this->client)) { // Check if cURL handle is still valid
            curl_close($this->client);
        }
    }
}

/**
 *  Action send_to_reguest
 * 
 * @return void
 */
function send_to_reguest($contact_form) { // Type hint WPCF7_ContactForm if available
    // Collect form data, excluding WPCF7 internal fields
    $form = [];
    foreach($_POST as $k=>$v) {
        if(strpos($k,'_wpcf7') === false) {
            $form[$k]=$v;
        }
    }

    // Check if the 'reguest' field is present and not explicitly 'false'
    if( $form['reguest'] && strtolower($form['reguest']) != 'false' ) {
        $form['form_title'] = strtoupper($contact_form->title);

        try {
            $apiClient = new ReguestAPIClient(
                get_option('webx_reguest_uri'),
                get_option('webx_reguest_username'),
                get_option('webx_reguest_password')
            );
            return $apiClient->send($form);
        } catch (InvalidArgumentException $e) {
            error_log('Webx Reguest API Client Error: ' . $e->getMessage());
            return false; // Indicate failure to CF7
        } catch (Exception $e) { // Catch any other unexpected exceptions
            error_log('Webx Reguest Unexpected Error: ' . $e->getMessage());
            return false;
        }
    }
    // If 'reguest' field is not set or is 'false', do nothing and let CF7 proceed
    return true; // Indicate that the CF7 process should continue
}

/**
 * Action webx_reguest_menu
 * 
 * @return void
 */
function webx_reguest_menu() {
    add_options_page(
        __( 'Reguest Settings', 'webx-reguest' ), // Page title
        __( 'Reguest', 'webx-reguest' ),          // Menu title
        'manage_options',   // Capability required
        'webx_reguest_menu_settings', // Menu slug
        'webx_reguest_menu_settings_page_callback' // Callback function
    );
}

/**
 * Register plugin settings, sections, and fields.
 * 
 * @return void
 */
function webx_reguest_register_settings() {
    // Register a setting for the plugin activation checkbox
    register_setting(
        'webx_reguest_settings_group', // Option group
        'webx_reguest_active',         // Option name
        array(
            'type' => 'boolean',
            'sanitize_callback' => 'webx_reguest_sanitize_checkbox',
            'default' => false,
        )
    );

    // Register settings for API credentials
    register_setting(
        'webx_reguest_settings_group',
        'webx_reguest_username',
        array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => '',
        )
    );
    register_setting(
        'webx_reguest_settings_group',
        'webx_reguest_password',
        array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field', // Passwords should ideally be encrypted or handled more securely
            'default' => '',
        )
    );
    register_setting(
        'webx_reguest_settings_group',
        'webx_reguest_uri',
        array(
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        )
    );

    // Register setting for form field mappings (array)
    register_setting(
        'webx_reguest_settings_group',
        'webx_reguest_form',
        array(
            'type' => 'array',
            'sanitize_callback' => 'webx_reguest_sanitize_form_mappings',
            'default' => array(),
        )
    );

    // Add a settings section
    add_settings_section(
        'webx_reguest_main_section', // ID
        __( 'Reguest API Settings', 'webx-reguest' ), // Title
        'webx_reguest_main_section_callback', // Callback
        'webx_reguest_menu_settings' // Page slug
    );

    // Add fields to the section
    add_settings_field(
        'webx_reguest_active_field',
        __( 'Plugin Active', 'webx-reguest' ),
        'webx_reguest_active_callback',
        'webx_reguest_menu_settings',
        'webx_reguest_main_section'
    );
    add_settings_field(
        'webx_reguest_username_field',
        __( 'Username', 'webx-reguest' ),
        'webx_reguest_username_callback',
        'webx_reguest_menu_settings',
        'webx_reguest_main_section'
    );
    add_settings_field(
        'webx_reguest_password_field',
        __( 'Password', 'webx-reguest' ),
        'webx_reguest_password_callback',
        'webx_reguest_menu_settings',
        'webx_reguest_main_section'
    );
    add_settings_field(
        'webx_reguest_uri_field',
        __( 'API Endpoint URL', 'webx-reguest' ),
        'webx_reguest_uri_callback',
        'webx_reguest_menu_settings',
        'webx_reguest_main_section'
    );
    add_settings_field(
        'webx_reguest_form_mappings_field',
        __( 'Form Field Mappings', 'webx-reguest' ),
        'webx_reguest_form_mappings_callback',
        'webx_reguest_menu_settings',
        'webx_reguest_main_section'
    );
}
add_action( 'admin_init', 'webx_reguest_register_settings' );

/**
 * Sanitize callback for the 'webx_reguest_active' checkbox.
 *
 * @param string $input The checkbox value.
 * @return string|null
 */
function webx_reguest_sanitize_checkbox( $input ) {
    return ( $input === '1' ) ? '1' : null;
}

/**
 * Sanitize callback for the 'webx_reguest_form' array.
 *
 * @param array $input The array of form mappings.
 * @return array
 */
function webx_reguest_sanitize_form_mappings( $input ) {
    $sanitized_mappings = array();
    if ( is_array( $input ) ) {
        foreach ( $input as $k => $v ) {
            $sanitized_mappings[ sanitize_key( $k ) ] = sanitize_text_field( $v );
        }
    }
    return $sanitized_mappings;
}

/**
 * Callback for the main settings section.
 *
 * @return void
 */
function webx_reguest_main_section_callback() {
    echo '<p>' . esc_html__( 'Configure your Reguest API connection details and map your Contact Form 7 fields.', 'webx-reguest' ) . '</p>';
}

/**
 * Callback for the 'webx_reguest_active' field.
 *
 * @return void
 */
function webx_reguest_active_callback() {
    $active = get_option( 'webx_reguest_active' );
    ?>
    <input class="checkbox" type="checkbox" name="webx_reguest_active" id="webx_reguest_active" value="1" <?php checked(1, $active); ?>/>
    <label for="webx_reguest_active"><?php esc_html_e('Enable Reguest integration', 'webx-reguest'); ?></label>
    <?php
}

/**
 * Callback for the 'webx_reguest_username' field.
 *
 * @return void
 */
function webx_reguest_username_callback() {
    $username = get_option( 'webx_reguest_username' );
    ?>
    <input type="text" name="webx_reguest_username" id="webx_reguest_username" class="regular-text" placeholder="<?php esc_attr_e('API Username', 'webx-reguest'); ?>" value="<?php echo esc_attr($username); ?>" />
    <?php
}

/**
 * Callback for the 'webx_reguest_password' field.
 *
 * @return void
 */
function webx_reguest_password_callback() {
    $password = get_option( 'webx_reguest_password' );
    ?>
    <input type="password" name="webx_reguest_password" id="webx_reguest_password" class="regular-text" placeholder="<?php esc_attr_e('API Password', 'webx-reguest'); ?>" value="<?php echo esc_attr($password); ?>" />
    <?php
}

/**
 * Callback for the 'webx_reguest_uri' field.
 *
 * @return void
 */
function webx_reguest_uri_callback() {
    $uri = get_option( 'webx_reguest_uri' );
    ?>
    <input type="url" name="webx_reguest_uri" id="webx_reguest_uri" class="regular-text" placeholder="<?php esc_attr_e('e.g., https://api.reguest.com', 'webx-reguest'); ?>" value="<?php echo esc_url($uri); ?>" />
    <?php
}

/**
 * Callback for the 'webx_reguest_form_mappings' field.
 * This includes the dynamic add/remove functionality.
 *
 * @return void
 */
function webx_reguest_form_mappings_callback() {
    $current_mappings = get_option('webx_reguest_form', []);
    ?>
    <p class="description"><?php esc_html_e('Map Reguest API fields to your Contact Form 7 field names. Enter the exact name of your CF7 field in the input box.', 'webx-reguest'); ?></p>

    <div id="webx_reguest_form_mappings">
        <?php
        foreach($current_mappings as $reguest_field_name => $cf7_field_name) {
            ?>
            <div class="webx-reguest-mapping-row" id="webx-reguest-row-<?php echo esc_attr($reguest_field_name); ?>">
                <label for="webx_reguest_form_<?php echo esc_attr($reguest_field_name); ?>"><?php echo esc_html($reguest_field_name); ?></label>
                <input type="text" name="webx_reguest_form[<?php echo esc_attr($reguest_field_name); ?>]" value="<?php echo esc_attr($cf7_field_name); ?>" placeholder="<?php esc_attr_e('CF7 Field Name', 'webx-reguest'); ?>" id="webx_reguest_form_<?php echo esc_attr($reguest_field_name); ?>" class="regular-text" />
                <button type="button" class="button button-secondary webx-reguest-remove-mapping" data-field-name="<?php echo esc_attr($reguest_field_name); ?>"><?php esc_html_e('Remove', 'webx-reguest'); ?></button>
            </div>
            <?php
        }
        ?>
    </div>

    <p>
        <label for="webx_reguest_prototypes"><?php esc_html_e('Add New Mapping:', 'webx-reguest'); ?></label>
        <select name="webx_reguest_prototypes" id="webx_reguest_prototypes">
            <option value=""><?php esc_html_e('-- Select a field --', 'webx-reguest'); ?></option>
            <option value="ArrivalDate"><?php esc_html_e('Arrival Date', 'webx-reguest'); ?></option>
            <option value="DepartureDate"><?php esc_html_e('Departure Date', 'webx-reguest'); ?></option>
            <option value="Anrede"><?php esc_html_e('Salutation', 'webx-reguest'); ?></option>
            <option value="EmailAddress"><?php esc_html_e('Email Address', 'webx-reguest'); ?></option>
            <option value="Adults"><?php esc_html_e('Adults', 'webx-reguest'); ?></option>
            <option value="Children"><?php esc_html_e('Children', 'webx-reguest'); ?></option>
            <option value="ChildrenAges"><?php esc_html_e('Children Ages', 'webx-reguest'); ?></option>
            <option value="FirstName"><?php esc_html_e('First Name', 'webx-reguest'); ?></option>
            <option value="LastName"><?php esc_html_e('Last Name', 'webx-reguest'); ?></option>
            <option value="CompanyName"><?php esc_html_e('Company Name', 'webx-reguest'); ?></option>
            <option value="CountryCode"><?php esc_html_e('Country Code', 'webx-reguest'); ?></option>
            <option value="StreetName"><?php esc_html_e('Street Name', 'webx-reguest'); ?></option>
            <option value="PostalCode"><?php esc_html_e('Postal Code', 'webx-reguest'); ?></option>
            <option value="CityName"><?php esc_html_e('City Name', 'webx-reguest'); ?></option>
            <option value="PhoneNumber"><?php esc_html_e('Phone Number', 'webx-reguest'); ?></option>
            <option value="MobileNumber"><?php esc_html_e('Mobile Number', 'webx-reguest'); ?></option>
            <option value="Text"><?php esc_html_e('General Text', 'webx-reguest'); ?></option>
            <option value="LanguageCode"><?php esc_html_e('Language Code', 'webx-reguest'); ?></option>
        </select>
        <button type="button" class="button button-secondary" id="webx_reguest_add_mapping"><?php esc_html_e('Add Mapping', 'webx-reguest'); ?></button>
    </p>
    <?php
}

/**
 * Main callback function for the Reguest settings page.
 *
 * @return void
 */
function webx_reguest_menu_settings_page_callback() {
    if ( !current_user_can( 'manage_options' ) )  {
        wp_die( __( 'You do not have sufficient permissions to access this page.', 'webx-reguest' ) );
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <?php settings_errors( 'webx_reguest_messages' ); ?>

        <form method="post" action="options.php">
            <?php
            // Output security fields for the registered setting "webx_reguest_settings_group"
            settings_fields( 'webx_reguest_settings_group' );
            // Output setting sections and their fields
            do_settings_sections( 'webx_reguest_menu_settings' );
            // Output save button
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

/**
 * Enqueue admin scripts and styles.
 *
 * @param string $hook The current admin page hook.
 * @return void
 */
function webx_reguest_admin_enqueue_scripts( $hook ) {
    if ( 'settings_page_webx_reguest_menu_settings' !== $hook ) {
        return;
    }

    // Enqueue custom admin CSS
    wp_enqueue_style(
        'webx-reguest-admin-style',
        plugin_dir_url( __FILE__ ) . 'admin/css/admin-style.css',
        array(),
        '1.0.0'
    );

    // Enqueue custom admin JavaScript
    wp_enqueue_script(
        'webx-reguest-admin-script',
        plugin_dir_url( __FILE__ ) . 'admin/js/admin-script.js',
        array( 'jquery' ),
        '1.0.0',
        true // In footer
    );

    // Localize script for dynamic data
    wp_localize_script( 'webx-reguest-admin-script', 'webxReguestAdmin', array(
        'cf7FieldNamePlaceholder' => esc_attr__( 'CF7 Field Name', 'webx-reguest' ),
        'removeButtonText' => esc_html__( 'Remove', 'webx-reguest' ),
    ));
}
add_action( 'admin_enqueue_scripts', 'webx_reguest_admin_enqueue_scripts' );

// Add Action to hook
if(get_option('webx_reguest_active')) {
    add_action( 'wpcf7_before_send_mail', 'send_to_reguest', 10, 1 ); 
}
add_action( 'admin_menu', 'webx_reguest_menu' );