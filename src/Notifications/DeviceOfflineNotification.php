<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Notifications;

use ChrisJohnLeah\VelocityFleet\Data\Device;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A device stopped reporting fresh positions and is now considered offline /
 * stale.
 */
final class DeviceOfflineNotification extends Notification
{
    use Concerns\ResolvesNotificationChannels;

    public function __construct(
        public readonly string $customerId,
        public readonly Device $device,
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

        return (new MailMessage())
            ->subject("Device offline: {$vehicle}")
            ->line("{$vehicle} has stopped reporting and appears to be offline.")
            ->line($this->lastSeenLine());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'device_offline',
            'customer_id' => $this->customerId,
            'device_id' => $this->device->id,
            'vehicle_registration' => $this->device->vehicleRegistration,
            'driver_name' => $this->device->driverName,
            'last_seen_at' => $this->lastSeenIso(),
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

    private function lastSeenLine(): string
    {
        $iso = $this->lastSeenIso();

        return $iso === null ? 'Last reported time is unknown.' : "Last reported at: {$iso}.";
    }

    private function lastSeenIso(): ?string
    {
        return $this->device->occurredAt()?->format(DATE_ATOM);
    }
}
