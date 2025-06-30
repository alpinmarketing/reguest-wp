<?php
/**
 * Plugin Name: AM Hotelfolio Reguest
 * Plugin URI: https://www.web-crossing.com
 * Description: Sends Contact Form 7 Fields to Reguest
 * Version: 2.3
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
    public function send(array $form, array $fields) {
        $roomOccupancies = ['Adults','Children','ChildrenAges'];
        
        $form['kinderalter'] = explode(',',trim(preg_replace('/\D+/',',',$form['kinderalter']),','));
        // $form['kinderalter'] = preg_split('/([,\. ])/',$form['kinderalter']);
        if(!ctype_digit($form['kinderalter'][0])) $form['kinderalter']=[];
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

        switch($form['anrede']) {
            case 'Herr': case 'Mr':
                $request['Gender'] = 1;
                break;
            case 'Frau': case 'Mrs':
                $request['Gender'] = 2;
                break;
            case 'Firma': case 'Company':
                $request['GuestUserType'] = 2;
                break;
            default: break;
        }


        foreach($fields as $k=>$v) {
            if(in_array($k,$roomOccupancies)) {
                if($k == 'ChildrenAges' && is_array($v) && !empty($v)) {
                    $request['RoomOccupancies'][0][$k]=$form[$v];
                } else {
                    $request['RoomOccupancies'][0][$k]=$form[$v];
                }
            } else if(in_array($k,['ArrivalDate','DepartureDate'])) {
                $request[$k] = (new DateTime($form[$v]))->format('Y-m-d');
            } else if ($k == 'Anrede') {
                switch($form[$v]) {
                    case 'Herr': case 'Mr':
                        $request['Gender'] = 1;
                        break;
                    case 'Frau': case 'Mrs':
                        $request['Gender'] = 2;
                        break;
                    case 'Firma': case 'Company':
                        $request['GuestUserType'] = 2;
                        break;
                    default: break;
                }
            } else {
                $request[$k]=$form[$v];
            }
        }

        if(!isset($request['LanguageCode'])) {
            if(strpos($form['form_title'],'EN') !== false) {
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
    $options = get_option('webx_reguest_options');

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
        return $apiClient->send($form_data, $options['form'] ?? []);
    }
}
add_action( 'wpcf7_before_send_mail', 'send_to_reguest', 10, 1 );


/**
 * Admin Settings Section
 */

function webx_reguest_add_admin_menu() {
    add_options_page('Reguest API Settings', 'Reguest', 'manage_options', 'am-hotelfolio-reguest', 'webx_reguest_options_page_html');
}
add_action('admin_menu', 'webx_reguest_add_admin_menu');

function webx_reguest_enqueue_admin_assets($hook) {
    if ('settings_page_am-hotelfolio-reguest' !== $hook) {
        return;
    }
    wp_enqueue_style('am-hotelfolio-reguest-admin-style', plugin_dir_url(__FILE__) . 'am-hotelfolio-reguest-admin-style.css', [], '1.0');
    wp_enqueue_script('am-hotelfolio-reguest-admin-script', plugin_dir_url(__FILE__) . 'am-hotelfolio-reguest-admin-script.js', ['jquery'], '1.0', true);
}
add_action('admin_enqueue_scripts', 'webx_reguest_enqueue_admin_assets');


