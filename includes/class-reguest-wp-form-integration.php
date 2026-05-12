<?php
declare(strict_types=1);
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

function reguest_wp_log_error( string $message ): void {
    $options = (array) get_option( 'reguest_wp_options' );

    if ( ! empty( $options['debug'] ) ) {
        $log_transient_key = 'reguest_wp_debug_log';
        $logs = get_transient( $log_transient_key );
        if ( false === $logs || ! is_array( $logs ) ) {
            $logs = [];
        }

        array_unshift( $logs, wp_date( 'Y-m-d H:i:s' ) . ' - ' . $message );
        $logs = array_slice( $logs, 0, 100 );

        set_transient( $log_transient_key, $logs, WEEK_IN_SECONDS );
    } else {
        error_log( 'ReGuest Plugin: ' . $message );
    }
}

function reguest_wp_register_routes(): void {
    register_rest_route( 'reguest-wp/v1', '/submit', [
        'methods'             => 'POST',
        'callback'            => 'reguest_wp_handle_webhook',
        'permission_callback' => '__return_true',
    ] );
}
add_action( 'rest_api_init', 'reguest_wp_register_routes' );

function reguest_wp_handle_webhook( WP_REST_Request $request ): WP_REST_Response {
    $options = (array) get_option( 'reguest_wp_options' );

    if ( empty( $options['active'] ) || empty( $options['uri'] ) || empty( $options['username'] ) || empty( $options['password'] ) ) {
        return new WP_REST_Response( [ 'success' => false, 'error' => 'Plugin not configured.' ], 503 );
    }

    $stored_token = (string) ( $options['webhook_token'] ?? '' );
    $sent_token   = (string) ( $request->get_header( 'x_hf_token' ) ?? '' );

    if ( $stored_token === '' || ! hash_equals( $stored_token, $sent_token ) ) {
        return new WP_REST_Response( [ 'success' => false, 'error' => 'Unauthorized.' ], 401 );
    }

    $body = $request->get_json_params();
    if ( ! is_array( $body ) || empty( $body ) ) {
        return new WP_REST_Response( [ 'success' => false, 'error' => 'Empty or invalid JSON body.' ], 400 );
    }

    $meta_data  = [];
    $raw_locale = (string) ( $body['_wp_locale'] ?? '' );
    if ( $raw_locale !== '' ) {
        $meta_data['LanguageCode'] = strtolower( substr( trim( $raw_locale ), 0, 2 ) );
    }
    unset( $body['_wp_locale'] );

    try {
        $apiClient = new ReguestAPIClient( $options['uri'], $options['username'], $options['password'] );
        $success   = $apiClient->send(
            $body,
            $options['form_mapping'] ?? [],
            $meta_data,
            ! empty( $options['test_mode'] ),
            ! empty( $options['debug'] )
        );
    } catch ( InvalidArgumentException $e ) {
        reguest_wp_log_error( 'Configuration error: ' . $e->getMessage() );
        return new WP_REST_Response( [ 'success' => false, 'error' => 'Server configuration error.' ], 503 );
    }

    if ( $success ) {
        return new WP_REST_Response( [ 'success' => true ], 200 );
    }

    return new WP_REST_Response( [ 'success' => false, 'error' => 'API call failed. Check debug log.' ], 500 );
}
