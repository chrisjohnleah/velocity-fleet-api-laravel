<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Notifications;

use ChrisJohnLeah\VelocityFleet\Data\Device;
use ChrisJohnLeah\VelocityFleet\Laravel\Models\Geofence;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A vehicle entered (arrived at) a geofence. The {@see Geofence} is supplied by
 * the geofences module and referenced here by FQN; it may be null when the
 * breach context is unknown.
 */
final class VehicleArrivedNotification extends Notification
{
    use Concerns\ResolvesNotificationChannels;

    public function __construct(
        public readonly string $customerId,
        public readonly Device $device,
        public readonly ?Geofence $geofence = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->configuredChannels();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $vehicle = $this->vehicleLabel();
        $place = $this->geofenceLabel();

        return (new MailMessage())
            ->subject("Vehicle arrived: {$vehicle}")
            ->line("{$vehicle} has arrived at {$place}.")
            ->line($this->whenLine());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'vehicle_arrived',
            'customer_id' => $this->customerId,
            'device_id' => $this->device->id,
            'vehicle_registration' => $this->device->vehicleRegistration,
            'driver_name' => $this->device->driverName,
            'geofence' => $this->geofenceArray(),
            'lat' => $this->device->lat,
            'lon' => $this->device->lon,
            'occurred_at' => $this->occurredAtIso(),
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    private function vehicleLabel(): string
    {
        return $this->device->vehicleRegistration
            ?? $this->device->driverName
            ?? 'Device '.((string) ($this->device->id ?? 'unknown'));
    }

    private function geofenceLabel(): string
    {
        $name = $this->geofence?->name;

        return $name !== null && $name !== '' ? $name : 'a geofence';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function geofenceArray(): ?array
    {
        if ($this->geofence === null) {
            return null;
        }

        return [
            'id' => $this->geofence->id,
            'name' => $this->geofence->name,
        ];
    }

    private function whenLine(): string
    {
        $iso = $this->occurredAtIso();

        return $iso === null ? 'Arrival time was not reported.' : "Arrival time: {$iso}.";
    }

    private function occurredAtIso(): ?string
    {
        return $this->device->occurredAt()?->format(DATE_ATOM);
    }
}
