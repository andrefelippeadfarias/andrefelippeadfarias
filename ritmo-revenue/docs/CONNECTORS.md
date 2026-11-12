# Ritmo Revenue: connectors

What `src/Connectors` offers: the Beds24 PMS connector and the PriceLabs pricing connector, both read-only in milestone M1, plus the simulator and the fakes that test them. The contracts they implement are in [CONTRACTS.md](CONTRACTS.md); the HTTP clients, limiter and ledger they are wired with are in [INFRASTRUCTURE.md](INFRASTRUCTURE.md).

## 1. Wiring

```php
$guarded = new GuardedHttpClient(new WpHttpClient(), $readRoutes, $logger);   // see section 3 for $readRoutes

$pms = new Beds24Connector(
    $guarded,
    new Beds24Config(Beds24Credentials::refreshToken($vault->reveal('beds24_refresh')), $roomTypes->pmsMap(), [/* Beds24 property ids */]),
    $clock,
    $logger
);

$limiter = new RateLimiter($clock, new SystemSleeper(), [
    RateLimit::perMinute(PriceLabsConnector::REQUESTS_PER_MINUTE),
    RateLimit::perHour(PriceLabsConnector::REQUESTS_PER_HOUR),
]);
$pricing = new PriceLabsConnector(
    new ThrottledHttpClient($guarded, $limiter, PriceLabsConnector::ID),
    new PriceLabsConfig($vault->reveal('pricelabs_key'), 'beds24'),
    $clock,
    $quotaLedger,                                   // DbQuotaLedger: the hourly budget holds across runs
    $logger
);
```

- `Beds24Credentials::longLifeToken()` sends the token as it is; `refreshToken()` trades it for access tokens by `GET /authentication/token` and keeps each one until `expiresIn` minus five minutes (never assumed when the API gives no lifetime). A `401` renews the access token and repeats the request once.
- `Beds24Config` takes the room map (Beds24 room id to room type code) so `BookingRecord::$roomCode` is filled; an unmapped room gives `null` with `roomPmsRef` set. `minimumCredit` (default 5) is the five-minute credit below which no request is sent.
- `PriceLabsConfig` takes the API key and the PMS name PriceLabs uses for the listings; only listings of that PMS are returned.
- Both config objects hide their secret from dumps and refuse to be serialised.
- Inside, `Beds24Client` and `PriceLabsClient` hold the HTTP conversation (headers, token, credit, budget, pages, statuses); `Query` builds query strings where a list repeats the parameter (`status=new&status=request`), the form both APIs expect.

## 2. What each connector reads

| Connector | Method | Request | Notes |
|---|---|---|---|
| `Beds24Connector` | `testConnection()` | `GET /properties` | Never throws. |
| | `readBookingsByStay()` | `GET /bookings` with `arrivalTo`, `departureFrom`, every status, `includeInvoiceItems` | Pages by `pages.nextPageExists`. Only stays with a night in the range are kept. |
| | `readBookingsModifiedSince()` | `GET /bookings` with `modifiedFrom` (UTC) | A booking without any timestamp is kept. |
| | `readInventory()` | `GET /inventory/rooms/calendar` with `numAvail`, `minStay`, `maxStay`, `override` (and `prices` when asked) | Ranges become nights; `available` is passed through, negative when overbooked. |
| `PriceLabsConnector` | `testConnection()`, `listListings()` | `GET /listings` | |
| | `readPrices()` | `POST /listing_prices` | A read that takes its parameters in the body (section 3). |
| | `readOverrides()` | `GET /listings/{id}/overrides?pms=` | Floors and ceilings in `percent_base` become `minPercent`/`maxPercent`; `fixed` ones `minFixed`/`maxFixed`. |

`BookingMapper` copies a closed list of fields: ids, room, dates, status, channel, referer, amounts, timestamps and quantity. Guest names, contacts, addresses, comments, notes and every other free text never leave the mapper. The total is the sum of the invoice charges billed to the guest (payments and charges of another invoicee are not revenue of the stay), else the booking price.

