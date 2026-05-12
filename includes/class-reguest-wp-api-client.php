<?php
declare(strict_types=1);
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

class ReguestAPIClient {

    private string $baseUrl;
    private string $username;
    private string $password;

    private static array $knownApiKeys = [
        'EmailAddress', 'ArrivalDate', 'DepartureDate', 'MealType', 'GuestUserType',
        'Gender', 'Anrede', 'Title', 'FirstName', 'LastName', 'FullName', 'FamilyName',
        'CompanyName', 'BirthDate', 'StreetName', 'PostalCode', 'CityName', 'CountryCode',
        'PhoneNumber', 'MobileNumber', 'FaxNumber', 'Text', 'LanguageCode',
        'NewsletterSubscription', 'AlternativeArrivalDate', 'AlternativeDepartureDate',
        'OfferName', 'OfferCode', 'ThirdPartyNotes', 'ForeignId', 'SourceOfBusiness',
        'Adults', 'Children', 'ChildrenAges',
    ];

    public function __construct( string $url, string $username, string $password ) {
        if ( empty( $url ) || empty( $username ) || empty( $password ) ) {
            throw new InvalidArgumentException( 'URL, username, and password are required.' );
        }

        // rtrim prevents a double-slash when the stored URL has a trailing slash
        $this->baseUrl  = rtrim( $url, '/' ) . '/v1/ReGuest/Requests';
        $this->username = $username;
        $this->password = $password;
    }

