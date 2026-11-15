=== Ritmo Revenue ===
Contributors: ritmo
Tags: revenue management, hotel, pricing, inn, booking
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0

Revenue management for small hotels and inns: pace-based floors and ceilings, market anchors and safe, auditable rate updates.

== Description ==

Ritmo Revenue decides the floor and the ceiling of every room night of a small hotel or inn with a fixed, auditable rule engine. It reads bookings and availability from your PMS, prices and date rules from your dynamic pricing system and the competitor prices you bring, and explains every number in plain words.

**It writes nothing until you say so.** Every property starts in **shadow mode**: the plugin reads, decides and shows what it would do, and writes nothing to any system. A person moves a property to **approval mode** (only what a person approved is written) and, when the site allows it, to **autonomous mode**.

Safety comes first, in every mode:

* Today's price never goes up.
* No price goes below the absolute floor of its room.
* Prices are never written to the PMS, and bookings are never created, changed or cancelled. The PMS only receives availability, minimum and maximum stay, arrival and departure rules and channel limits.
* The minimum, base and maximum prices of a listing change only with an explicit human approval by someone allowed to change the safety limits.
* Every write is sent in small parts, stops at the first failure and is read back; a night someone else changed by hand is frozen and never overwritten.
* An emergency switch stops every write at once, from the screen or from wp-config.php.
* Every change is in the change log, with who, when, before and after, and can be undone.

What you get:

* A setup wizard that connects your systems, maps your rooms, sets the safety limits and ends with a shadow run that shows what the plugin would do.
* The nights grid, with the reason of every number; approvals by room and run of nights, with the amounts before and after.
* Rules editor (every change is a new version of the rules and never changes a price by itself), room relations written as sentences, seasons, holidays (Brazil and Portugal included) and owner orders.
* Market prices of your competitors by CSV or typed in, with reminders when they get old.
* Reports: weekly performance, month and goals, channels, the next 12 months, targets and forecast, the report of every run, all downloadable as CSV.
* Alerts and a to-do list, a scheduler on WP-Cron with full and light runs, and a support file without keys or guest data.
* An optional assistant that uses your own Claude API key to write report texts, explain alerts and turn a request in your words into a draft order or rule change. It never writes a price: a draft always waits for a person and goes through the same checks.
* Licence and signed updates.
* English and Brazilian Portuguese.

Supported systems: Beds24 (PMS, API v2) and PriceLabs (dynamic pricing, Customer API).

== Installation ==

1. Check the requirements: WordPress 6.5 or newer, PHP 8.1 or newer, HTTPS, and scheduled tasks that run (WP-Cron with visits, or a real cron that calls wp-cron.php every 5 minutes).
2. Recommended: add a key of your own for the plugin's secrets vault to wp-config.php, with 32 characters or more: `define( 'RITMO_SECRET_KEY', '...' );`
3. In Plugins > Add New > Upload Plugin, upload the ritmo-revenue zip and activate it.
4. Open Ritmo Revenue > Settings > Setup and follow the wizard: connect the PMS and the pricing system, check the property and the rooms, confirm the safety limits and run in shadow.
5. Leave the property in shadow mode for a few days and compare what the plugin would do with what you do today before you switch on approval mode.

== Frequently Asked Questions ==

= Does the plugin change my prices as soon as it is installed? =

No. Every property starts in shadow mode and stays there until a person changes the mode on the Operations screen. In shadow mode nothing is written to any system.

= Which keys does it need? =

A token of your PMS (Beds24: a long-life token with read access is enough while you only watch in shadow mode; writing stay rules later needs a refresh token with write access to the inventory) and the API key of your pricing system (PriceLabs: Account Settings > API Details). Keys are stored encrypted and are never shown again.

= Does it store guest data? =

No. It never keeps names, e-mail addresses, phone numbers or documents of guests. A booking appears only by its number and channel, in screens, reports, alerts, exports and the support file.

= The runs do not happen. Why? =

WP-Cron runs only when someone visits the site. On a quiet site, set up a real cron job on the server that calls wp-cron.php every 5 minutes.

= How do I stop every write at once? =

Turn on the emergency switch on the Operations screen, or add `define( 'RITMO_KILL_SWITCH', true );` to wp-config.php (the screen cannot turn that one off).

= Do I need the AI assistant? =

No. The plugin works the same without it. The assistant is off until you save your own Claude API key, and the cost of each answer is billed to your Anthropic account.

= What happens when I delete the plugin? =

By default the plugin keeps its data, so that deleting it by mistake loses nothing. To remove its tables, settings, roles, capabilities and scheduled events on deletion, tick the option in Settings > Uninstall first.

== External services ==

The plugin connects only to the services you configure, and only from the server:

* Your PMS (Beds24, https://api.beds24.com): reads properties, rooms, bookings with their invoice items and the availability calendar; writes only availability, stay and arrival/departure rules, never in shadow mode. Sent: your token and the ids of your properties and rooms.
* Your pricing system (PriceLabs, https://api.pricelabs.co): reads listings, prices, date rules and reservations; writes floors, ceilings and stay rules of nights, never in shadow mode. Sent: your API key and the ids of your listings.
* Anthropic (https://api.anthropic.com), only when you turn on the assistant with your own key: sends the question and the figures it needs (rules, counts, prices and occupancy), never guest data.
* The licence server, only when the site defines RITMO_LICENCE_SERVER_URL: sends the licence key, the site address and the plugin version.
* The update server, only when an update address is configured: reads the signed description of the latest version and downloads the package, which is installed only when its signature is valid.

== Changelog ==

= 1.0.0 =
* First release: shadow, approval and autonomous modes with the safety limits, the write gate, read-back and undo; scheduler with full and light runs; alerts; the twelve admin screens with the setup wizard; rules, relations, calendar and orders editors; market prices by CSV; reports; optional AI assistant with your own key; licence and signed updates; English and Brazilian Portuguese.

== Upgrade Notice ==

= 1.0.0 =
First release.