`InventoryMapper` maps the Beds24 override to `Restriction` (`none`, `blackout`, `noCheckIn`, `noCheckOut`, `noCheckInOrCheckOut`); an unknown value is an error, not a silent "none". `ListingMapper`, `PriceNightMapper` and `OverrideMapper` read the PriceLabs payloads through `Payload`, which treats text numbers, `-1`/`0` sentinels and missing fields as "no value".

Writing is not available: `writeCalendar`, `writeOverrides`, `deleteOverrides`, `updateListing` and `requestRecalculation` come from the contract traits and throw `NotAvailableInThisMilestone` without sending anything.

## 3. The calendar read of PriceLabs is a POST

`GuardedHttpClient` lets only `GET` leave the site. With `ReadRouteAllowList::none()`, `readPrices()` ends in `WriteBlocked` before anything is sent, and the run takes prices from `Beds24Connector::readInventory(..., withPrices: true)` instead (option (a) of ARCHITECTURE.md section 6). To use `readPrices()` (option (b)), the composition root lists that one route, and only after the owner decides it:

```php
$target = RequestTarget::fromUrl($priceLabsConfig->baseUrl . PriceLabsConnector::PRICES_PATH);
$readRoutes = ReadRouteAllowList::none()->with(PriceLabsConnector::ID, 'POST', $target->host, $target->path);
```

`PriceLabsClient` can send that POST and nothing else but GET; the tests prove both outcomes.

## 4. Errors and limits

- Every failure is a `ConnectorException` (`provider()` is `beds24` or `pricelabs`): `AuthFailed` for `401`/`403` and a refused refresh token, `RateLimited` for `429` (with the reset time the API gave), retryable for `5xx` and `408`, not retryable for a body outside the documented format. `HttpFailure` builds them; messages never quote a response body.
- Beds24: `CreditWindow` reads the five-minute credit headers of every response (`X-Five-Min-Limit-Remaining`/`-Resets-In`/`X-Request-Cost`, or the spec spelling `X-FiveMinCreditLimit-*`/`X-RequestCost`) and stops with `RateLimited` before a request the credit cannot pay. It never waits.
- PriceLabs: `RequestBudget` counts every request sent (failed ones too) in the `QuotaLedger` and refuses the next one when the last hour already holds `REQUESTS_PER_HOUR`. The per-minute pace is the `ThrottledHttpClient` of section 1.

## 5. Base URLs and the simulator

`BaseUrl` keeps the production URLs (`Beds24Connector::BASE_URL`, `PriceLabsConnector::BASE_URL`). A config may replace one only while the constant `RITMO_DEV` is exactly `true`, and only with a plain `http(s)` URL.

`tests/fake-apis/router.php` serves both APIs in their real format from the invented world of `tests/fixtures/api/scenario.json` (one property, rooms of 4, 2 and 1 units, 60 nights, bookings of every status, a sold-out and an overbooked night, varied overrides; synthetic guest fields marked `GUESTDATA` so tests can prove they are dropped):

```
RITMO_FAKE_API_LOG=/tmp/fake.log [RITMO_FAKE_API_START=2026-11-12] php -S 127.0.0.1:8099 tests/fake-apis/router.php
base URLs: http://127.0.0.1:8099/beds24/v2 and http://127.0.0.1:8099/pricelabs/v1
```

It logs every request (method, path, query, status), answers `GET /__log`, `POST /__reset`, `POST /__revoke` (access tokens stop working) and `POST /__fault` (a status and headers for the next requests to a path: 401, 429 with credit headers, low credit), and refuses every write with `405`. `RITMO_FAKE_API_START` moves the whole scenario in time.

## 6. Test doubles (`tests/Fakes`)

| Double | Use |
|---|---|
| `FakeApiServer` | Starts the simulator on a free port, reads and resets its log, asks for faults. |
| `ScriptedHttpClient` | `HttpClient` that answers from a queue or a handler and records every request. |
| `InMemoryPmsConnector`, `InMemoryPricingConnector` | Connectors over data in memory with the contract's semantics, for tests of whatever consumes them; a conformance test holds them to the real connectors. |
| `MemoryQuotaLedger`, `RecordingLogger`, `MovableClock`, `ClockSleeper` | Ledger, logger, clock and sleeper doubles. |
