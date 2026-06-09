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
     * token, optionally with OAuth client credentials, exchanged for short-lived
     * access tokens at the token endpoint.
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
];
