<?php
declare(strict_types=1);
/**
 * Plugin Name: Reguest WP
 * Plugin URI: https://github.com/alpinmarketing/reguest-wp
 * Description: Sends form submissions (CF7 and HF Forms) to the Reguest API
 * Version: 26.4.20.2
 * Requires at least: 6.9
 * Requires PHP: 8.3
 * Author: Ing. Christian Fohrmann
 * Author URI: https://www.alpinmarketing.at
 */
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-reguest-wp-api-client.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-reguest-wp-admin-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-reguest-wp-form-integration.php';

// Migration v1: webx_reguest_* individual options → reguest_wp_options
function reguest_wp_run_migration_v1(): void {
    if ( get_option( 'reguest_wp_migrated_v1' ) ) {
        return;
    }
    update_option( 'reguest_wp_migrated_v1', true );

    if ( get_option( 'webx_reguest_username' ) !== false ) {
        $options = (array) get_option( 'reguest_wp_options', [] );

        $migrated_data = [
            'active'       => get_option( 'webx_reguest_active', null ),
            'username'     => get_option( 'webx_reguest_username', '' ),
            'password'     => get_option( 'webx_reguest_password', '' ),
            'uri'          => get_option( 'webx_reguest_uri', '' ),
            'form_mapping' => get_option( 'webx_reguest_form', [] ),
        ];

        update_option( 'reguest_wp_options', array_merge( $options, $migrated_data ) );

        foreach ( [ 'webx_reguest_active', 'webx_reguest_username', 'webx_reguest_password', 'webx_reguest_uri', 'webx_reguest_form' ] as $old_option ) {
            delete_option( $old_option );
        }
    }
}
add_action( 'admin_init', 'reguest_wp_run_migration_v1' );

// Migration v2: am_hotelfolio_reguest_options → reguest_wp_options (plugin rename)
function reguest_wp_run_migration_v2(): void {
    if ( get_option( 'reguest_wp_migrated_v2' ) ) {
        return;
    }
    update_option( 'reguest_wp_migrated_v2', true );

    $old_options = get_option( 'am_hotelfolio_reguest_options', false );
    if ( $old_options !== false ) {
        if ( get_option( 'reguest_wp_options', false ) === false ) {
            update_option( 'reguest_wp_options', $old_options );
        }
        delete_option( 'am_hotelfolio_reguest_options' );
        delete_option( 'am_hotelfolio_reguest_migrated_to_v2_5' );
    }
}
add_action( 'admin_init', 'reguest_wp_run_migration_v2' );
