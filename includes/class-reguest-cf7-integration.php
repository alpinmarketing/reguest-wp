<?php
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

    $submission = WPCF7_Submission::get_instance();
    if (!$submission) {
        return;
    }
    // Use the recommended CF7 method to get sanitized submitted data.
    $form_data = $submission->get_posted_data();

    // Automatically detect the form's language from the CF7 locale property.
    // This is more reliable than relying on field mapping for this special value.
    $meta_data = [];
    if (isset($contact_form->locale)) {
        // The value will be normalized to a 2-letter code in the API client.
        $meta_data['LanguageCode'] = $contact_form->locale;
    }

    if( isset($form_data['reguest']) && strtolower($form_data['reguest']) !== 'false' ) {
        $apiClient = new ReguestAPIClient($options['uri'], $options['username'], $options['password']);
        $is_test_mode = !empty($options['test_mode']);
        $is_debug_mode = !empty($options['debug']);
        // Call the API. The result is not used here to prevent blocking the CF7 submission flow.
        $apiClient->send($form_data, $options['form_mapping'] ?? [], $meta_data, $is_test_mode, $is_debug_mode);
    }
}
add_action( 'wpcf7_before_send_mail', 'send_to_reguest', 10, 1 );