<?php
/**
 * Plugin Name: Webx Reguest
 * Plugin URI: https://www.web-crossing.com
 * Description: Sends Requests to Reguest
 * Version: 1.0
 * Author: Gunther Urbanke
 * Author URI: https://www.web-crossing.com
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
    public function send($form) {
        $fields = get_option('webx_reguest_form');
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
        $return = curl_exec($this->client);   

        if($return['success']) return true;
        else return false;
    }
}

/**
 *  Action send_to_reguest
 * 
 * @return void
 */
function send_to_reguest($contact_form) {
    
    foreach($_POST as $k=>$v) {
        if(strpos($k,'_wpcf7') === false) {
            $form[$k]=$v;
        }
    }
    if( $form['reguest'] && strtolower($form['reguest']) != 'false' ) {
        $form['form_title'] = strtoupper($contact_form->title);
        $apiClient = new ReguestAPIClient(get_option('webx_reguest_uri'),get_option('webx_reguest_username'),get_option('webx_reguest_password'));
        return $apiClient->send($form);
    } else {
    }

}



/**
 * Action webx_reguest_menu
 * 
 * @return void
 */
function webx_reguest_menu() {
    add_options_page( 'Reguest', 'Reguest', 'manage_options', 'webx_reguest_menu_settings', 'webx_reguest_menu_settings' );
}



// OLD 
/**
 * action webx_reguest_menu_settings()
 * 
 * @return void
 */
function webx_reguest_menu_settings() {
    if ( !current_user_can( 'manage_options' ) )  {
        wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }

    if(isset($_POST) && !empty($_POST)) {
        if(isset($_POST['webx_reguest_username'])) {
            update_option('webx_reguest_username',$_POST['webx_reguest_username']);
            $updated = true;
        }
        if(isset($_POST['webx_reguest_password'])) {
            update_option('webx_reguest_password',$_POST['webx_reguest_password']);
            $updated = true;
        }
        if(isset($_POST['webx_reguest_uri'])) {
            update_option('webx_reguest_uri',$_POST['webx_reguest_uri']);
            $updated = true;
        }
        
        if(isset($_POST['webx_reguest_active'])) {
            update_option('webx_reguest_active',$_POST['webx_reguest_active']);
            $updated = true;
        } else update_option('webx_reguest_active',null);

        if(is_array($_POST['webx_reguest_form'])) {
            foreach($_POST['webx_reguest_form'] as $k=>$v) {
                $form[$k]=$v;
            }

            update_option('webx_reguest_form',$form);
            
        }
    }

?>
<style>.wrap {margin-bottom: 25px;}.row {margin-bottom: 15px;display:flex}.row input{width: 250px;}.row label{width: 100px}.row button {width:100px;margin-right: 15px!important;}</style>

<div class="wrap"><h2>Reguest Einstellungen</h2></div><?php
if(isset($updated) && $updated) {
    echo '<font color="red">Updated</font><br/><br/>';
}
?><form name="form1" method="post" action="">
    <div>
        <label for="webx_reguest_active">
            <input class="checkbox" type="checkbox" name="webx_reguest_active" value="1" <?php if(get_option('webx_reguest_active')) echo "checked=\"checked\"" ?>/> ist aktiv
        </label>
    </div>
    <div class="row">
        <label for="webx_reguest_username">Benutzername</label>
        <input type="text" name="webx_reguest_username" placeholder="Benutzername" value="<?php echo get_option( 'webx_reguest_username' ); ?>" />
    </div>
    <div class="row">
        <label for="webx_reguest_password">Passwort</label>
        <input type="password" name="webx_reguest_password" placeholder="Passwort" value="<?php echo get_option( 'webx_reguest_password' ); ?>" />
    </div>
    <div class="row">
        <label for="webx_reguest_uri">Link</label>
        <input type="text" name="webx_reguest_uri" placeholder="Link" value="<?php echo get_option( 'webx_reguest_uri' ); ?>" />
    </div>
    
    <div id="webx_reguest_form"><?php 

    foreach(get_option('webx_reguest_form') as $k=>$v) {
        ?><div class="row">
            <label for="webx_reguest_form[<?php echo $k ?>]"><?php echo $k ?></label>
            <input type="text" name="webx_reguest_form[<?php echo $k ?>]" placeholder="Klasse" value="<?php echo $v ?>" id="<?php echo $k ?>" />
        </div><?php
    }

    ?></div>
    <div class="row">
        <label for="webx_reguest_prototypes">Feld</label>
        <select name="webx_reguest_prototypes" id="webx_reguest_prototypes">
            <option value="ArrivalDate">Ankunft</option>
            <option value="DepartureDate">Abreise</option>
            <option value="Anrede">Anrede</option>
            <option value="EmailAddress">E-Mail</option>
            <option value="Adults">Erwachsene</option>
            <option value="Children">Kinder</option>
            <option value="ChildrenAges">Kinderalter</option>
            <option value="FirstName">Vorname</option>
            <option value="LastName">Nachname</option>
            <option value="ArrivalDate">Ankunft</option>
            <option value="DepartureDate">Abreise</option>
            <option value="CompanyName">Firma</option>
            <option value="CountryCode">Ländercode</option>
            <option value="StreetName">Straße</option>
            <option value="PostalCode">Postleitzahl</option>
            <option value="CityName">Stadt</option>
            <option value="PhoneNumber">Telefonnummer</option>
            <option value="MobileNumber">Mobilnummer</option>
            <option value="Text">Text</option>
            <option value="LanguageCode">Sprache</option>
        </select>
    </div>
    <div class="row">
        <div class="col-md-6">
            <button type="button" class="button-primary prototype-button" data-func="add">Hinzufügen</button>
        </div>
        <div class="col-md-6">
            <button type="button" class="button-primary prototype-button" data-func="del">Entfernen</button>
        </div>
    </div>
    
    <div class="row">
        <input type="submit" class="button-primary"  value="<?php esc_attr_e('Save Changes') ?>" />
    </div>

</form>

<script>
    jQuery(document).ready(function() {
        jQuery('.prototype-button').click(function() {
            var wrapper= jQuery('#webx_reguest_form');
            var name = jQuery('#webx_reguest_prototypes').val();
            var prototype = '<div class="row"><label for="webx_reguest_form[' + name + ']">' + name + '</label><input type="text" name="webx_reguest_form[' + name + ']" value="" placeholder="Klasse" id="' + name + '"/></div>';
            switch(jQuery(this).data('func')) {
                case 'add':
                    console.log(jQuery('#webx_reguest_prototypes').val());
                    if(wrapper.find('#'+name).length == 0 ) wrapper.append(prototype);
                    break;
                case 'del':
                    if(wrapper.find('#'+name).length > 0 ) wrapper.find('#'+name).parent().remove();
                    break;
                default: break;
            }

        })
        // console.log(jQuery.fn.jquery);
    });
</script>

<?php
}
// Add Action to hook
if(get_option('webx_reguest_active')) {
    add_action( 'wpcf7_before_send_mail', 'send_to_reguest', 10, 1 ); 
}
add_action( 'admin_menu', 'webx_reguest_menu' );