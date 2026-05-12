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

function reguest_wp_send( $contact_form ): void {
    $options = (array) get_option( 'reguest_wp_options' );

    if ( empty( $options['active'] ) || empty( $options['uri'] ) || empty( $options['username'] ) || empty( $options['password'] ) ) {
        return;
    }

    if ( ! class_exists( 'WPCF7_Submission' ) ) {
        return;
    }

    $submission = WPCF7_Submission::get_instance();
    if ( ! $submission ) {
        return;
    }

    $form_data = $submission->get_posted_data();
    $meta_data = [];

    // Auto-detect language from the CF7 form locale — more reliable than requiring a mapped field.
    $locale = null;
    if ( $contact_form instanceof WPCF7_ContactForm && isset( $contact_form->locale ) ) {
        $locale = $contact_form->locale;
    } elseif ( isset( $_POST['_wpcf7_locale'] ) ) {
        $locale = sanitize_text_field( wp_unslash( $_POST['_wpcf7_locale'] ) );
    }

    if ( $locale ) {
        $meta_data['LanguageCode'] = $locale;
    }

    if ( isset( $form_data['reguest'] ) && strtolower( (string) $form_data['reguest'] ) !== 'false' ) {
        $apiClient = new ReguestAPIClient( $options['uri'], $options['username'], $options['password'] );
        // Result intentionally ignored to avoid blocking the CF7 submission flow on API errors.
        $apiClient->send( $form_data, $options['form_mapping'] ?? [], $meta_data, ! empty( $options['test_mode'] ), ! empty( $options['debug'] ) );
    }
}
add_action( 'wpcf7_before_send_mail', 'reguest_wp_send', 10, 1 );

function reguest_wp_send_hf_forms( int $_form_id, array $payload_data, array $params ): void {
    $options = (array) get_option( 'reguest_wp_options' );

    if ( empty( $options['active'] ) || empty( $options['uri'] ) || empty( $options['username'] ) || empty( $options['password'] ) ) {
        return;
    }

    // Hidden field <input type="hidden" name="reguest" value="true"> must be present to trigger the API call.
    if ( ( $params['reguest'] ?? '' ) !== 'true' ) {
        return;
    }

    $meta_data  = [];
    $raw_locale = (string) ( $params['locale'] ?? $payload_data['locale'] ?? '' );
    if ( $raw_locale === '' ) {
        // pll_current_language() returns false in REST/AJAX context; fall back to get_locale().
        $raw_locale = ( function_exists( 'pll_current_language' ) ? pll_current_language() : '' ) ?: get_locale();
    }
    if ( $raw_locale !== '' ) {
        $meta_data['LanguageCode'] = sanitize_text_field( wp_unslash( $raw_locale ) );
    }

    $apiClient = new ReguestAPIClient( $options['uri'], $options['username'], $options['password'] );
    $apiClient->send( $payload_data, $options['form_mapping'] ?? [], $meta_data, ! empty( $options['test_mode'] ), ! empty( $options['debug'] ) );
}
add_action( 'hf_form_submitted', 'reguest_wp_send_hf_forms', 10, 3 );
