=== Digitum GHL WhatsApp Popup ===
Contributors: digitum
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.9.0
License: GPLv2 or later

Floating WhatsApp-style chat button with a chat card that sends leads to GoHighLevel and fires the Google Ads conversion on a real submission.

== Setup ==
1. Plugins > Add New > Upload Plugin, choose the zip, Install, Activate.
2. WhatsApp Popup (left sidebar).
3. Form & GHL: paste the GHL Inbound Webhook URL, press "Send test", add the business WhatsApp number.
4. Tracking: switch on Google Ads conversion, paste Conversion ID (AW-...) and label. Keep "Form is submitted".
5. Save. Open the site in a private window and send a test lead. It appears under the Leads tab.

== Data sent to the GHL webhook ==
full_name, first_name, last_name, phone (E.164), country_code, consent, consent_text, page_url, page_title, referrer,
gclid, gbraid, wbraid, fbclid, utm_source, utm_medium, utm_campaign, utm_term, utm_content, source, website, submitted_at.

== WhatsApp button references ==
Only for Google Ads visits: when the URL of the page carries a gclid, every click on a wa.me / api.whatsapp.com
link (for example an Elementor hero button linking to https://wa.me/971501234567) gets a new unique code such as
REF-KM3P (prefix + 4 capital letters/digits). The code is added to the end of the link's prefilled text (or to the
default message when the link has none):
"Hi! I'd like to know more. Ref: REF-KM3P".
Codes are reserved on the server when the page loads, so the same code is never given to two clicks.
The click saves one row under WhatsApp Popup > WhatsApp clicks: Code | GCLID | Click time | Button | Page
(plus gbraid, wbraid, fbclid and UTMs in the CSV export). Search the code from the WhatsApp message to find the GCLID.
Without a gclid in the page URL, no code is made and nothing is logged: only the prefilled message is sent.
Settings: Tracking tab > WhatsApp button references (prefix, label, default message). The chat popup's own
WhatsApp hand-off is not affected; its submissions stay under Chat leads.

== Google Sheet ==
Each WhatsApp click with a Ref code is also added to a Google Sheet: GCLID | Ref Code | UTC | Pk Time
(12-hour clock, e.g. "19 Oct 2026, 1:05 PM"). Setup is in the Tracking tab > Google Sheet:
1. Row 1 of the sheet has the headers GCLID, Ref Code, UTC, Pk Time (other columns are fine).
2. Extensions > Apps Script: paste the script shown in the plugin, Save.
3. Deploy > New deployment > Web app. Execute as: Me. Who has access: Anyone. Deploy and allow access.
4. Paste the Web app URL (ends with /exec) in the plugin, Send test row, Save settings.
Clicks that could not reach the sheet are marked Failed in the WhatsApp clicks tab and can be re-sent.

== GTM events ==
dataLayer events: dgwc_chat_open, dgwc_lead (with dgwc_phone on the built-in form), dgwc_wa_click (with dgwc_ref, dgwc_button).

== Updates ==
New versions appear in Dashboard > Updates like any other plugin and can be auto-updated
(Plugins > Enable auto-updates). Saved settings, leads and clicks are kept on update.

== Changelog ==
= 1.9.0 =
* Updates from GitHub: new versions show in Dashboard > Updates and support auto-updates.
* One build for every client: business name, WhatsApp number and GHL webhook come only from each site's own settings.

= 1.8.1 =
* Fix: clicks that reached the Google Sheet were shown as Failed (Google answers with a redirect that must be read with GET).

= 1.8.0 =
* Google Sheet: every Ref-code click is added as GCLID | Ref Code | UTC | Pk Time (12-hour clock).
* Sheet status per click, and a button to re-send clicks that did not reach the sheet.

= 1.7.1 =
* Every WhatsApp button and the chat popup open the business WhatsApp number.

= 1.7.0 =
* Reference codes are now short: REF-KM3P (capital letters and digits, 4 characters after the prefix).
* A code is only added, and a click only logged, when the page URL has a gclid. Otherwise the plain prefilled message is sent.
* Codes are reserved on the server, so every code is unique.

= 1.6.0 =
* WhatsApp button references: a new Ref code on every click of any WhatsApp link, added to the prefilled message.
* New WhatsApp clicks tab (Code, GCLID, Click time, Button, Page) with search, CSV export and clear. Stored in its own table.
* Chat form submissions renamed to Chat leads so the two logs stay separate.

= 1.5.0 =
* Send button sits below the form card.
* Phone field with country flags and automatic country detection (intl-tel-input, MIT).
* Settings moved to their own sidebar menu: WhatsApp Popup.

= 1.4.0 =
* First production build from the approved design.
