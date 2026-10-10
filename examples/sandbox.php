<?php

/*
 * End-to-end check against the Boxtal TEST environment (no billing): quote, parcel points, order, label, tracking,
 * cancellation, content categories, webhook subscriptions.
 *
 *   BOXTAL_ACCESS_KEY=… BOXTAL_SECRET_KEY=… php examples/sandbox.php
 *
 * Keys of an API v3 application created on a test account (https://developer.boxtal.com).
 */

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use Ernadoo\Boxtal\BoxtalClient;
use Ernadoo\Boxtal\ParcelPoint\Network;
use Ernadoo\Boxtal\ParcelPoint\Operation;
use Ernadoo\Boxtal\Quote\QuoteRequest;
use Ernadoo\Boxtal\Shipping\Address;
use Ernadoo\Boxtal\Shipping\Package;
use Ernadoo\Boxtal\Shipping\ShippingOrderRequest;
use Ernadoo\Boxtal\Webhook\EventType;
use Symfony\Component\HttpClient\Psr18Client;

$accessKey = getenv('BOXTAL_ACCESS_KEY') ?: exit("BOXTAL_ACCESS_KEY is missing\n");
$secretKey = getenv('BOXTAL_SECRET_KEY') ?: exit("BOXTAL_SECRET_KEY is missing\n");
$psr18 = new Psr18Client();
$boxtal = new BoxtalClient($psr18, $psr18, $psr18, $accessKey, $secretKey, sandbox: true);

function step(string $title): void
{
    echo "\n== ", $title, "\n";
}

step('Quote: 500 g, 20 × 15 × 8 cm, Paris → Lyon');
$offers = $boxtal->quote(new QuoteRequest('75009', 'Paris', '69002', 'Lyon', 0.5, 20, 15, 8));
usort($offers, static fn ($a, $b) => $a->priceInclTax <=> $b->priceInclTax);
foreach (array_slice($offers, 0, 8) as $offer) {
    printf("  %-30s %-28s %6.2f € TTC  %-13s → %-12s %s day(s)\n", $offer->code(), $offer->carrierName.' '.$offer->serviceName, $offer->priceInclTax,
        $offer->collectionType, $offer->deliveryType, $offer->transitDays() ?? '?');
}
printf("  %d offers\n", count($offers));

step('Quote restricted to two offers');
foreach ($boxtal->quote(new QuoteRequest('75009', 'Paris', '69002', 'Lyon', 0.9, 20, 15, 8, offerCodes: ['MONR-CpourToi', 'CHRP-ChronoShoptoShop'])) as $offer) {
    printf("  %-24s %6.2f € TTC\n", $offer->code(), $offer->priceInclTax);
}

step('Parcel points of two networks near 69002 Lyon');
$points = $boxtal->parcelPoints([Network::MONDIAL_RELAY, Network::CHRONOPOST_SHOP], '69002', 'Lyon');
foreach (array_slice($points, 0, 5) as $point) {
    printf("  %-8s %-32s %4d m  %s%s\n", $point->code, $point->name, $point->distanceMeters, implode(',', $point->networks), $point->isLocker() ? ' (locker)' : '');
}
printf("  %d points\n", count($points));

step('Pickup points accepting Chronopost Shop2Shop near 69002 Lyon');
$shop = $boxtal->parcelPointsForOffer('CHRP-ChronoShoptoShop', '69002', 'Lyon', Operation::Arrival);
$point = $shop[0] ?? exit("no point\n");
printf("  %d points, first: %s %s, %s %s %s\n", count($shop), $point->code, $point->name, $point->street, $point->postalCode, $point->city);

step('Order Chronopost Shop2Shop to that point');
$order = $boxtal->createShippingOrder(new ShippingOrderRequest(
    'CHRP-ChronoShoptoShop',
    new Address('Alice', 'Essai', 'alice.essai@example.com', '+33612345678', 'boulevard des Capucines', '75009', 'Paris', number: '4'),
    new Address('Béa', 'Essai', 'bea.essai@example.com', '+33698765432', 'rue de la République', '69002', 'Lyon', number: '20'),
    [new Package(0.5, 20, 15, 8, 15.0, 'Cosmétiques d’occasion entre particulières')],
    pickupPointCode: $point->code,
    externalId: 'ernadoo-boxtal-sandbox-'.date('YmdHis'),
));
printf("  %s %s, %.2f € HT, delivery %s\n", $order->id, $order->status->value, $order->priceExclTax, $order->estimatedDeliveryDate?->format('Y-m-d'));

step('Label');
$documents = [];
for ($i = 0; $i < 6 && [] === ($documents = $boxtal->shippingDocuments($order->id)); ++$i) {
    sleep(5);
}
foreach ($documents as $document) {
    printf("  %s %s %s…\n", $document->type, $document->format, substr($document->url, 0, 80));
}

step('Order and tracking');
printf("  status %s\n", $boxtal->getShippingOrder($order->id)->status->value);
foreach ($boxtal->tracking($order->id) as $tracking) {
    printf("  %s %s · %s · %s\n", $tracking->status?->value, $tracking->message, $tracking->trackingNumber, $tracking->trackingUrl);
}

step('Cancellation');
printf("  %s\n", $boxtal->cancelShippingOrder($order->id)->status->value);

step('Content categories');
$categories = $boxtal->contentCategories();
printf("  %d categories, content:v1:30100 = %s\n", count($categories), $categories['content:v1:30100'] ?? '?');

step('Webhook subscription (created, listed, deleted)');
$subscription = $boxtal->subscribe(EventType::TrackingChanged, 'https://example.com/boxtal-webhook-sandbox', bin2hex(random_bytes(16)));
printf("  %s %s %s\n", $subscription->id, $subscription->eventType?->value, $subscription->status);
printf("  %d subscription(s)\n", count($boxtal->subscriptions()));
$boxtal->unsubscribe($subscription->id);
printf("  after deletion: %d subscription(s)\n", count($boxtal->subscriptions()));