    public function send(
        array $form,
        array $fields,
        array $meta_data  = [],
        bool  $test_mode  = false,
        bool  $debug_mode = false
    ): bool {
        $requestData    = [];
        $anredeValue    = null;
        $roomOccupancies = [ 'Adults', 'Children', 'ChildrenAges' ];
        $dateFields      = [ 'ArrivalDate', 'DepartureDate', 'AlternativeArrivalDate', 'AlternativeDepartureDate', 'BirthDate' ];
        $booleanFields   = [ 'NewsletterSubscription' ];

        // Build a lowercase → PascalCase map so admin-entered keys are case-insensitive.
        $keyMap = [];
        foreach ( self::$knownApiKeys as $key ) {
            $keyMap[ strtolower( $key ) ] = $key;
        }

        foreach ( $fields as $apiKey => $formFieldName ) {
            if ( empty( $formFieldName ) || ! isset( $form[ $formFieldName ] ) || $form[ $formFieldName ] === '' ) {
                continue;
            }

            $normalizedApiKey = $keyMap[ strtolower( (string) $apiKey ) ] ?? (string) $apiKey;
            $value            = $form[ $formFieldName ];

            // 'Anrede' is not a direct API field; it resolves to Gender after the loop.
            if ( $normalizedApiKey === 'Anrede' ) {
                $anredeValue = $value;
            } elseif ( $normalizedApiKey === 'CountryCode' ) {
                $countryName = is_array( $value ) ? ( $value[0] ?? null ) : $value;
                if ( is_string( $countryName ) && $countryName !== '' ) {
                    if ( preg_match( '/^[A-Za-z]{2}$/', $countryName ) ) {
                        $requestData[ $normalizedApiKey ] = strtoupper( $countryName );
                    } else {
                        $countryCode = $this->get_country_code_from_name( $countryName );
                        if ( $countryCode ) {
                            $requestData[ $normalizedApiKey ] = $countryCode;
                        } else {
                            reguest_wp_log_error( "Country name '{$countryName}' could not be mapped to an ISO code and was skipped." );
                        }
                    }
                } else {
                    reguest_wp_log_error( 'Invalid value received for CountryCode; expected a string. Value skipped.' );
                }
            } elseif ( in_array( $normalizedApiKey, $dateFields, true ) ) {
                try {
                    $requestData[ $normalizedApiKey ] = ( new DateTime( (string) $value ) )->format( 'Y-m-d' );
                } catch ( Exception $e ) {
                    reguest_wp_log_error( "Invalid date format for {$normalizedApiKey}: " . $value );
                    $requestData[ $normalizedApiKey ] = null;
                }
            } elseif ( $normalizedApiKey === 'ChildrenAges' ) {
                $agesString = is_array( $value ) ? implode( ',', $value ) : (string) $value;
                $agesArray  = array_filter( preg_split( '/[,\s\.]+/', $agesString ) ?: [], 'is_numeric' );
                // array_values re-indexes so json_encode produces a JSON array, not an object.
                $requestData['RoomOccupancies'][0][ $normalizedApiKey ] = array_map( 'intval', array_values( $agesArray ) );
            } elseif ( in_array( $normalizedApiKey, $booleanFields, true ) ) {
                $requestData[ $normalizedApiKey ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
            } elseif ( in_array( $normalizedApiKey, $roomOccupancies, true ) ) {
                $requestData['RoomOccupancies'][0][ $normalizedApiKey ] = (int) $value;
            } else {
                $requestData[ $normalizedApiKey ] = is_array( $value ) ? implode( ', ', $value ) : $value;
            }
        }

        // Resolve salutation to Gender after the full loop so all fields are available.
        if ( $anredeValue !== null ) {
            $salutation = is_array( $anredeValue ) ? ( $anredeValue[0] ?? null ) : $anredeValue;
            if ( is_string( $salutation ) && $salutation !== '' ) {
                // Polylang for CF7 can wrap values in {}; strip braces and spaces before matching.
                switch ( strtolower( trim( $salutation, ' {}' ) ) ) {
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

        $defaults = [ 'Gender' => 0 ];
        $request  = array_merge( $defaults, $meta_data, $requestData );

        // Normalise LanguageCode to ISO 639-1 after merging all sources so it applies
        // regardless of whether the value came from auto-detection or manual mapping.
        if ( isset( $request['LanguageCode'] ) && is_string( $request['LanguageCode'] ) ) {
            $request['LanguageCode'] = strtolower( substr( trim( $request['LanguageCode'] ), 0, 2 ) );
        }

        $request['MealType']      = 0;
        $request['GuestUserType'] = 0;

        $requiredFields = [ 'EmailAddress', 'ArrivalDate', 'DepartureDate' ];
        foreach ( $requiredFields as $field ) {
            if ( empty( $request[ $field ] ) ) {
                reguest_wp_log_error( "Aborting send. Required API field '{$field}' is missing or empty. Please check your form mapping in the settings." );
                return false;
            }
        }

        if ( ! filter_var( $request['EmailAddress'], FILTER_VALIDATE_EMAIL ) ) {
            reguest_wp_log_error( 'Aborting send due to invalid email address format: ' . $request['EmailAddress'] );
            return false;
        }

        try {
            if ( isset( $request['ArrivalDate'], $request['DepartureDate'] )
                && new DateTime( $request['ArrivalDate'] ) >= new DateTime( $request['DepartureDate'] ) ) {
                reguest_wp_log_error( 'Aborting send. DepartureDate must be after ArrivalDate.' );
                return false;
            }
            if ( isset( $request['AlternativeArrivalDate'], $request['AlternativeDepartureDate'] )
                && new DateTime( $request['AlternativeArrivalDate'] ) >= new DateTime( $request['AlternativeDepartureDate'] ) ) {
                reguest_wp_log_error( 'Aborting send. AlternativeDepartureDate must be after AlternativeArrivalDate.' );
                return false;
            }
        } catch ( Exception $e ) {
            reguest_wp_log_error( 'Aborting send due to invalid date for comparison. ' . $e->getMessage() );
            return false;
        }

        // The number of ages provided is authoritative; overrides any manually entered Children count.
        if ( isset( $request['RoomOccupancies'][0]['ChildrenAges'] ) ) {
            $numAges = count( $request['RoomOccupancies'][0]['ChildrenAges'] );
            if ( $numAges > 0 ) {
                $request['RoomOccupancies'][0]['Children'] = $numAges;
            }
        }

        if ( isset( $request['RoomOccupancies'][0] ) ) {
            $numChildren = $request['RoomOccupancies'][0]['Children'] ?? 0;
            $numAges     = isset( $request['RoomOccupancies'][0]['ChildrenAges'] )
                ? count( $request['RoomOccupancies'][0]['ChildrenAges'] )
                : 0;
            if ( $numChildren > 0 && $numChildren !== $numAges ) {
                reguest_wp_log_error( "Aborting send. Mismatch between number of children ({$numChildren}) and provided ages ({$numAges})." );
                return false;
            }
        }

        // GuestUserType is always 0 (Person); per API docs, persons use FirstName/LastName, not CompanyName/FamilyName.
        unset( $request['CompanyName'], $request['FamilyName'] );

        $json = json_encode( $request );
        if ( $json === false ) {
            reguest_wp_log_error( 'json_encode failed: ' . json_last_error_msg() );
            return false;
        }

        if ( $debug_mode ) {
            reguest_wp_log_error( "API Request Payload:\n" . json_encode( $request, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
        }

        if ( $test_mode ) {
            reguest_wp_log_error( 'TESTMODUS: API-Aufruf übersprungen.' );
            return true;
        }

        $response = wp_remote_post( $this->baseUrl, [
            'timeout' => 15,
            'headers' => [
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'ReguestWordpressApiClient/1.0',
                'Username'      => $this->username,
                'Password'      => $this->password,
                'ServiceAction' => 'Add',
            ],
            'body' => $json,
        ] );

        if ( is_wp_error( $response ) ) {
            reguest_wp_log_error( 'HTTP Error: ' . $response->get_error_message() );
            return false;
        }

        $http_code     = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        if ( $debug_mode ) {
            reguest_wp_log_error( "API Response (HTTP {$http_code}):\n" . $response_body );
        }

        if ( $http_code < 200 || $http_code >= 300 ) {
            if ( ! $debug_mode ) {
                $error_details = json_decode( $response_body, true );
                $error_message = ( json_last_error() === JSON_ERROR_NONE && isset( $error_details['ExceptionMessage'] ) )
                    ? 'API Error: ' . $error_details['ExceptionMessage']
                    : 'Raw Response: ' . $response_body;
                reguest_wp_log_error( "HTTP Error: Status code {$http_code}. " . $error_message );
            }
            return false;
        }

        $return = json_decode( $response_body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            reguest_wp_log_error( 'JSON Decode Error: ' . json_last_error_msg() );
            return false;
        }

        $is_success = isset( $return['Success'] ) && $return['Success'] === true;

        if ( $debug_mode ) {
            reguest_wp_log_error( 'API Call Result: ' . ( $is_success ? 'Success' : 'Failure' ) );
        }

        return $is_success;
    }

    private function get_country_code_from_name( string $name ): ?string {
        static $countryMap = [
            'deutschland' => 'DE',
            'österreich'  => 'AT',
            'schweiz'     => 'CH',
            'belgien'     => 'BE',
            'bulgarien'   => 'BG',
            'kroatien'    => 'HR',
            'tschechien'  => 'CZ',
            'dänemark'    => 'DK',
            'estland'     => 'EE',
            'finnland'    => 'FI',
            'frankreich'  => 'FR',
            'griechenland' => 'GR',
            'ungarn'      => 'HU',
            'irland'      => 'IE',
            'italien'     => 'IT',
            'lettland'    => 'LV',
            'litauen'     => 'LT',
            'luxemburg'   => 'LU',
            'malta'       => 'MT',
            'niederlande' => 'NL',
            'polen'       => 'PL',
            'portugal'    => 'PT',
            'rumänien'    => 'RO',
            'slowakei'    => 'SK',
            'slowenien'   => 'SI',
            'spanien'     => 'ES',
            'schweden'    => 'SE',
            'zypern'      => 'CY',
            'united kingdom' => 'GB',
            'usa'         => 'US',
        ];

        // mb_strtolower handles multi-byte characters (Ö, ü, etc.) independent of server locale.
        return $countryMap[ mb_strtolower( trim( $name ), 'UTF-8' ) ] ?? null;
    }
}
