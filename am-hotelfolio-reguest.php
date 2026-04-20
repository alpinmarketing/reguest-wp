<?php
/**
 * Plugin Name: AM Hotelfolio Reguest
 * Plugin URI: https://www.web-crossing.com
 * Description: Sends form submissions (CF7 and HF Forms) to the Reguest API
 * Version: 26.4.20.2
 * Author: Ing. Christian Fohrmann
 * Author URI: https://www.alpinmarketing.at
 */
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

// Load plugin classes
require_once plugin_dir_path( __FILE__ ) . 'includes/class-reguest-api-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-reguest-admin-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-reguest-form-integration.php';

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