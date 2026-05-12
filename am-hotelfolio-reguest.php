<?php
declare(strict_types=1);
/**
 * Plugin Name: AM Hotelfolio Reguest
 * Plugin URI: https://www.web-crossing.com
 * Description: Sends form submissions (CF7 and HF Forms) to the Reguest API
 * Version: 26.4.20.2
 * Requires at least: 6.9
 * Requires PHP: 8.3
 * Author: Ing. Christian Fohrmann
 * Author URI: https://www.alpinmarketing.at
 */
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-reguest-api-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-reguest-admin-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-reguest-form-integration.php';

function am_hotelfolio_reguest_run_migration(): void {
    $migration_flag = 'am_hotelfolio_reguest_migrated_to_v2_5';

    if ( get_option( $migration_flag ) ) {
        return;
    }

    if ( get_option( 'webx_reguest_username' ) !== false ) {
        $options = (array) get_option( 'am_hotelfolio_reguest_options', [] );

        $migrated_data = [
            'active'       => get_option( 'webx_reguest_active', null ),
            'username'     => get_option( 'webx_reguest_username', '' ),
            'password'     => get_option( 'webx_reguest_password', '' ),
            'uri'          => get_option( 'webx_reguest_uri', '' ),
            'form_mapping' => get_option( 'webx_reguest_form', [] ),
        ];

        update_option( 'am_hotelfolio_reguest_options', array_merge( $options, $migrated_data ) );

        foreach ( [ 'webx_reguest_active', 'webx_reguest_username', 'webx_reguest_password', 'webx_reguest_uri', 'webx_reguest_form' ] as $old_option ) {
            delete_option( $old_option );
        }

        update_option( $migration_flag, true );
    }
}
add_action( 'admin_init', 'am_hotelfolio_reguest_run_migration' );
