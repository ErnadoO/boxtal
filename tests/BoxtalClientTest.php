<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal\Tests;

use Ernadoo\Boxtal\BoxtalClient;
use Ernadoo\Boxtal\Exception\ApiException;
use Ernadoo\Boxtal\Exception\TransportException;
use Ernadoo\Boxtal\ParcelPoint\Network;
use Ernadoo\Boxtal\ParcelPoint\Operation;
use Ernadoo\Boxtal\Quote\Offer;
use Ernadoo\Boxtal\Quote\QuoteRequest;
use Ernadoo\Boxtal\Shipping\Address;
use Ernadoo\Boxtal\Shipping\Package;
use Ernadoo\Boxtal\Shipping\ShippingOrderRequest;
use Ernadoo\Boxtal\Shipping\ShippingOrderStatus;
use Ernadoo\Boxtal\Tests\Support\FakeHttpClient;
use Ernadoo\Boxtal\Tracking\TrackingStatus;
use Ernadoo\Boxtal\Webhook\EventType;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/**
 * Fixtures are real responses of the Boxtal test environment (2026-10-10).
 */
final class BoxtalClientTest extends TestCase
{
    private FakeHttpClient $http;
    private BoxtalClient $boxtal;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
        $factory = new Psr17Factory();
        $this->boxtal = new BoxtalClient($this->http, $factory, $factory, 'access', 'secret', sandbox: true);
    }

    public function testAQuoteListsTheOffersWithTheirPricesAndDates(): void
    {
        $this->http->queueFixture('quote.xml');

        $offers = $this->boxtal->quote(new QuoteRequest('75009', 'Paris', '69002', 'Lyon', 0.5, 20, 15, 8));

        $this->assertCount(24, $offers);
        $shop2shop = $this->offer($offers, 'CHRP-ChronoShoptoShop');
        $this->assertSame(3.75, $shop2shop->priceExclTax);
        $this->assertSame(4.5, $shop2shop->priceInclTax);
        $this->assertTrue($shop2shop->deliversToPickupPoint());
        $this->assertSame(Offer::COLLECTION_DROPOFF_POINT, $shop2shop->collectionType);
        $this->assertSame(3, $shop2shop->transitDays());
        $this->assertTrue($this->offer($offers, 'POFR-ColissimoAccess')->deliversHome());

        $request = $this->http->lastRequest();
        $this->assertSame('test.envoimoinscher.com', $request->getUri()->getHost());
        $this->assertSame('Basic '.base64_encode('access:secret'), $request->getHeaderLine('Authorization'));
        $this->assertSame('application/xml', $request->getHeaderLine('Accept'));
        parse_str($request->getUri()->getQuery(), $query);
        $this->assertSame('0.5', $query['colis_1_poids'] ?? $query['colis_1.poids'] ?? null);
    }

    public function testAQuoteCanBeRestrictedToSomeOffers(): void
    {
        $this->http->queueFixture('quote.xml');

        $offers = $this->boxtal->quote(new QuoteRequest('75009', 'Paris', '69002', 'Lyon', 0.5, 20, 15, 8, offerCodes: ['MONR-CpourToi', 'CHRP-ChronoShoptoShop']));

        $this->assertSame(['CHRP-ChronoShoptoShop', 'MONR-CpourToi'], array_map(static fn (Offer $o): string => $o->code(), $offers));
        $this->assertStringContainsString('MONRCpourToi', urldecode($this->http->lastRequest()->getUri()->getQuery()));
    }

    public function testAShippingOrderIsCreated(): void
    {
        $this->http->queueFixture('order-create.json');

        $order = $this->boxtal->createShippingOrder($this->order());

        $this->assertSame('2610101341MONR51M2FR', $order->id);
        $this->assertSame(ShippingOrderStatus::Pending, $order->status);
        $this->assertSame(3.84, $order->priceExclTax);
        $this->assertSame('2026-10-16', $order->estimatedDeliveryDate?->format('Y-m-d'));

        $request = $this->http->lastRequest();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.boxtal.build/shipping/v3.1/shipping-order', (string) $request->getUri());
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('MONR-CpourToi', $body['shippingOfferCode']);
        $this->assertSame('40246', $body['shipment']['pickupPointCode']);
        $this->assertSame('content:v1:30100', $body['shipment']['packages'][0]['content']['id']);
        $this->assertSame('RESIDENTIAL', $body['shipment']['toAddress']['type']);
    }

    public function testAValidationErrorBecomesAnApiException(): void
    {
        $this->http->queueFixture('order-error-missing-point.json', 422);

        try {
            $this->boxtal->createShippingOrder($this->order());
            $this->fail('An ApiException was expected.');
        } catch (ApiException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertTrue($e->hasErrorCode('RequestValidationException'));
            $this->assertSame('shipment.pickupPointCode', $e->getErrors()[0]['field']);
            $this->assertStringContainsString('missing value', $e->getMessage());
        }
    }

    public function testTheLabelIsReadOnceAvailable(): void
    {
        $this->http->queue(422, '{"status":422,"errors":[{"code":"UnexpectedException","parameters":[]}]}');
        $this->http->queueFixture('documents.json');

        // Not available yet (422 UnexpectedException), then available
        $notYet = $this->boxtal->shippingDocuments('2610101341MONR51M2FR');
        $this->assertSame([], $notYet);
        $documents = $this->boxtal->shippingDocuments('2610101341MONR51M2FR');
        $this->assertCount(1, $documents);
        $this->assertTrue($documents[0]->isLabel());
        $this->assertStringStartsWith('https://document.boxtal.build/', $documents[0]->url);
    }

    public function testAnUnknownOrderIsAnError(): void
    {
        $this->http->queueFixture('documents-none.json', 422);

        $this->expectException(ApiException::class);
        $this->boxtal->shippingDocuments('INCONNU123');
    }

    public function testTheTrackingHasItsHistory(): void
    {
        $this->http->queueFixture('tracking.json');

        $tracking = $this->boxtal->tracking('2610101141POFR51LIFR')[0];

        $this->assertTrue($tracking->isDelivered());
        $this->assertTrue($tracking->final);
        $this->assertTrue($tracking->isHandedOver());
        $this->assertSame('CR261010000000000Q5Y', $tracking->trackingNumber);
        $this->assertStringStartsWith('https://www.laposte.fr/', $tracking->trackingUrl);
        $this->assertCount(7, $tracking->history);
        $this->assertSame(TrackingStatus::Announced, $tracking->history[6]->status);
        $this->assertSame('Centre Courrier 34', $tracking->history[0]->location);
    }

    public function testAnOrderIsCancelled(): void
    {
        $this->http->queueFixture('order-cancel.json');

        $this->assertTrue($this->boxtal->cancelShippingOrder('2610101341MONR51M2FR')->isCancelled());
        $this->assertSame('DELETE', $this->http->lastRequest()->getMethod());
    }

    public function testParcelPointsAreSearchedByNetwork(): void
    {
        $this->http->queueFixture('points-MONR-CpourToi.json');

        $points = $this->boxtal->parcelPoints([Network::MONDIAL_RELAY, Network::CHRONOPOST_SHOP], '69002', 'Lyon');

        $this->assertSame('40246', $points[0]->code);
        $this->assertTrue($points[0]->belongsTo(Network::MONDIAL_RELAY));
        $this->assertTrue($points[0]->isLocker());
        $this->assertSame(98, $points[0]->distanceMeters);
        $this->assertNotNull($points[0]->latitude);
        $query = urldecode($this->http->lastRequest()->getUri()->getQuery());
        $this->assertStringContainsString('searchNetworks=MONR_NETWORK', $query);
        $this->assertStringContainsString('searchNetworks=CHRP_SHOP_ONLY_NETWORK', $query);
    }

    public function testParcelPointsAreSearchedForAnOffer(): void
    {
        $this->http->queueFixture('points-CHRP-ChronoShoptoShop.json');

        $points = $this->boxtal->parcelPointsForOffer('CHRP-ChronoShoptoShop', '69002', 'Lyon', Operation::Arrival);

        $this->assertSame('7796W', $points[0]->code);
        $this->assertNotSame([], $points[0]->openingHours);
        $this->assertStringContainsString('operationType=ARRIVAL', (string) $this->http->lastRequest()->getUri());
    }

    public function testUnknownOpeningHoursAreNotClosedDays(): void
    {
        // Colissimo gives null for every day: hours unknown; Chronopost gives [] for a closed day
        $this->http->queueFixture('points-POFR-ColissimoPickupStation.json');
        $colissimo = $this->boxtal->parcelPoints([Network::COLISSIMO], '69002', 'Lyon');
        $this->http->queueFixture('points-CHRP-ChronoShoptoShop.json');
        $chronopost = $this->boxtal->parcelPoints([Network::CHRONOPOST_SHOP], '69002', 'Lyon');

        $this->assertNull($colissimo[0]->openingHours);
        $this->assertFalse($colissimo[0]->hasOpeningHours());
        $this->assertSame('339DL', $chronopost[1]->code);
        $this->assertTrue($chronopost[1]->hasOpeningHours());
        $this->assertArrayNotHasKey('MONDAY', (array) $chronopost[1]->openingHours);
    }

    public function testContentCategoriesAreListedById(): void
    {
        $this->http->queueFixture('content-categories.json');

        $this->assertSame('Cosmétiques, bien-être', $this->boxtal->contentCategories()['content:v1:30100'] ?? null);
    }

    public function testASubscriptionSendsTheChosenSecret(): void
    {
        $this->http->queue(200, '{"status":200,"content":{"id":4622,"eventType":"TRACKING_CHANGED","callbackUrl":"https://example.com/hook","webhookSecret":null,"status":"ACTIVE"}}');

        $subscription = $this->boxtal->subscribe(EventType::TrackingChanged, 'https://example.com/hook', 's3cr3t');

        $this->assertSame('4622', $subscription->id);
        $this->assertSame(EventType::TrackingChanged, $subscription->eventType);
        $this->assertSame('s3cr3t', json_decode((string) $this->http->lastRequest()->getBody(), true)['webhookSecret']);
    }

    public function testAServerErrorIsATransportException(): void
    {
        $this->http->queue(503, 'Service Unavailable', 'text/plain');

        $this->expectException(TransportException::class);
        $this->boxtal->getShippingOrder('2610101341MONR51M2FR');
    }

    /**
     * @param list<Offer> $offers
     */
    private function offer(array $offers, string $code): Offer
    {
        foreach ($offers as $offer) {
            if ($offer->code() === $code) {
                return $offer;
            }
        }
        $this->fail('Offer not found: '.$code);
    }

    private function order(): ShippingOrderRequest
    {
        return new ShippingOrderRequest(
            'MONR-CpourToi',
            new Address('Alice', 'Essai', 'alice.essai@example.com', '+33612345678', 'boulevard des Capucines', '75009', 'Paris', number: '4'),
            new Address('Béa', 'Essai', 'bea.essai@example.com', '+33698765432', 'rue de la République', '69002', 'Lyon', number: '20'),
            [new Package(0.5, 20, 15, 8, 15.0, 'Cosmétiques')],
            pickupPointCode: '40246',
            externalId: 'troc-42',
        );
    }
}
