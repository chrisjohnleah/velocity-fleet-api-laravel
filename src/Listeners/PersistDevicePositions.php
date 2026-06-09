<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Listeners;

use Carbon\CarbonImmutable;
use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Laravel\Events\DevicePositionsUpdated;
use ChrisJohnLeah\VelocityFleet\Laravel\Jobs\IngestPositionsJob;
use Illuminate\Contracts\Encryption\Encrypter;

/**
 * Opt-in history sink. When `velocity-fleet.history.enabled` is set, maps the
 * poll's devices into flat rows and hands them to {@see IngestPositionsJob} for an
 * idempotent upsert. Private devices are suppressed by default, and PII columns are
 * pre-encrypted here (the {@see IngestPositionsJob} upsert bypasses Eloquent casts,
 * so encryption cannot be deferred to the model).
 */
final class PersistDevicePositions
{
    public function __construct(
        private readonly Encrypter $encrypter,
    ) {
    }

    public function handle(DevicePositionsUpdated $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $encryptPii = $this->encryptPii();
        $storeRaw = $this->storeRaw();

        $rows = [];

        foreach ($event->positions->devices as $device) {
            $row = $this->mapDevice($event->customerId, $device, $encryptPii, $storeRaw, $event->occurredAt);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        if ($rows === []) {
            return;
        }

        IngestPositionsJob::dispatch($event->customerId, $rows);
    }

    /**
     * @return array<string, scalar|null>|null
     */
    private function mapDevice(string $customerId, Device $device, bool $encryptPii, bool $storeRaw, CarbonImmutable $polledAt): ?array
    {
        // Suppress private devices from the history store by default.
        if ($device->private === true) {
            return null;
        }

        if ($device->id === null) {
            return null;
        }

        // Fall back to the poll time (deterministic per poll, carried on the
        // event) rather than now() at listener-execution time, so a queued or
        // retried listener produces the same idempotency key.
        $occurredAt = $device->occurredAt();
        $recordedAt = $occurredAt !== null
            ? CarbonImmutable::instance($occurredAt)->toDateTimeString()
            : $polledAt->toDateTimeString();

        return [
            'customer_id' => $customerId,
            'device_id' => (string) $device->id,
            'service_id' => $device->serviceId,
            'recorded_at' => $recordedAt,
            'lat' => $device->lat,
            'lon' => $device->lon,
            'speed' => $device->speed,
            'direction' => $device->direction,
            'ignition' => $device->ignition,
            'driver_name' => $this->pii($device->driverName, $encryptPii),
            'vehicle_registration' => $this->pii($device->vehicleRegistration, $encryptPii),
            'mobile_phone' => $this->pii($device->mobilePhone, $encryptPii),
            'signal_strength_color' => $device->signalStrengthColor,
            'raw' => $storeRaw ? $this->encodeRaw($device) : null,
        ];
    }

    private function pii(?string $value, bool $encryptPii): ?string
    {
        if ($value === null || ! $encryptPii) {
            return $value;
        }

        // Match Laravel's `encrypted` cast exactly: encrypt WITHOUT serialization
        // (the cast decrypts with decrypt($value, false)), so the model reads cleanly.
        return $this->encrypter->encrypt($value, false);
    }

    private function encodeRaw(Device $device): string
    {
        return json_encode($device, JSON_THROW_ON_ERROR);
    }

    private function enabled(): bool
    {
        $enabled = config('velocity-fleet.history.enabled');

        return is_bool($enabled) && $enabled;
    }

    private function encryptPii(): bool
    {
        $encrypt = config('velocity-fleet.history.encrypt_pii');

        // Default to encrypting when unset/non-bool (privacy-by-default).
        return ! is_bool($encrypt) || $encrypt;
    }

    private function storeRaw(): bool
    {
        $storeRaw = config('velocity-fleet.history.store_raw');

        return is_bool($storeRaw) && $storeRaw;
    }
}
