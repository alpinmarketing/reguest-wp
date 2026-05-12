# AM Hotelfolio Reguest

WordPress plugin that forwards form submissions to the [Re:Guest](https://www.reguest.io/) hotel inquiry API. Supports **Contact Form 7** and **HF Forms**, with optional Polylang integration for multilingual sites.

## Features

- Connects Contact Form 7 and HF Forms to the Re:Guest API
- Visual field mapping UI in the WordPress admin — no code required
- Auto-detects submission language via CF7 locale or Polylang (`pll_current_language`)
- Resolves German country names to ISO 3166-1 alpha-2 codes automatically
- Debug mode: logs request payloads and API responses inside the admin panel
- Test mode: simulates the full data pipeline without actually sending to the API
- Settings migration from the previous plugin version (pre-Settings API)

## Requirements

- WordPress 5.8+
- PHP 7.4+
- [Contact Form 7](https://wordpress.org/plugins/contact-form-7/) or [HF Forms](https://wordpress.org/plugins/hf-form/) (or both)
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

In the **Form Field Mapping** section, map each Re:Guest API field to the corresponding form field name (the `name` attribute of your CF7 tag or HF Forms input).

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
| `LanguageCode` | ISO 639-1 language code (auto-detected if Polylang is active) |
| `NewsletterSubscription` | Boolean — accepts `true`, `1`, `yes`, etc. |
| `ArrivalDate` / `AlternativeArrivalDate` | Primary and alternative arrival |
| `DepartureDate` / `AlternativeDepartureDate` | Primary and alternative departure |
| `OfferName` / `OfferCode` | Offer name and code |
| `Adults` | Number of adults (maps into `RoomOccupancies`) |
| `Children` | Number of children (maps into `RoomOccupancies`) |
| `ChildrenAges` | Comma-separated ages — also determines the Children count |
| `SourceOfBusiness` | Booking source (e.g. `Website`) |
| `ForeignId` | External reference ID |
| `ThirdPartyNotes` | Notes for third parties |

### 3. Add the trigger field to your form

The plugin only fires when a hidden field named **`reguest`** with value **`true`** is present in the submission. Add this to every form that should send data to Re:Guest:

**Contact Form 7:**
```
[hidden reguest "true"]
```

**HF Forms / native HTML:**
```html
<input type="hidden" name="reguest" value="true">
```

Forms without this field are silently ignored.

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

Creates `dist/am-hotelfolio-reguest.zip`, ready for upload to WordPress.

## License

Proprietary — © Ing. Christian Fohrmann / [Alpin Marketing](https://www.alpinmarketing.at)
