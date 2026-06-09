<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel;

use ChrisJohnLeah\VelocityFleet\Auth\StoredToken;
use ChrisJohnLeah\VelocityFleet\Data\DevicePositions;
use ChrisJohnLeah\VelocityFleet\Laravel\Contracts\PositionsCache;
use ChrisJohnLeah\VelocityFleet\Laravel\Support\DeviceCollection;
use ChrisJohnLeah\VelocityFleet\Resources\CustomersResource;
use ChrisJohnLeah\VelocityFleet\Resources\DevicePositionsResource;
use ChrisJohnLeah\VelocityFleet\VelocityFleet;
use ChrisJohnLeah\VelocityFleet\VelocityFleetConnector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Http\Response;

/**
 * The primary bound service and facade target. Wraps the (final) core client,
 * delegating its API, and layers Laravel-side sugar on top: chainable device
 * queries ({@see fleet()}), the refresh-rate-aware cache ({@see cached()}), and a
 * test entrypoint ({@see fake()}).
 *
 * @mixin \ChrisJohnLeah\VelocityFleet\VelocityFleet
 */
final class FleetManager
{
    public function __construct(
        private readonly VelocityFleet $client,
        private readonly PositionsCache $cache,
    ) {
    }

    public function customers(): CustomersResource
    {
        return $this->client->customers();
    }

    public function devicePositions(): DevicePositionsResource
    {
        return $this->client->devicePositions();
    }

    public function send(Request $request): Response
    {
        return $this->client->send($request);
    }

    public function refresh(StoredToken $token): StoredToken
    {
        return $this->client->refresh($token);
    }

    public function connector(): VelocityFleetConnector
    {
        return $this->client->connector();
    }

    public function client(): VelocityFleet
    {
        return $this->client;
    }

    /**
     * Live devices for a customer as a chainable {@see DeviceCollection}, e.g.
     * `VelocityFleet::fleet($id)->moving()->inDriverGroup(7)`.
     */
    public function fleet(string $customerId): DeviceCollection
    {
        return new DeviceCollection($this->positions($customerId)->devices);
    }

    /**
     * The full live positions payload for a customer.
     */
    public function positions(string $customerId): DevicePositions
    {
        return $this->client->devicePositions()->forCustomer($customerId);
    }

    /**
     * The refresh-rate-aware cache (stale-while-revalidate + single-flight).
     */
    public function cached(): PositionsCache
    {
        return $this->cache;
    }

    /**
     * Cached devices for a customer as a chainable {@see DeviceCollection}.
     */
    public function cachedFleet(string $customerId): DeviceCollection
    {
        return new DeviceCollection($this->cache->positions($customerId)->devices);
    }

    /**
     * Begin a test fake: registers a global Saloon mock so the underlying client
     * returns canned responses without hitting the network.
     *
     * @param  array<string, MockResponse>  $responses
     */
    public static function fake(array $responses = []): MockClient
    {
        return MockClient::global($responses);
    }
}
