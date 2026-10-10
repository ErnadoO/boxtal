<?php

declare(strict_types=1);

namespace Ernadoo\Boxtal;

use Ernadoo\Boxtal\Exception\ApiException;
use Ernadoo\Boxtal\Exception\BoxtalException;
use Ernadoo\Boxtal\Exception\TransportException;
use Ernadoo\Boxtal\ParcelPoint\Operation;
use Ernadoo\Boxtal\ParcelPoint\ParcelPoint;
use Ernadoo\Boxtal\Quote\Offer;
use Ernadoo\Boxtal\Quote\QuoteRequest;
use Ernadoo\Boxtal\Shipping\ShippingDocument;
use Ernadoo\Boxtal\Shipping\ShippingOrder;
use Ernadoo\Boxtal\Shipping\ShippingOrderRequest;
use Ernadoo\Boxtal\Tracking\PackageTracking;
use Ernadoo\Boxtal\Webhook\EventType;
use Ernadoo\Boxtal\Webhook\Subscription;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Boxtal API client.
 *
 * - Quotes come from the v1 API (XML, GET /cotation): the v3 API has no quote yet.
 * - Everything else uses the v3 API (JSON): shipping orders, documents, tracking, parcel points, webhooks.
 *
 * Both APIs accept the access key and secret key of a v3 application in basic auth. Test environment (no billing,
 * dummy tracking events every few minutes): pass $sandbox = true and the keys of a test account.
 *
 * Accepts any PSR-18 HTTP client and PSR-17 factories. With symfony/http-client, Psr18Client implements all three:
 *
 *   $psr18 = new \Symfony\Component\HttpClient\Psr18Client();
 *   $boxtal = new BoxtalClient($psr18, $psr18, $psr18, $accessKey, $secretKey, sandbox: true);
 */
