# ernadoo/boxtal

PHP client for the [Boxtal](https://www.boxtal.com) shipping API: one integration for Colissimo, Mondial Relay,
Chronopost, Relais Colis, Colis Privé… Framework-agnostic (PSR-18 / PSR-17, optional PSR-3 logging).

> **Unofficial.** This library is not affiliated with, endorsed by or supported by Boxtal. "Boxtal" and the carrier
> names are trademarks of their respective owners. For questions about your Boxtal account, contracts or billing,
> contact Boxtal directly.

- **Quotes** with real prices for a parcel (weight, dimensions, from / to): v1 API, the v3 API has no quote yet
- **Shipping orders**: create, read, cancel (v3)
- **Labels** (PDF) and **tracking**, harmonised across carriers (v3)
- **Parcel points**: by network, or the points accepting a given offer (v3)
- **Webhooks**: subscriptions, signature check and parsing of `DOCUMENT_CREATED` / `TRACKING_CHANGED` events

Not covered (yet): token authentication (`/iam/account-app/token`; basic auth with the application keys works on
both APIs), v3 quotes (Boxtal has none yet), pickup at the sender's address, insurance and international customs
documents.

## Installation

```bash
composer require ernadoo/boxtal
```

The client needs a PSR-18 HTTP client and PSR-17 request and stream factories: use the ones your project already
has, for example:

```php
use Ernadoo\Boxtal\BoxtalClient;

// symfony/http-client: Psr18Client is the client and both factories
$psr18 = new \Symfony\Component\HttpClient\Psr18Client();
$boxtal = new BoxtalClient($psr18, $psr18, $psr18, $accessKey, $secretKey);

// guzzlehttp/guzzle 7: HttpFactory (guzzlehttp/psr7, installed with Guzzle) provides both PSR-17 factories
$factory = new \GuzzleHttp\Psr7\HttpFactory();
$boxtal = new BoxtalClient(new \GuzzleHttp\Client(), $factory, $factory, $accessKey, $secretKey);
```

## Credentials

Create an **API v3 application** on the [Boxtal developer app](https://developer.boxtal.com): it gives an access key
and a secret key, used in basic auth on both APIs.

For development, create a separate account on the **test environment** and pass `sandbox: true`: orders are not
billed, labels are generated, and dummy tracking events follow every few minutes until delivery.

In production, ordering through the API requires **deferred payment by direct debit** on the Boxtal account
(billing contact registered as a company, then *Billing preferences › Payment method*).

## Usage

```php
use Ernadoo\Boxtal\BoxtalClient;
use Ernadoo\Boxtal\ParcelPoint\Network;
use Ernadoo\Boxtal\ParcelPoint\Operation;
use Ernadoo\Boxtal\Quote\QuoteRequest;
use Ernadoo\Boxtal\Shipping\Address;
use Ernadoo\Boxtal\Shipping\Package;
use Ernadoo\Boxtal\Shipping\ShippingOrderRequest;
use Symfony\Component\HttpClient\Psr18Client;

$psr18 = new Psr18Client(); // or any PSR-18 client, see Installation
$boxtal = new BoxtalClient($psr18, $psr18, $psr18, $accessKey, $secretKey, sandbox: true);

// Offers and prices for 500 g, 20 × 15 × 8 cm, Paris → Lyon (optionally restricted to some offers)
$offers = $boxtal->quote(new QuoteRequest('75009', 'Paris', '69002', 'Lyon', 0.5, 20, 15, 8,
    offerCodes: ['MONR-CpourToi', 'CHRP-ChronoShoptoShop', 'POFR-ColissimoAccess']));
foreach ($offers as $offer) {
    echo $offer->code(), ' ', $offer->priceInclTax, ' € ', $offer->deliversToPickupPoint() ? 'pickup point' : 'home', "\n";
}

// Pickup points of the recipient: several networks at once, or the points accepting one offer
$points = $boxtal->parcelPoints([Network::MONDIAL_RELAY, Network::CHRONOPOST_SHOP], '69002', 'Lyon');
$shop = $boxtal->parcelPointsForOffer('CHRP-ChronoShoptoShop', '69002', 'Lyon', Operation::Arrival);

// Order: offers delivered to a pickup point need the recipient's point code
$order = $boxtal->createShippingOrder(new ShippingOrderRequest(
    'CHRP-ChronoShoptoShop',
    new Address('Alice', 'Martin', 'alice@example.com', '+33612345678', 'boulevard des Capucines', '75009', 'Paris', number: '4'),
    new Address('Béa', 'Durand', 'bea@example.com', '+33698765432', 'rue de la République', '69002', 'Lyon', number: '20'),
    [new Package(0.5, 20, 15, 8, 15.0, 'Cosmetics')],
    pickupPointCode: $shop[0]->code,
    externalId: 'my-shipment-42',
));

// Label: empty while not available yet (a few seconds), or use the DOCUMENT_CREATED webhook
$label = $boxtal->shippingDocuments($order->id)[0] ?? null;

// Tracking, and cancellation before drop-off (refunded)
$tracking = $boxtal->tracking($order->id);
$boxtal->cancelShippingOrder($order->id);
```

### Webhooks

You choose the secret when subscribing; Boxtal signs each request with it (HMAC SHA-256 of the raw body, header
`x-bxt-signature`) and expects a 2xx answer within 2 seconds; otherwise it retries 10 times over 24 hours, then
drops the event. Answer quickly (queue the work) and answer 2xx for orders you do not know, to avoid retries.

```php
use Ernadoo\Boxtal\Webhook\EventType;
use Ernadoo\Boxtal\Webhook\WebhookParser;

$boxtal->subscribe(EventType::TrackingChanged, 'https://example.com/webhook/boxtal', $webhookSecret);

// In your controller: the raw body, as received
$event = WebhookParser::parse($rawBody, $request->headers->get(WebhookParser::SIGNATURE_HEADER, ''), $webhookSecret);
$event->shipmentExternalId; // your externalId
$event->trackings;          // TRACKING_CHANGED
$event->documents;          // DOCUMENT_CREATED
```

## Error handling

Every method throws an `Ernadoo\Boxtal\Exception\BoxtalException` on failure, one of:

| Exception | When | What to do |
|---|---|---|
| `TransportException` | Boxtal unreachable, timeout, HTTP 5xx, or a body that is not a valid API response | Usually temporary: retry later |
| `ApiException` | Boxtal rejected the request (HTTP 4xx with an error body) | Read the errors: usually invalid data |

`ApiException` gives the HTTP status, the top-level error codes and the detailed errors (field and message):

```php
use Ernadoo\Boxtal\Exception\ApiException;
use Ernadoo\Boxtal\Exception\TransportException;

try {
    $order = $boxtal->createShippingOrder($request);
} catch (ApiException $e) {
    $e->getStatusCode();                                    // 422
    $e->hasErrorCode('RequestValidationException');          // true
    foreach ($e->getErrors() as $error) {
        // ['code' => 'RequestValidationException', 'field' => 'shipment.pickupPointCode', 'message' => '… missing value']
    }
} catch (TransportException $e) {
    // try again later
}
```

`shippingDocuments()` and `tracking()` do not throw while the order is still being processed: they return an empty
list (see below).

## Logging

The client implements `Psr\Log\LoggerAwareInterface`: pass any PSR-3 logger to log created and cancelled orders,
quotes, and every rejected or failed request (method and path, never the credentials).

```php
$boxtal->setLogger($logger);
```

## Things learnt on the real API

- E-mail addresses on reserved domains such as `.test` are refused (`example.com` is accepted).
- Right after ordering, the documents and tracking endpoints answer `422 UnexpectedException` instead of "not
  available yet": `shippingDocuments()` and `tracking()` return an empty list in both cases.
- In a quote, `offers[N]` takes the codes glued together (`MONRCpourToi`) and Boxtal still adds a few offers that
  were not asked for: `quote()` filters them out.
- Boxtal never returns the webhook secret (`webhookSecret` is `null` in subscription responses).
- A parcel point search returns **30 points at most, all networks together**: in a dense city, one network (e.g.
  Colissimo) can hide the others. Search one network at a time (`parcelPoints([Network::MONDIAL_RELAY], …)`) and
  merge the results yourself.
- The same shop has **a different code in each network** (a shop serving Chronopost and Colissimo appears twice,
  with two codes, each listed in one network only). Always order with the code returned for the offer's network.
- Passing the **street** (`parcelPoints(…, street: '12 rue de la République')`) sorts points around that address;
  with the postal code alone, they are sorted around the centre of the postal code area, which can be far off.
- Colissimo gives **no opening hours** (every day is `null`): `ParcelPoint::$openingHours` is then `null`, meaning
  unknown, not closed. `[]` for a day means closed that day.
- Despite its name, `CHRP-Chrono2ShopDirectRetour` is a regular **home delivery** (drop-off at a Chronopost Pickup
  point, delivered to the recipient's address), often the cheapest one.
- The encoding of `x-bxt-signature` is not documented by Boxtal. Other integrations expect lowercase hex (the most
  likely); `WebhookParser` also accepts uppercase hex, base64 and a `sha256=` prefix. If a webhook is ever refused,
  please open an issue with the header format (never the secret).
- Webhooks can be lost or refused: keep a periodic `tracking()` call for orders still in transit as a safety net.

## Checking against the test environment

```bash
BOXTAL_ACCESS_KEY=… BOXTAL_SECRET_KEY=… php examples/sandbox.php
```

Quote, parcel points, order, label, tracking, cancellation, content categories and subscriptions, for real.

## License

MIT
