# Digitum GHL WhatsApp Popup

WordPress plugin: floating WhatsApp-style chat button that sends leads to GoHighLevel and tracks Google Ads conversions. See `readme.txt` for setup and the changelog.

## Releasing an update

1. Bump the version in three places: `Version:` and `DGWC_VERSION` in `digitum-whatsapp-chat.php`, and `Stable tag:` in `readme.txt`.
2. Add a `= x.y.z =` changelog entry in `readme.txt`.
3. Commit and run `bin/release.sh`.

The script builds the zip, tags the version, and publishes it on the public repo
[shahbazshafi/digitum-whatsapp-chat-releases](https://github.com/shahbazshafi/digitum-whatsapp-chat-releases).
Client sites check that repo (`includes/class-dgwc-updater.php`), so this source repo can stay private.

Before submitting to the WordPress.org directory, remove the `Update URI` header and the updater class: WordPress.org handles updates itself and does not allow custom updaters.
