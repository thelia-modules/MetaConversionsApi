# Meta Conversions Api

Sends the conversions of the shop to the Meta Conversions API, from the server: PageView, AddToCart,
InitiateCheckout, CompleteRegistration and Purchase.

Thelia 3.2 or later. The 2.x line of this module is for Thelia 2.

## Installation

```
composer require thelia/meta-conversions-api-module:^3.0
php bin/console module:refresh
php bin/console module:activate MetaConversionsApi
```

Nothing is sent unless the environment variable `META_CONVERSION_ENV` is `prod` (in `.env.local` of the
production server only): a copy of the production database never writes into the merchant's Meta account.

## Configuration

In the back office, module configuration page:

| Setting | |
|---|---|
| Pixel Id | the Meta pixel (digits) |
| Token | the access token of the Conversions API; the field is never filled back, leave it empty to keep the saved token |
| Active Tracker | nothing is sent while unticked |
| Send the customer personal data | e-mail, phones, names, city, zip code, country and customer reference, hashed in SHA-256 after the normalisation Meta asks for: lower case and trimmed, names reduced to their letters, phones in digits with the calling code of the address country and without the national 0 (`33612345678`) |
| Send only with the visitor consent | on by default; see below |
| Meta vendor name in the consent tool | `facebook_pixel` by default |
| Days the visitor details of an unpaid order are kept | 30 by default, see Purchase |
| Test Event Code, Test Mode | the code of the Meta test events tool, sent while the test mode is ticked |

## Events

| Event | When | Shared identifier with the pixel |
|---|---|---|
| PageView | every HTML page of the shop answering a GET (not the back office, the API, an XHR, a Turbo frame or a live component) | no |
| AddToCart | a product added to the cart (`CART_ADDITEM`), with the added quantity; not for a product offered by a coupon | yes, see below |
| InitiateCheckout | a delivery address chosen (`CART_SET_DELIVERY_ADDRESS`), once per visit until an order is placed | no |
| CompleteRegistration | an account created from the front office (`CREATE_CUSTOMER_MINIMAL`, `CUSTOMER_CREATEACCOUNT`) | no |
| Purchase | an order reaching the paid status | the order reference |

The events of a request are queued and sent in a single call once the response is gone (`kernel.terminate`, or
the end of a command), with a 2 second timeout. Unit prices and values include taxes. A failure is logged,
without the token, and never thrown: a failure of the module never stops a page, a cart, an order or a payment.

The response leaves before the call only under PHP-FPM, where Symfony calls `fastcgi_finish_request()` before
`kernel.terminate`. Under another server API (mod_php, the PHP built-in server) the visitor waits for the call,
up to the timeout. After 5 failures in a row (timeout, error answer), nothing is sent for 10 minutes (state kept
in the `cache.app` pool), so that an unreachable Meta does not hold every PHP worker; the events of that window
are dropped.

### Consent

With "Send only with the visitor consent" ticked, a visitor event leaves only when the visitor accepted the Meta
vendor in Axeptio (cookie `axeptio_authorized_vendors`, or `axeptio_cookies`). For Purchase, the consent is the
one given when the order was placed: a visitor who withdraws it afterwards still sends the Purchase of that order. The vendor must be declared in the
Axeptio project, otherwise nobody can accept it and nothing is sent. Another consent tool plugs in by aliasing
`MetaConversionsApi\Service\VisitorConsentInterface` to its own reader.

### Purchase

The paid status is often set without the visitor's browser: the server call of a payment provider, a cheque or a
transfer recorded later by the merchant. When the visitor places the order (`ORDER_BEFORE_PAYMENT`), the module
freezes on the order, in the core `meta_data` table (key `meta_conversions_api_visitor`), the browser details of a
consenting visitor: IP address, user agent, `_fbc` and `_fbp` cookies, page address. Purchase reads them, never
the request that marks the order paid. An order without them sends no Purchase: the visitor refused, or the order
was created from the back office or the command line.

These details are personal data, kept only as long as they are needed:

- deleted as soon as Purchase is queued (an order paid again sends no second Purchase);
- for an order never paid, deleted by `php bin/console maintenance:purge` once older than the retention period
  (30 days by default, setting of the configuration page): schedule this command;
- exported with the personal data of the customer (section `meta_conversions_api`) and erased when the customer is
  anonymized (`CustomerPersonalDataProviderInterface`).

### Identifier shared with the pixel

The theme gets the identifier of the last AddToCart sent by the server with the Twig function
`meta_conversions_api_event_id('AddToCart')` (read once, null when none is waiting) and passes it as `eventID` to
the pixel, so that Meta counts the conversion once. Purchase uses the order reference.

## Tests

Run from the root of the Thelia project, against a disposable test database whose name ends with `_test`:

```
php bin/test-prepare
vendor/bin/phpunit --bootstrap vendor/thelia/modules/MetaConversionsApi/Tests/bootstrap.php vendor/thelia/modules/MetaConversionsApi/Tests
```

Nothing leaves the machine: the Conversions API is replaced by a mock.

## Changes in 3.0.0

- Thelia 3: services by `configureServices()`, route by attribute, Twig configuration page for the default-twig
  back office. `routing.xml`, `schema.xml` and the Smarty templates are gone.
- The Meta business SDK is replaced by the Symfony HTTP client of the module, built without a logger: the access
  token travels in the request body and never reaches a log.
- Events are sent after the response in one call, with a short timeout, instead of one blocking call per event.
  PageView no longer adds a network call to the rendering of every page.
- Consent: visitor events wait for the Axeptio consent (setting on by default).
- The visitor details frozen on an order are erased once Purchase is queued, purged after a retention period
  (`maintenance:purge`, 30 days by default), exported and anonymized with the customer.
- A failure of the module is logged and never stops the shop. After 5 failures in a row, sending stops for 10
  minutes.
- Purchase reads the visitor details frozen on the order when it was placed, not those of the request that marks
  it paid (the payment provider's server). Orders created from the back office send no Purchase.
- InitiateCheckout listens to `CART_SET_DELIVERY_ADDRESS`: `ORDER_SET_DELIVERY_ADDRESS` is no longer dispatched by
  Thelia 3. CompleteRegistration also listens to `CREATE_CUSTOMER_MINIMAL`, the registration of the front office.
- AddToCart carries the added quantity and the price with taxes (promotional price when on sale), shares its
  identifier with the pixel, and is not sent for a product offered by a coupon. Purchase unit prices include taxes.
- Phones carry the calling code of the address country, names keep their letters only, as Meta asks.
- Purchase is worth what the customer pays (discount deducted); the 2.x line left the discount out.
- The customer reference (`external_id`) is hashed like the other personal data. A personal value Meta would
  refuse (invalid e-mail) is left out instead of losing the event.
- The token is no longer shown in the configuration page. The settings of the 2.x line are kept.
