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

    // Automatically populate meta data supported by the API
    $meta_data = [];

    // Add language code from CF7 locale, e.g., 'de_DE' -> 'de'
    $locale = $contact_form->locale ?? null;
    if (!empty($locale)) {
        $meta_data['LanguageCode'] = substr($locale, 0, 2);
    }

    if( isset($form_data['reguest']) && strtolower($form_data['reguest']) !== 'false' ) {
        $apiClient = new ReguestAPIClient($options['uri'], $options['username'], $options['password']);
        $is_debug_mode = !empty($options['debug']);
        // Call the API but do not return its result to prevent blocking the CF7 submission.
        $apiClient->send($form_data, $options['form_mapping'] ?? [], $meta_data, $is_debug_mode);
    }
}
add_action( 'wpcf7_before_send_mail', 'send_to_reguest', 10, 1 );