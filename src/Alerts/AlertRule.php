<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Alerts;

use Illuminate\Notifications\Notification;

/**
 * A small value object describing a single alert mapping: a stable rule key (used
 * for cache-based throttling/dedupe) and the {@see Notification} class it sends.
 *
 * The throttle key is composed from the rule key, the owning customer and the
 * specific device so that distinct devices alert independently, but a repeat of
 * the same rule for the same device inside the throttle window is suppressed.
 */
final readonly class AlertRule
{
    /**
     * @param  class-string<Notification>  $notification
     */
    public function __construct(
        public string $key,
        public string $notification,
    ) {
    }

    /**
     * @param  class-string<Notification>  $notification
     */
    public static function make(string $key, string $notification): self
    {
        return new self($key, $notification);
    }

    /**
     * The cache key for throttling this rule for a specific customer + device.
     */
    public function throttleKey(string $customerId, string $deviceId): string
    {
        return implode(':', [
            'velocity_fleet',
            'alert',
            $this->key,
            $this->normalise($customerId),
            $this->normalise($deviceId),
        ]);
    }

    private function normalise(string $value): string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? 'unknown' : $trimmed;
    }
}
