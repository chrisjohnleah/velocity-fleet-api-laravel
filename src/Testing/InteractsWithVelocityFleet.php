<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Testing;

use ChrisJohnLeah\VelocityFleet\Laravel\Events\DeviceEvent;
use ChrisJohnLeah\VelocityFleet\Laravel\FleetManager;
use ChrisJohnLeah\VelocityFleet\Requests\DevicePositions\GetDevicePositions;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Assert;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

/**
 * Drop-in test-case helper for host apps: stand up a faked fleet, then assert
 * what was polled and which per-device events fired — without touching the
 * network or the saloonphp/laravel plugin.
 *
 * It leans only on the core Saloon {@see MockClient} (returned and retained from
 * {@see FleetManager::fake()}) and Laravel's {@see Event} fake, so it stays
 * dependency-light and works in any testbench-backed suite.
 */
trait InteractsWithVelocityFleet
{
    private ?MockClient $velocityFleetMock = null;

    /**
     * Fake the live-positions poll. Pass either a single positions body (replayed
     * for every poll) or an ordered list of bodies (one consumed per poll, to
     * drive transitions). Bodies are raw arrays as produced by
     * {@see FakeDevicePositions::body()} or {@see FleetScenario}.
     *
     * @param  array<string, mixed>|list<array<string, mixed>>  $bodies
     */
    public function fakeFleet(array $bodies = []): MockClient
    {
        $frames = $this->normaliseFleetBodies($bodies);

        if ($frames === []) {
            $this->velocityFleetMock = FleetManager::fake([
                GetDevicePositions::class => MockResponse::make(FakeDevicePositions::body()),
            ]);

            return $this->velocityFleetMock;
        }

        if (count($frames) === 1) {
            // A single frame: key it by the request class so it answers every poll.
            $this->velocityFleetMock = FleetManager::fake([
                GetDevicePositions::class => MockResponse::make($frames[0]),
            ]);

            return $this->velocityFleetMock;
        }

        // Multiple frames: an integer-keyed sequence Saloon pops one-per-poll,
        // so successive polls see successive snapshots. FleetManager::fake() only
        // accepts class-keyed responses, so we register the bare sequence on the
        // same global mock it would create.
        $responses = array_map(
            static fn (array $body): MockResponse => MockResponse::make($body),
            $frames,
        );

        $this->velocityFleetMock = MockClient::global($responses);

        return $this->velocityFleetMock;
    }

    /**
     * Assert the live-positions endpoint was polled for the given customer at
     * least once. Matches on the {@see GetDevicePositions} request and its
     * `customer` query parameter.
     */
    public function assertPolled(string $customerId): void
    {
        $mock = $this->velocityFleetMock;

        Assert::assertNotNull(
            $mock,
            'No Velocity Fleet fake is active — call fakeFleet() before asserting a poll.',
        );

        $mock->assertSent(static function (Request $request) use ($customerId): bool {
            if (! $request instanceof GetDevicePositions) {
                return false;
            }

            return $request->query()->get('customer') === $customerId;
        });
    }

    /**
     * Assert a per-device event of the given class was dispatched for the device
     * with the given id. The event must be a {@see DeviceEvent} subclass so its
     * `$device->id` can be inspected. Requires {@see Event::fake()} to be active.
     *
     * @param  class-string<DeviceEvent>  $event
     */
    public function assertEventDispatchedForDevice(string $event, int $deviceId): void
    {
        Event::assertDispatched($event, static function (DeviceEvent $dispatched) use ($deviceId): bool {
            return $dispatched->device->id === $deviceId;
        });
    }

    /**
     * Normalise either a single body or a list of bodies into a list of frames.
     *
     * @param  array<string, mixed>|list<array<string, mixed>>  $bodies
     * @return list<array<string, mixed>>
     */
    private function normaliseFleetBodies(array $bodies): array
    {
        if ($bodies === []) {
            return [];
        }

        // A list of frames is a 0-indexed array whose first element is itself an
        // array (a positions body); anything else is treated as a single body.
        $first = $bodies[0] ?? null;

        if (array_key_exists(0, $bodies) && is_array($first)) {
            $frames = [];

            foreach ($bodies as $body) {
                if (is_array($body)) {
                    $frames[] = $this->toStringKeyedArray($body);
                }
            }

            return $frames;
        }

        return [$this->toStringKeyedArray($bodies)];
    }

    /**
     * Coerce an arbitrary array into a string-keyed map, so larastan sees a
     * concrete array<string, mixed> without an inline @var hint.
     *
     * @param  array<int|string, mixed>  $value
     * @return array<string, mixed>
     */
    private function toStringKeyedArray(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }
}
