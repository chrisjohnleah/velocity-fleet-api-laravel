<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Notifications;

use ChrisJohnLeah\VelocityFleet\Data\Device;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A vehicle has been idling (ignition on, speed zero) beyond the configured
 * threshold.
 *
 * This is a HEURISTIC alert: idling is inferred across successive poll snapshots
 * (ignition on + zero speed persisting), not from a dedicated idling sensor, so
 * the duration is approximate. Copy is labelled accordingly.
 */
final class IdlingTooLongNotification extends Notification
{
    use Concerns\ResolvesNotificationChannels;

    public function __construct(
        public readonly string $customerId,
        public readonly Device $device,
        public readonly int $idlingMinutes,
        public readonly int $thresholdMinutes,
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
            ->subject("Idling too long (heuristic): {$vehicle}")
            ->line("{$vehicle} appears to have been idling for about {$this->idlingMinutes} minute(s), above the {$this->thresholdMinutes} minute threshold.")
            ->line('This is an approximate, heuristic alert inferred from successive position polls.')
            ->line($this->whenLine());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'idling_too_long',
            'heuristic' => true,
            'customer_id' => $this->customerId,
            'device_id' => $this->device->id,
            'vehicle_registration' => $this->device->vehicleRegistration,
            'driver_name' => $this->device->driverName,
            'idling_minutes' => $this->idlingMinutes,
            'threshold_minutes' => $this->thresholdMinutes,
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

    private function whenLine(): string
    {
        $iso = $this->occurredAtIso();

        return $iso === null ? 'Time was not reported.' : "Reported at: {$iso}.";
    }

    private function occurredAtIso(): ?string
    {
        return $this->device->occurredAt()?->format(DATE_ATOM);
    }
}
