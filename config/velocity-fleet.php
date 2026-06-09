<?php

declare(strict_types=1);

return [
    /*
     * Static flow: an API token generated in the Velocity UI
     * (Account Settings > API Integrations > Create API Token). No refresh.
     */
    'access_token' => env('VELOCITY_FLEET_ACCESS_TOKEN'),

    /*
     * Refresh flow (third-party integrations): a customer-supplied refresh
     * token, optionally with OAuth client credentials.
     */
    'refresh_token' => env('VELOCITY_FLEET_REFRESH_TOKEN'),
    'client_id' => env('VELOCITY_FLEET_CLIENT_ID'),
    'client_secret' => env('VELOCITY_FLEET_CLIENT_SECRET'),

    /*
     * Endpoints — defaults target Radius Velocity Fleet production.
     */
    'base_url' => env('VELOCITY_FLEET_BASE_URL', 'https://www.velocityfleet.com'),
    'token_endpoint' => env('VELOCITY_FLEET_TOKEN_ENDPOINT', 'https://www.velocityfleet.com/o/token/'),

    /*
     * The table the Eloquent token store reads/writes.
     */
    'table' => env('VELOCITY_FLEET_TOKEN_TABLE', 'velocity_fleet_tokens'),

    /*
     * Refresh the access token this many seconds before it expires.
     */
    'refresh_buffer_seconds' => 60,

    /*
     * Encrypt the stored access/refresh token columns at rest (requires APP_KEY).
     */
    'encrypt_tokens' => (bool) env('VELOCITY_FLEET_ENCRYPT_TOKENS', true),

    /*
     * Opt-in HTTP debug log channel (null = silent). Token material is redacted.
     */
    'log_channel' => env('VELOCITY_FLEET_LOG_CHANNEL'),

    /*
     * Refresh-rate-aware cache (stale-while-revalidate + single-flight). The TTL
     * is taken from the API's own live-map refresh hints, clamped by min_ttl so
     * we never poll faster than the provider suggests.
     */
    'cache' => [
        'enabled' => (bool) env('VELOCITY_FLEET_CACHE_ENABLED', true),
        'store' => env('VELOCITY_FLEET_CACHE_STORE'),
        'min_ttl' => (int) env('VELOCITY_FLEET_CACHE_MIN_TTL', 25),
        'large_fleet_threshold' => (int) env('VELOCITY_FLEET_CACHE_LARGE_FLEET_THRESHOLD', 100),
        'prefix' => env('VELOCITY_FLEET_CACHE_PREFIX', 'velocity_fleet'),
        // How long a caller waits for the single-flight holder before fetching itself.
        'lock_seconds' => (int) env('VELOCITY_FLEET_CACHE_LOCK_SECONDS', 10),
        // How long the single-flight lock is held — must exceed the worst-case
        // upstream fetch so a slow refresh can't let a second worker double-fetch.
        'lock_ttl' => (int) env('VELOCITY_FLEET_CACHE_LOCK_TTL', 45),
    ],

    /*
     * Background polling that fans device positions into change-detection events.
     * Off by default. Requires a queue worker and a shared cache/lock store on
     * multi-server deployments.
     */
    'polling' => [
        'enabled' => (bool) env('VELOCITY_FLEET_POLLING_ENABLED', false),
        'customers' => ['*'],
        'queue' => env('VELOCITY_FLEET_POLLING_QUEUE'),
        'cadence_override' => null,
    ],

    /*
     * A device is considered stale (and "offline") when its last position is
     * older than this many seconds.
     */
    'stale_after_seconds' => (int) env('VELOCITY_FLEET_STALE_AFTER', 900),

    /*
     * Opt-in position history. Off by default. PII columns are encrypted.
     */
    'history' => [
        'enabled' => (bool) env('VELOCITY_FLEET_HISTORY_ENABLED', false),
        'connection' => env('VELOCITY_FLEET_HISTORY_CONNECTION'),
        'store_raw' => (bool) env('VELOCITY_FLEET_HISTORY_STORE_RAW', false),
        'encrypt_pii' => (bool) env('VELOCITY_FLEET_HISTORY_ENCRYPT_PII', true),
    ],

    /*
     * Retention pruning for stored history.
     */
    'retention' => [
        'positions_days' => (int) env('VELOCITY_FLEET_RETENTION_POSITIONS_DAYS', 90),
    ],

    /*
     * Geofencing. Off by default.
     */
    'geofencing' => [
        'enabled' => (bool) env('VELOCITY_FLEET_GEOFENCING_ENABLED', false),
        'dwell_minutes' => (int) env('VELOCITY_FLEET_GEOFENCE_DWELL_MINUTES', 5),
    ],

    /*
     * Notifications. Off by default; channels degrade gracefully if absent.
     */
    'notifications' => [
        'enabled' => (bool) env('VELOCITY_FLEET_NOTIFICATIONS_ENABLED', false),
        'channels' => ['mail'],
        'routes' => [],
    ],

    /*
     * Heuristic alert thresholds (approximate — derived from poll cadence).
     */
    'alerts' => [
        'speeding_mph' => (int) env('VELOCITY_FLEET_ALERT_SPEEDING_MPH', 80),
        'idling_minutes' => (int) env('VELOCITY_FLEET_ALERT_IDLING_MINUTES', 10),
        'offline_minutes' => (int) env('VELOCITY_FLEET_ALERT_OFFLINE_MINUTES', 30),
        'throttle_minutes' => (int) env('VELOCITY_FLEET_ALERT_THROTTLE_MINUTES', 30),
    ],

    /*
     * Structured activity logging (counts/ids only — never device-level PII).
     */
    'logging' => [
        'channel' => env('VELOCITY_FLEET_LOGGING_CHANNEL'),
    ],
];
