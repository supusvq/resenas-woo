=== Reseñas Woo ===
Contributors: juangallardo
Tags: google reviews, woocommerce, reviews, customer feedback, testimonials
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 2.11.9
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Display Google reviews locally and automate review requests after WooCommerce purchases.

== Description ==

**Reseñas Woo** importa reseñas de Google Maps, las guarda localmente en WordPress y permite mostrarlas con varios diseños listos para usar.

### Main Features
* Import reviews through an external service.
* Store reviews locally for fast frontend rendering.
* Display reviews in horizontal, vertical, square, or spotlight layouts.
* Send review request emails automatically after WooCommerce purchases.
* Manage invitations, retries, and delivery logs from the WordPress admin.
* Filter displayed reviews by star rating.

### External Service
This plugin can connect to an external review import service. The site owner must configure the service URL and explicitly consent before any Google Maps URL is sent to that service.
The plugin sends only the Google Maps URL, the configured review limit, the site language, the site URL, and an optional internal site token when an import is manually triggered by an administrator.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the `Plugins` menu in WordPress.
3. Go to `Reseñas Woo > Configuración general`.
4. Paste the Google Maps URL of your business.
5. Enter the base URL of your review import service.
6. Accept the external service consent checkbox.
7. Save the settings and import your reviews.

== Frequently Asked Questions ==

= Do I need a Google API Key? =
No. This version imports reviews from a Google Maps URL using an external import service.

= Does the plugin contact an external server? =
Yes, but only after the site owner configures the service URL and explicitly enables consent in the settings page.

= How do I display the reviews? =
Use the shortcode `[mis_resenas_google]`. It supports attributes such as `design="horizontal"`, `design="spotlight"`, `limit="5"`, and `theme="light"`.

= Are emails sent immediately? =
You can configure a delay in days. If the delay is 0, the plugin waits 5 minutes after order completion to send the email.

== Screenshots ==

1. General settings panel with Google Maps URL import.
2. Review invitations list and delivery status.
3. Customization of email content and design.
4. Frontend reviews widget with spotlight layout.

== Changelog ==

= 2.11.9 =
* Replaced unused email text variables with an editable Google review link variable.
* Migrated saved email templates so legacy placeholders do not appear in outgoing emails.

= 2.11.8 =
* Added editable email variables for company name, review button text, and review intro text.

= 2.11.7 =
* Added a defensive privacy module check to avoid fatal errors on incomplete uploads.

= 2.11.6 =
* Centered the spotlight summary and reduced the Google logo size.

= 2.11.5 =
* Improved the spotlight slider layout with compact Google-style summary and review cards.

= 2.11.4 =
* Added an option to show only reviews with written comments in the frontend slider.

= 2.11.3 =
* Added clear header controls for the displayed total review count and header stars.
* Fixed frontend theme styles and strengthened slider mode and star filter settings.

= 2.11.2 =
* Simplified the settings screen to show only useful controls for the current import flow.
* Fixed review display values so limit, speed, cache, and header ratings are now handled automatically.

= 2.10.6 =
* Fixed the review pipeline to keep and display only the 6 most recent reviews.
* Updated the import limit so the backend and frontend stay aligned on 6 reviews.

= 2.10.5 =
* Prepared the plugin to use the fixed scraper service URL at scraper.supufactory.es.
* Bumped the plugin version for a clean upload and detection of the latest release.

= 2.10.0 =
* Replaced the old Google Places flow with Google Maps URL import through an external service.
* Removed obsolete Place ID and API key logic from the plugin.
* Kept reviews stored locally after each import.

= 2.9.3 =
* Added new spotlight review layout inspired by Google-style review rails.

= 2.9.2 =
* Fixed functional bugs in emails, logs, and frontend rendering.

= 2.1 =
* Improved SMTP error handling.
* Refactored architecture to namespaces and autoloader.
* Added support for manual and automatic slider mode.
* Full internationalization support.

= 2.0 =
* Added post-sale email delivery system.
* Added local review storage for better performance.

= 1.0 =
* Initial release.
