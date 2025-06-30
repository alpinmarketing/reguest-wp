<?php
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
    // A list of all known API keys in their correct PascalCase format.
    private static $knownApiKeys = [
        'EmailAddress', 'ArrivalDate', 'DepartureDate', 'MealType', 'GuestUserType',
        'Gender', 'Anrede', 'Title', 'FirstName', 'LastName', 'FullName', 'FamilyName',
        'CompanyName', 'BirthDate', 'StreetName', 'PostalCode', 'CityName', 'CountryCode',
        'PhoneNumber', 'MobileNumber', 'FaxNumber', 'Text', 'LanguageCode',
        'NewsletterSubscription', 'AlternativeArrivalDate', 'AlternativeDepartureDate',
        'OfferName', 'OfferCode', 'ThirdPartyNotes', 'ForeignId', 'SourceOfBusiness',
        'Adults', 'Children', 'ChildrenAges'
    ];

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
        if (empty($url) || empty($username) || empty($password)) {
            throw new InvalidArgumentException('URL, username, and password are required.');
        }

        // Ensure the base URL doesn't have a trailing slash before appending the path
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
            ]
        ];
        $this->client = curl_init();
    }

    public function __destruct() {
        if (is_resource($this->client)) {
            curl_close($this->client);
        }
    }

    /**
     * send
     * 
     * @param array $form The submitted form data.
     * @param array $fields The mapping of API keys to form field names.
     * @param array $meta_data Additional data to include in the request.
     * @param bool $test_mode If true, the request is logged but not sent.
     * @param bool $debug_mode If true, detailed request/response info is logged.
     * 
     * @return bool
     */
    public function send(array $form, array $fields, array $meta_data = [], bool $test_mode = false, bool $debug_mode = false): bool {
        // --- 1. Data Collection ---
        // This section gathers all data from the form based on the mapping.
        $requestData = [];
        $anredeValue = null; // Temporary variable to hold the salutation value for later processing.
        $roomOccupancies = ['Adults', 'Children', 'ChildrenAges'];
        $dateFields = ['ArrivalDate', 'DepartureDate', 'AlternativeArrivalDate', 'AlternativeDepartureDate', 'BirthDate'];
        $booleanFields = ['NewsletterSubscription'];

        // Create a map of lowercase keys to their correct PascalCase version for normalization.
        // This makes the mapping in the admin settings case-insensitive.
        $keyMap = [];
        foreach (self::$knownApiKeys as $key) {
            $keyMap[strtolower($key)] = $key;
        }

        foreach ($fields as $apiKey => $formFieldName) { // This is now part of Data Collection
            // Skip if the form field name is empty or the field wasn't submitted in the form
            if (empty($formFieldName) || !isset($form[$formFieldName]) || $form[$formFieldName] === '') {
                continue;
            }

            // Normalize the API key from the settings to the correct case (e.g., 'adults' -> 'Adults').
            // If the key is not in our known list, we use it as-is to allow for future API fields.
            $normalizedApiKey = $keyMap[strtolower($apiKey)] ?? $apiKey;

            $value = $form[$formFieldName];

            // The 'Anrede' field is not a direct API field but a meta field to determine Gender/GuestUserType.
            // We capture its value here and process it after the loop.
            if ($normalizedApiKey === 'Anrede') {
                $anredeValue = $value;
            } elseif ($normalizedApiKey === 'CountryCode') {
                // Handle cases where CF7 might wrap a single select value in an array.
                $countryName = is_array($value) ? ($value[0] ?? null) : $value;

                if (is_string($countryName) && !empty($countryName)) {
                    $countryCode = $this->get_country_code_from_name($countryName);
                    if ($countryCode) { // Only set if a valid code was found
                        $requestData[$normalizedApiKey] = $countryCode;
                    } else {
                        am_hotelfolio_reguest_log_error("Country name '{$countryName}' could not be mapped to an ISO code and was skipped.");
                    }
                } else {
                    // Log if the value was unusable (e.g., empty array or not a string)
                    am_hotelfolio_reguest_log_error("Invalid value received for CountryCode; expected a string, but got something else. Value skipped.");
                }
            } elseif (in_array($normalizedApiKey, $dateFields)) {
                try {
                    $requestData[$normalizedApiKey] = (new DateTime($value))->format('Y-m-d');
                } catch (Exception $e) {
                    // Handle invalid date format gracefully
                    am_hotelfolio_reguest_log_error("Invalid date format for {$normalizedApiKey}: " . $value);
                    $requestData[$normalizedApiKey] = null;
                }
            } elseif ($normalizedApiKey === 'ChildrenAges') {
                // Clean the string and convert to an array of integers
                $agesArray = array_filter(preg_split('/[,\s\.]+/', $value), 'is_numeric');
                $requestData['RoomOccupancies'][0][$normalizedApiKey] = array_map('intval', $agesArray);
            } elseif (in_array($normalizedApiKey, $booleanFields)) {
                // Convert common string representations of 'true' to a boolean
                $requestData[$normalizedApiKey] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            } elseif (in_array($normalizedApiKey, $roomOccupancies)) { // Handles 'Adults' and 'Children'
                $requestData['RoomOccupancies'][0][$normalizedApiKey] = (int)$value;
            } else {
                // For fields that might submit an array (like checkboxes), convert them to a string.
                if (is_array($value)) {
                    $requestData[$normalizedApiKey] = implode(', ', $value);
                } else {
                    $requestData[$normalizedApiKey] = $value;
                }
            }
        }

        // --- 2. Logic Application ---
        // This is done after the main loop to ensure all data is present and to avoid ordering issues.
        if ($anredeValue) {
            // Handle cases where CF7 might wrap a single select value in an array.
            $salutation = is_array($anredeValue) ? ($anredeValue[0] ?? null) : $anredeValue;

            if (is_string($salutation) && !empty($salutation)) {
                // Handle Polylang for CF7, which wraps values in {}. We remove them and surrounding spaces before comparison.
                switch (strtolower(trim($salutation, ' {}'))) {
                    case 'herr':
                    case 'mr':
                        $requestData['Gender'] = 1;
                        break;
                    case 'frau':
                    case 'mrs':
                    case 'ms':
                        $requestData['Gender'] = 2;
                        break;
                }
            }

        }

        // --- 3. Final Payload Assembly ---
        // This ensures a clear priority: Form Data > Metadata > Defaults.
        $defaults = [
            'Gender'        => 0,
            'LanguageCode'  => 'de',
        ];

        $request = array_merge($defaults, $meta_data, $requestData);


        // --- 4. Pre-flight Validation & Business Rules ---
        // These are applied to the final, assembled request payload.

        // Apply the requested fixed values. This overrides any value from the form or defaults.
        $request['MealType'] = 0;
        $request['GuestUserType'] = 0;

        // 0. Validate presence of required fields as a final safeguard.
        $requiredFields = ['EmailAddress', 'ArrivalDate', 'DepartureDate'];
        foreach ($requiredFields as $field) {
            if (empty($request[$field])) {
                am_hotelfolio_reguest_log_error("Aborting send. Required API field '{$field}' is missing or empty. Please check your form mapping in the settings.");
                return false;
            }
        }

        // 1. Validate Email Address format
        if (isset($request['EmailAddress']) && !filter_var($request['EmailAddress'], FILTER_VALIDATE_EMAIL)) {
            am_hotelfolio_reguest_log_error("Aborting send due to invalid email address format: " . ($request['EmailAddress'] ?? ''));
            return false; // Stop processing if email is invalid
        }

        // 2. Validate Date Plausibility
        try {
            if (isset($request['ArrivalDate'], $request['DepartureDate']) && new DateTime($request['ArrivalDate']) >= new DateTime($request['DepartureDate'])) {
                am_hotelfolio_reguest_log_error("Aborting send. DepartureDate must be after ArrivalDate.");
                return false;
            }
            if (isset($request['AlternativeArrivalDate'], $request['AlternativeDepartureDate']) && new DateTime($request['AlternativeArrivalDate']) >= new DateTime($request['AlternativeDepartureDate'])) {
                am_hotelfolio_reguest_log_error("Aborting send. AlternativeDepartureDate must be after AlternativeArrivalDate.");
                return false;
            }
        } catch (Exception $e) {
            // This case is already handled during date parsing, but serves as a safeguard.
            am_hotelfolio_reguest_log_error("Aborting send due to invalid date for comparison. " . $e->getMessage());
            return false;
        }

        // 3. Validate Room Occupancy consistency
        if (isset($request['RoomOccupancies'][0])) {
            $numChildren = $request['RoomOccupancies'][0]['Children'] ?? 0;
            $numAges = isset($request['RoomOccupancies'][0]['ChildrenAges']) ? count($request['RoomOccupancies'][0]['ChildrenAges']) : 0;
            if ($numChildren > 0 && $numChildren !== $numAges) {
                am_hotelfolio_reguest_log_error("Aborting send. Mismatch between number of children ({$numChildren}) and provided ages ({$numAges}).");
                return false;
            }
        }

        // Since GuestUserType is always 0 (Person), we apply the corresponding business rule.
        // Per API docs, for a person, use FirstName/LastName or FullName. Not Company/Family name.
        unset($request['CompanyName'], $request['FamilyName']);


        // --- 5. Send & Log ---

        // If debug mode is active, log the request payload that will be sent or simulated.
        if ($debug_mode) {
            $json_payload_for_log = json_encode($request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            am_hotelfolio_reguest_log_error("API Request Payload:\n" . $json_payload_for_log);
        }

        // If test mode is active, we skip the actual API call.
        if ($test_mode) {
            am_hotelfolio_reguest_log_error("TESTMODUS: API-Aufruf übersprungen.");
            return true; // Simulate a successful submission for testing purposes.
        }

        // --- 6. Execute API Call ---
        $this->options[CURLOPT_POSTFIELDS] = json_encode($request);
        curl_setopt_array($this->client,$this->options);
        $response_body = curl_exec($this->client);

        // Check for cURL errors
        if (curl_errno($this->client)) {
            am_hotelfolio_reguest_log_error('cURL Error: ' . curl_error($this->client));
            return false;
        }

        // Check for non-successful HTTP status codes
        $http_code = curl_getinfo($this->client, CURLINFO_HTTP_CODE);

        // If debug mode is active, log the raw response from the API, regardless of status code.
        if ($debug_mode) {
            am_hotelfolio_reguest_log_error("API Response (HTTP {$http_code}):\n" . $response_body);
        }

        if ($http_code < 200 || $http_code >= 300) {
            // Try to decode the error response for a more specific message
            $error_details = json_decode($response_body, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($error_details['ExceptionMessage'])) {
                $error_message = "API Error: " . $error_details['ExceptionMessage'];
            } else {
                $error_message = "Raw Response: " . $response_body;
            }
            // This log is redundant if debug mode is on (as it's already logged above), but crucial if it's off.
            if (!$debug_mode) {
                am_hotelfolio_reguest_log_error("HTTP Error: Status code {$http_code}. " . $error_message);
            }
            return false;
        }

        $return = json_decode($response_body, true);

        // Check for JSON decoding errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            am_hotelfolio_reguest_log_error('JSON Decode Error: ' . json_last_error_msg());
            return false;
        }

        // Check for the 'Success' flag in the API response
        $is_success = isset($return['Success']) && $return['Success'] === true;

        if ($debug_mode) {
            am_hotelfolio_reguest_log_error("API Call Result: " . ($is_success ? 'Success' : 'Failure'));
        }

        return $is_success;
    }

    /**
     * Converts a country name to its ISO 3166-1 alpha-2 code.
     *
     * @param string $name The full name of the country provided by the form.
     * @return string|null The two-letter country code or null if not found.
     */
    private function get_country_code_from_name(string $name): ?string
    {
        // Using a static variable to avoid re-creating the map on every call within the same request.
        static $countryMap = [
            'deutschland' => 'DE',
            'österreich' => 'AT',
            'schweiz' => 'CH',
            'belgien' => 'BE',
            'bulgarien' => 'BG',
            'kroatien' => 'HR',
            'tschechien' => 'CZ',
            'dänemark' => 'DK',
            'estland' => 'EE',
            'finnland' => 'FI',
            'frankreich' => 'FR',
            'griechenland' => 'GR',
            'ungarn' => 'HU',
            'irland' => 'IE',
            'italien' => 'IT',
            'lettland' => 'LV',
            'litauen' => 'LT',
            'luxemburg' => 'LU',
            'malta' => 'MT',
            'niederlande' => 'NL',
            'polen' => 'PL',
            'portugal' => 'PT',
            'rumänien' => 'RO',
            'slowakei' => 'SK',
            'slowenien' => 'SI',
            'spanien' => 'ES',
            'schweden' => 'SE',
            'zypern' => 'CY',
            'united kingdom' => 'GB',
            'usa' => 'US',
        ];

        // Use mb_strtolower for proper handling of multi-byte characters like 'Ö', 'ü', etc.,
        // regardless of the server's locale settings.
        $normalizedName = mb_strtolower(trim($name), 'UTF-8');
        return $countryMap[$normalizedName] ?? null;
    }
}