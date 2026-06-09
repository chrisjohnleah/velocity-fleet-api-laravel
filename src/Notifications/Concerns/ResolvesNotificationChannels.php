<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Notifications\Concerns;

/**
 * Shared channel resolution for the package's notifications. Reads the
 * configured channel list and degrades gracefully: only channels this package
 * can deliver without an extra Composer package ("mail", "array", "database",
 * "broadcast") are kept, so a config listing "slack" with no Slack channel
 * installed never fatals — it is simply dropped.
 */
trait ResolvesNotificationChannels
{
    /**
     * The channels this notification will actually be sent on.
     *
     * @return list<string>
     */
    private function configuredChannels(): array
    {
        $channels = $this->normaliseConfiguredChannels();

        $supported = $this->supportedChannels();

        $filtered = array_values(array_filter(
            $channels,
            static fn (string $channel): bool => in_array($channel, $supported, true),
        ));

        return $filtered === [] ? ['mail'] : $filtered;
    }

    /**
     * Channels the package can deliver to without optional dependencies.
     *
     * @return list<string>
     */
    private function supportedChannels(): array
    {
        return ['mail', 'array', 'database', 'broadcast'];
    }

    /**
     * Raw configured channels, coerced to a clean list of strings.
     *
     * @return list<string>
     */
    private function normaliseConfiguredChannels(): array
    {
        $configured = config('velocity-fleet.notifications.channels', ['mail']);

        if (! is_array($configured)) {
            return ['mail'];
        }

        $channels = [];

        foreach ($configured as $channel) {
            if (is_string($channel) && $channel !== '') {
                $channels[] = $channel;
            }
        }

        return $channels;
    }
}
