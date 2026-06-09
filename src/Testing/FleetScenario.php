<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Testing;

use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Data\DevicePositions;
use OutOfRangeException;
use Saloon\Http\Faking\MockResponse;

/**
 * Stages an ordered sequence of position bodies so a test can drive change
 * detection across successive polls: e.g. frame 0 has a vehicle stopped, frame 1
 * has it moving, so the poller emits a {@see \ChrisJohnLeah\VelocityFleet\Laravel\Events\VehicleStartedMoving}.
 *
 * Each {@see next()} advances an internal cursor and returns the next raw body;
 * {@see toMockResponses()} turns the whole sequence into the
 * `[$requestClass => MockResponse|MockResponse[]]` map {@see \ChrisJohnLeah\VelocityFleet\Laravel\FleetManager::fake()}
 * consumes, replaying one frame per request.
 */
final class FleetScenario
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $frames = [];

    private int $cursor = 0;

    /**
     * Append a frame from a ready-made positions body.
     *
     * @param  array<string, mixed>  $body
     */
    public function push(array $body): self
    {
        $this->frames[] = $body;

        return $this;
    }

    /**
     * Append a frame built from a set of devices (DTOs or attribute arrays),
     * baking the positions body via {@see FakeDevicePositions::body()}.
     *
     * @param  array<int, Device|array<string, mixed>>  $devices
     * @param  array<string, mixed>  $overrides
     */
    public function pushDevices(array $devices, array $overrides = []): self
    {
        return $this->push(FakeDevicePositions::body($devices, $overrides));
    }

    /**
     * Append a frame from a hydrated {@see DevicePositions} DTO.
     */
    public function pushPositions(DevicePositions $positions): self
    {
        return $this->push(FakeDevicePositions::body($positions->devices));
    }

    /**
     * The next frame's raw body, advancing the cursor. Throws once the staged
     * frames are exhausted so an over-run is a loud test failure, not a silent
     * empty payload.
     *
     * @return array<string, mixed>
     */
    public function next(): array
    {
        if (! array_key_exists($this->cursor, $this->frames)) {
            throw new OutOfRangeException(
                "FleetScenario has no frame at index {$this->cursor}; staged ".count($this->frames).'.',
            );
        }

        return $this->frames[$this->cursor++];
    }

    /**
     * Rewind the cursor back to the first frame.
     */
    public function rewind(): self
    {
        $this->cursor = 0;

        return $this;
    }

    public function count(): int
    {
        return count($this->frames);
    }

    /**
     * All staged frames as a flat, ordered {@see MockResponse} sequence for
     * {@see \ChrisJohnLeah\VelocityFleet\Laravel\FleetManager::fake()}. Saloon
     * stores one response per request-class key, so to replay frame-by-frame
     * across successive polls the responses MUST be an integer-keyed sequence
     * (it pops the next one off the queue per request) rather than keyed by
     * class. The $requestClass is accepted for symmetry/intent — every frame in
     * a scenario answers that same poll request.
     *
     * @param  class-string<\Saloon\Http\Request>  $requestClass
     * @return list<MockResponse>
     */
    public function toMockResponses(string $requestClass): array
    {
        unset($requestClass);

        return array_map(
            static fn (array $body): MockResponse => MockResponse::make($body),
            $this->frames,
        );
    }
}