function webx_reguest_settings_init() {
    register_setting('webx_reguest_options_group', 'webx_reguest_options', 'webx_reguest_sanitize_options');

    add_settings_section('webx_reguest_main_section', 'API Credentials', null, 'am-hotelfolio-reguest');

    add_settings_field('webx_reguest_active', 'Plugin aktiv', 'webx_reguest_field_active_cb', 'am-hotelfolio-reguest', 'webx_reguest_main_section');
    add_settings_field('webx_reguest_username', 'Benutzername', 'webx_reguest_field_text_cb', 'am-hotelfolio-reguest', 'webx_reguest_main_section', ['id' => 'username', 'type' => 'text']);
    add_settings_field('webx_reguest_password', 'Passwort', 'webx_reguest_field_text_cb', 'am-hotelfolio-reguest', 'webx_reguest_main_section', ['id' => 'password', 'type' => 'password']);
    add_settings_field('webx_reguest_uri', 'API Link', 'webx_reguest_field_text_cb', 'am-hotelfolio-reguest', 'webx_reguest_main_section', ['id' => 'uri', 'type' => 'url']);

    add_settings_section('webx_reguest_form_section', 'Form Field Mapping', null, 'am-hotelfolio-reguest');
    add_settings_field('webx_reguest_form_mapping', 'API Field => Form Field', 'webx_reguest_field_mapping_cb', 'am-hotelfolio-reguest', 'webx_reguest_form_section');
}
add_action('admin_init', 'webx_reguest_settings_init');


function webx_reguest_sanitize_options($input) {
    $sanitized_input = [];
    $options = get_option('webx_reguest_options');

    $sanitized_input['active'] = isset($input['active']) ? '1' : null;
    $sanitized_input['username'] = isset($input['username']) ? sanitize_text_field($input['username']) : '';
    $sanitized_input['uri'] = isset($input['uri']) ? esc_url_raw($input['uri']) : '';

    // Only update password if a new one is provided
    if (!empty($input['password'])) {
        $sanitized_input['password'] = $input['password'];
    } else {
        $sanitized_input['password'] = $options['password'] ?? '';
    }

    if (isset($input['form']) && is_array($input['form'])) {
        $sanitized_input['form'] = [];
        foreach ($input['form'] as $key => $value) {
            $sanitized_input['form'][sanitize_key($key)] = sanitize_text_field($value);
        }
    }

    return $sanitized_input;
}

function webx_reguest_field_active_cb() {
    $options = get_option('webx_reguest_options');
    $checked = isset($options['active']) ? 'checked' : '';
    echo "<input type='checkbox' name='webx_reguest_options[active]' value='1' {$checked} />";
}

function webx_reguest_field_text_cb($args) {
    $options = get_option('webx_reguest_options');
    $id = $args['id'];
    $type = $args['type'];
    $value = $options[$id] ?? '';
    $placeholder = ($type === 'password') ? 'Zum Ändern neu eingeben' : '';
    $value_attr = ($type === 'password') ? '' : 'value="' . esc_attr($value) . '"';

    echo "<input type='{$type}' name='webx_reguest_options[{$id}]' {$value_attr} placeholder='{$placeholder}' class='regular-text' />";
}

function webx_reguest_field_mapping_cb() {
    $options = get_option('webx_reguest_options');
    $mappings = $options['form'] ?? [];
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

    echo '<div id="webx_reguest_form_mapping">';
    if (!empty($mappings)) {
        foreach ($mappings as $key => $value) {
            echo '<div class="mapping-row">';
            echo '<label for="webx_reguest_options_form_' . esc_attr($key) . '">' . esc_html($key) . '</label>';
            echo '<input type="text" name="webx_reguest_options[form][' . esc_attr($key) . ']" value="' . esc_attr($value) . '" placeholder="Contact Form 7 field name" data-key="' . esc_attr($key) . '" />';
            echo '</div>';
        }
    }
    echo '</div>';

    echo '<div style="margin-top: 20px;">';
    echo '<select id="webx_reguest_prototypes">';
    echo '<option value="">-- API Feld auswählen --</option>';
    foreach ($api_fields as $key => $label) {
        echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
    }
    echo '</select> ';
    echo '<button type="button" class="button prototype-button" data-func="add">Hinzufügen</button> ';
    echo '<button type="button" class="button prototype-button" data-func="del">Entfernen</button>';
    echo '</div>';
}


function webx_reguest_options_page_html() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap webx-reguest-settings-form">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('webx_reguest_options_group');
            do_settings_sections('am-hotelfolio-reguest');
            submit_button('Änderungen speichern');
            ?>
        </form>
    </div>
    <?php
}