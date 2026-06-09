<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Notifications;

use ChrisJohnLeah\VelocityFleet\Data\Device;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A vehicle's reported speed exceeded the configured threshold.
 *
 * This is a HEURISTIC alert derived from the live-map poll cadence — the
 * reported speed is the instantaneous value at the moment the fleet was polled,
 * not a continuous measurement. Copy is labelled as approximate accordingly.
 */
final class SpeedingNotification extends Notification
{
    use Concerns\ResolvesNotificationChannels;

    public function __construct(
        public readonly string $customerId,
        public readonly Device $device,
        public readonly int $thresholdMph,
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
        $speed = $this->device->speed ?? 0;
        $unit = $this->speedUnit();

        return (new MailMessage())
            ->subject("Speeding (heuristic): {$vehicle}")
            ->line("{$vehicle} was reported at {$speed} {$unit}, above the {$this->thresholdMph} mph threshold.")
            ->line('This is an approximate, heuristic alert based on the latest poll — treat the speed as indicative.')
            ->line($this->whenLine());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'speeding',
            'heuristic' => true,
            'customer_id' => $this->customerId,
            'device_id' => $this->device->id,
            'vehicle_registration' => $this->device->vehicleRegistration,
            'driver_name' => $this->device->driverName,
            'speed' => $this->device->speed,
            'speed_measure_text' => $this->device->speedMeasureText,
            'threshold_mph' => $this->thresholdMph,
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

    private function speedUnit(): string
    {
        $unit = $this->device->speedMeasureText;

        return $unit !== null && $unit !== '' ? $unit : 'mph';
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