final class BoxtalClient implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const V3_URL = 'https://api.boxtal.com/shipping';
    private const V3_SANDBOX_URL = 'https://api.boxtal.build/shipping';
    private const V1_URL = 'https://www.envoimoinscher.com/api/v1';
    private const V1_SANDBOX_URL = 'https://test.envoimoinscher.com/api/v1';

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $accessKey,
        #[\SensitiveParameter]
        private readonly string $secretKey,
        private readonly bool $sandbox = false,
    ) {
    }

    /**
     * Offers and prices for one parcel (v1 API). Prices include and exclude tax.
     *
     * @return list<Offer>
     *
     * @throws BoxtalException
     */
    public function quote(QuoteRequest $request): array
    {
        $url = ($this->sandbox ? self::V1_SANDBOX_URL : self::V1_URL).'/cotation?'.http_build_query($request->toQuery(), '', '&', \PHP_QUERY_RFC3986);
        $response = $this->send('GET', $url, null, 'application/xml');
        $body = (string) $response->getBody();

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body);
        } finally {
            libxml_use_internal_errors($previous);
        }
        if (false === $xml) {
            throw new TransportException(sprintf('Invalid quote response from Boxtal (HTTP %d).', $response->getStatusCode()));
        }
        if ($response->getStatusCode() >= 400 || 'error' === $xml->getName()) {
            $code = (string) ($xml->code ?? 'UNKNOWN');
            $message = (string) ($xml->message ?? '');
            throw new ApiException(sprintf('Boxtal API error (HTTP %d): %s%s', $response->getStatusCode(), $code, '' !== $message ? ': '.$message : ''),
                $response->getStatusCode(), [['code' => $code, 'field' => null, 'message' => '' !== $message ? $message : null]]);
        }

        $offers = [];
        foreach ($xml->shipment->offer ?? [] as $offer) {
            $offers[] = Offer::fromXml($offer);
        }
        // With "offers[N]", Boxtal still adds some offers that were not asked for (e.g. CHRP-Chrono2ShopDirect)
        if ([] !== $request->offerCodes) {
            $offers = array_values(array_filter($offers, static fn (Offer $o): bool => \in_array($o->code(), $request->offerCodes, true)));
        }
        $this->logger()->info('Boxtal quote: {offers} offer(s).', ['offers' => \count($offers), 'weight_kg' => $request->weightKg]);

        return $offers;
    }

    /**
     * Orders a shipment: Boxtal sends it to the carrier, then the label becomes available (shippingDocuments(), or
     * the DOCUMENT_CREATED webhook). Billed to the Boxtal account (deferred payment by direct debit is required in
     * production).
     *
     * @throws BoxtalException
     */
    public function createShippingOrder(ShippingOrderRequest $request): ShippingOrder
    {
        $order = ShippingOrder::fromArray($this->json('POST', '/v3.1/shipping-order', $request->toArray()));
        $this->logger()->info('Boxtal shipping order {id} created ({offer}).', ['id' => $order->id, 'offer' => $request->offerCode, 'external_id' => $request->externalId]);

        return $order;
    }

    /**
     * @throws BoxtalException
     */
    public function getShippingOrder(string $id): ShippingOrder
    {
        return ShippingOrder::fromArray($this->json('GET', '/v3.1/shipping-order/'.rawurlencode($id)));
    }

    /**
     * Cancels a shipping order that was not dropped off yet. Boxtal refunds it (credit note on the next invoice with
     * deferred payment). Unused labels are also cancelled by Boxtal 30 days after their creation.
     *
     * @throws BoxtalException
     */
    public function cancelShippingOrder(string $id): ShippingOrder
    {
        $order = ShippingOrder::fromArray($this->json('DELETE', '/v3.1/shipping-order/'.rawurlencode($id)));
        $this->logger()->info('Boxtal shipping order {id} cancelled.', ['id' => $id]);

        return $order;
    }

    /**
     * Documents of a shipping order (the label first of all); empty while they are not available yet. Right after
     * the order, while it is still PENDING, Boxtal answers "UnexpectedException" (HTTP 422) instead of
     * "NoShippingDocumentException": both mean "not yet".
     *
     * @return list<ShippingDocument>
     *
     * @throws BoxtalException
     */
    public function shippingDocuments(string $shippingOrderId): array
    {
        try {
            $content = $this->json('GET', '/v3.1/shipping-order/'.rawurlencode($shippingOrderId).'/shipping-document');
        } catch (ApiException $e) {
            if ($e->hasErrorCode('NoShippingDocumentException') || $e->hasErrorCode('UnexpectedException')) {
                return [];
            }
            throw $e;
        }

        return array_values(array_map(static fn (array $d): ShippingDocument => ShippingDocument::fromArray($d), array_filter($content, 'is_array')));
    }

    /**
     * Tracking of the packages of a shipping order; empty while it is not available yet (see shippingDocuments()
     * for "UnexpectedException").
     *
     * @return list<PackageTracking>
     *
     * @throws BoxtalException
     */
    public function tracking(string $shippingOrderId): array
    {
        try {
            $content = $this->json('GET', '/v3.1/shipping-order/'.rawurlencode($shippingOrderId).'/tracking');
        } catch (ApiException $e) {
            if ($e->hasErrorCode('NoPackageTrackingFoundException') || $e->hasErrorCode('UnexpectedException')) {
                return [];
            }
            throw $e;
        }

        return array_values(array_map(static fn (array $t): PackageTracking => PackageTracking::fromArray($t), array_filter($content, 'is_array')));
    }

    /**
     * Parcel points of one or several networks (Network::MONDIAL_RELAY…) near a place, nearest first. Each point
     * lists all its networks.
     *
     * @param list<string> $networks
     *
     * @return list<ParcelPoint>
     *
     * @throws BoxtalException
     */
    public function parcelPoints(array $networks, string $postalCode, string $city, string $countryIsoCode = 'FR', ?string $street = null): array
    {
        $query = array_filter(['postalCode' => $postalCode, 'city' => $city, 'countryIsoCode' => $countryIsoCode, 'street' => $street], static fn (?string $v): bool => null !== $v && '' !== $v);
        $path = '/v3.2/parcel-point-by-network?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
        foreach ($networks as $network) {
            $path .= '&searchNetworks='.rawurlencode($network);
        }

        return $this->points($path);
    }

    /**
     * Parcel points usable with one offer, near a place: where the recipient can pick the parcel up (Arrival), or
     * where the sender can drop it off (Departure). Use it to check that a point accepts an offer before ordering.
     *
     * @return list<ParcelPoint>
     *
     * @throws BoxtalException
     */
    public function parcelPointsForOffer(string $offerCode, string $postalCode, string $city, Operation $operation = Operation::Arrival, string $countryIsoCode = 'FR', ?string $street = null): array
    {
        $query = array_filter([
            'shippingOfferCode' => $offerCode, 'operationType' => $operation->value,
            'postalCode' => $postalCode, 'city' => $city, 'countryIsoCode' => $countryIsoCode, 'street' => $street,
        ], static fn (?string $v): bool => null !== $v && '' !== $v);

        return $this->points('/v3.2/parcel-point-by-shipping-offer?'.http_build_query($query, '', '&', \PHP_QUERY_RFC3986));
    }

    /**
     * Content categories, by id ("content:v1:30100" => "Cosmétiques, bien-être").
     *
     * @return array<string, string>
     *
     * @throws BoxtalException
     */
    public function contentCategories(): array
    {
        $categories = [];
        foreach ($this->json('GET', '/v3.1/content-category') as $category) {
            if (\is_array($category) && isset($category['id'])) {
                $categories[(string) $category['id']] = (string) ($category['label'] ?? '');
            }
        }

        return $categories;
    }

    /**
     * Subscribes a URL (https) to an event type. You choose the webhook secret: Boxtal signs each request with it
     * (header x-bxt-signature, see WebhookParser) and never returns it.
     *
     * @throws BoxtalException
     */
    public function subscribe(EventType $eventType, string $callbackUrl, #[\SensitiveParameter] string $webhookSecret): Subscription
    {
        if ('' === $webhookSecret) {
            throw new \InvalidArgumentException('A webhook secret is required to authenticate the requests sent by Boxtal.');
        }

        return Subscription::fromArray($this->json('POST', '/v3.1/subscription', ['eventType' => $eventType->value, 'callbackUrl' => $callbackUrl, 'webhookSecret' => $webhookSecret]));
    }

    /**
     * @return list<Subscription>
     *
     * @throws BoxtalException
     */
    public function subscriptions(): array
    {
        return array_values(array_map(static fn (array $s): Subscription => Subscription::fromArray($s), array_filter($this->json('GET', '/v3.1/subscription'), 'is_array')));
    }

    /**
     * @throws BoxtalException
     */
    public function unsubscribe(string $subscriptionId): void
    {
        $this->json('DELETE', '/v3.1/subscription/'.rawurlencode($subscriptionId));
    }

    /**
     * @return list<ParcelPoint>
     */
    private function points(string $path): array
    {
        return array_values(array_map(static fn (array $p): ParcelPoint => ParcelPoint::fromArray($p), array_filter($this->json('GET', $path), 'is_array')));
    }

    /**
     * Calls the v3 API and returns the "content" of the response.
     *
     * @param array<string, mixed>|null $body
     *
     * @return array<mixed>
     *
     * @throws BoxtalException
     */
    private function json(string $method, string $path, ?array $body = null): array
    {
        $url = ($this->sandbox ? self::V3_SANDBOX_URL : self::V3_URL).$path;
        $response = $this->send($method, $url, null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), 'application/json');
        $raw = (string) $response->getBody();

        try {
            $data = '' === $raw ? [] : json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TransportException(sprintf('Invalid response from Boxtal (HTTP %d): %s', $response->getStatusCode(), $e->getMessage()), 0, $e);
        }
        if (!\is_array($data)) {
            throw new TransportException(sprintf('Invalid response from Boxtal (HTTP %d).', $response->getStatusCode()));
        }
        if ($response->getStatusCode() >= 400) {
            $exception = ApiException::fromResponse($response->getStatusCode(), $data);
            // Never log bodies: they contain names, addresses and phone numbers
            $this->logger()->error('Boxtal rejected {method} {path}: {message}', ['method' => $method, 'path' => strtok($path, '?'), 'message' => $exception->getMessage()]);
            throw $exception;
        }

        $content = $data['content'] ?? [];

        return \is_array($content) ? $content : [];
    }

    private function send(string $method, string $url, ?string $body, string $accept): ResponseInterface
    {
        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Authorization', 'Basic '.base64_encode($this->accessKey.':'.$this->secretKey))
            ->withHeader('Accept', $accept);
        if (null !== $body) {
            $request = $request->withHeader('Content-Type', 'application/json')->withBody($this->streamFactory->createStream($body));
        }

        try {
            $response = $this->httpClient->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            $this->logger()->error('Boxtal unreachable: {error}', ['error' => $e->getMessage(), 'method' => $method]);
            throw new TransportException('Boxtal unreachable: '.$e->getMessage(), 0, $e);
        }
        if ($response->getStatusCode() >= 500) {
            throw new TransportException(sprintf('Boxtal server error (HTTP %d).', $response->getStatusCode()));
        }

        return $response;
    }

    private function logger(): LoggerInterface
    {
        return $this->logger ??= new NullLogger();
    }
}
