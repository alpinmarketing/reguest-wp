# Reguest WP

WordPress plugin that forwards form submissions to the [Re:Guest](https://www.reguest.io/) hotel inquiry API via a generic webhook endpoint. Works with any form plugin that supports outgoing webhooks.

## Features

- Generic REST webhook endpoint — compatible with any form plugin
- Visual field mapping UI in the WordPress admin — no code required
- Auto-detects submission language via Polylang (`pll_current_language`)
- Resolves German country names to ISO 3166-1 alpha-2 codes automatically
- Debug mode: logs request payloads and API responses inside the admin panel
- Test mode: simulates the full data pipeline without actually sending to the API
- Settings migration from previous plugin versions

## Requirements

- WordPress 6.9+
- PHP 8.3+
- A form plugin that supports outgoing webhooks (e.g. [Hotelfolio](https://www.alpinmarketing.at/hotelfolio/), Gravity Forms, Fluent Forms, WPForms Pro, Ninja Forms)
- A valid Re:Guest API account (URL, username, password)

## Installation

1. Download the latest release ZIP from the [Releases](../../releases) page.
2. In WordPress go to **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP and click **Install Now**, then **Activate**.

Alternatively, unzip the plugin folder into `wp-content/plugins/` and activate it from the Plugins screen.

## Configuration

### 1. API Credentials

Go to **Settings → Reguest** and fill in:

| Field | Description |
|---|---|
| Plugin aktiv | Master on/off switch |
| API Link | Base URL provided by Re:Guest (e.g. `https://api.reguest.io`) |
| Benutzername | Your Re:Guest username |
| Passwort | Your Re:Guest password |

### 2. Field Mapping

In the **Form Field Mapping** section, map each Re:Guest API field to the corresponding form field name (the `name` attribute of the HTML input).

Use the dropdown to pick an API field, click **Hinzufügen**, then type the matching form field name in the text box.

#### Available API fields

| API Field | Description |
|---|---|
| `EmailAddress` | Guest email *(required)* |
| `ArrivalDate` | Arrival date *(required)* |
| `DepartureDate` | Departure date *(required)* |
| `Anrede` | Salutation — resolves `Herr`/`Mr` → Gender 1, `Frau`/`Mrs`/`Ms` → Gender 2 |
| `Title` | Academic or honorific title |
| `FirstName` / `LastName` | Given and family name |
| `FullName` | Full name (replaces FirstName + LastName) |
| `BirthDate` | Date of birth |
| `StreetName` / `PostalCode` / `CityName` | Address fields |
| `CountryCode` | ISO 3166-1 alpha-2 code, or a German country name (auto-converted) |
| `PhoneNumber` / `MobileNumber` / `FaxNumber` | Contact numbers |
| `Text` | Free-text message |
| `LanguageCode` | ISO 639-1 language code (auto-detected from `_wp_locale` if sent by the form) |
| `NewsletterSubscription` | Boolean — accepts `true`, `1`, `yes`, etc. |
| `AlternativeArrivalDate` / `AlternativeDepartureDate` | Alternative travel dates |
| `OfferName` / `OfferCode` | Offer name and code |
| `Adults` | Number of adults (maps into `RoomOccupancies`) |
| `Children` | Number of children (maps into `RoomOccupancies`) |
| `ChildrenAges` | Comma-separated ages — also determines the Children count |
| `SourceOfBusiness` | Booking source (e.g. `Website`) |
| `ForeignId` | External reference ID |
| `ThirdPartyNotes` | Notes for third parties |

### 3. Webhook Endpoint

The plugin exposes a REST endpoint that your form plugin calls on submission:

```
POST https://your-site.com/wp-json/reguest-wp/v1/submit
```

The **Webhook URL** and **Webhook Token** are displayed at the bottom of the Settings → Reguest page. Copy both values into your form plugin's webhook configuration.

#### Request format

The endpoint expects a JSON body with a flat key → value structure matching your field mapping, plus an optional `_wp_locale` field:

```json
{
  "vorname": "Hans",
  "familienname": "Muster",
  "email": "hans@example.com",
  "ankunft": "2026-06-01",
  "abreise": "2026-06-08",
  "erwachsene": "2",
  "_wp_locale": "de"
}
```

#### Authentication

Every request must include the token as a header:

```
X-HF-Token: your-webhook-token
```

Requests with a missing or incorrect token are rejected with HTTP 401.

#### Hotelfolio (HF Forms)

Open the `hf_form` post in the WordPress editor → **Settings tab**:

| Field | Value |
|---|---|
| Webhook URL | copied from Settings → Reguest |
| Webhook Token | copied from Settings → Reguest |

The language (`_wp_locale`) is automatically included in the payload from the active Polylang language at the time the form page was rendered. No hidden field required.

## Debug & Test modes

| Mode | Effect |
|---|---|
| **Debug** | Logs request payloads and API responses to a transient visible at the bottom of the settings page (last 100 entries). Disable on live sites. |
| **Testmodus** | Builds and validates the full payload but skips the actual HTTP call. Useful for verifying field mapping. Requires Debug to be on to see the simulated output. |

## Date format

Dates are accepted in any format PHP's `DateTime` can parse and are normalised to `Y-m-d` before being sent to the API.

## Build

```bash
./build-zip.sh
```

Creates `dist/reguest-wp.zip`, ready for upload to WordPress.

## Disclaimer

This plugin is provided as is, without warranty of any kind, express or implied. The author accepts no liability for any damages, data loss, security issues, or other consequences arising from the use or inability to use this plugin. Use at your own risk.

The plugin communicates with the external [reguest.io](https://www.reguest.io/) API. The author is not responsible for the availability, accuracy, or content of that service.

## License

This plugin is released under the GNU General Public License v2.0 or later.

Copyright (C) 2026 [ALPINMARKETING®](https://www.alpinmarketing.at)
