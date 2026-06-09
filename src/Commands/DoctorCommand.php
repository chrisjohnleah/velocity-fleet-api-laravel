<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Commands;

use ChrisJohnLeah\VelocityFleet\Laravel\Models\VelocityFleetToken;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/**
 * CI-runnable self-checks for a Velocity Fleet deployment. Prints a checklist
 * and exits non-zero on any failure so it can gate a release pipeline. NEVER
 * prints secrets or token material — only PASS/FAIL/WARN status lines.
 */
class DoctorCommand extends Command
{
    protected $signature = 'velocity-fleet:doctor';

    protected $description = 'Run Velocity Fleet self-checks (config, encryption, retention, cache driver).';

    private const STATUS_PASS = 'PASS';

    private const STATUS_WARN = 'WARN';

    private const STATUS_FAIL = 'FAIL';

    /**
     * @var list<array{status: string, label: string, detail: string}>
     */
    private array $results = [];

    public function handle(ConfigRepository $config): int
    {
        $this->checkAppKey($config);
        $this->checkTokenEncryption($config);
        $this->checkRetention($config);
        $this->checkCacheDriver($config);

        $this->render();

        $failed = array_filter(
            $this->results,
            static fn (array $row): bool => $row['status'] === self::STATUS_FAIL,
        );

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function checkAppKey(ConfigRepository $config): void
    {
        if (! $this->boolConfig($config, 'velocity-fleet.encrypt_tokens')) {
            $this->recordPass('APP_KEY', 'token encryption disabled — APP_KEY not required.');

            return;
        }

        $key = $config->get('app.key');

        if (is_string($key) && $key !== '') {
            $this->recordPass('APP_KEY', 'present (token encryption is enabled).');

            return;
        }

        $this->recordFail('APP_KEY', 'missing but encrypt_tokens is on — tokens cannot be encrypted at rest.');
    }

    private function checkTokenEncryption(ConfigRepository $config): void
    {
        if (! $this->boolConfig($config, 'velocity-fleet.encrypt_tokens')) {
            $this->recordPass('Token at rest', 'encryption disabled by config.');

            return;
        }

        try {
            $row = VelocityFleetToken::query()->latest('id')->first();
        } catch (Throwable) {
            $this->recordWarn('Token at rest', 'could not read the tokens table (migration not run yet?).');

            return;
        }

        if ($row === null) {
            $this->recordWarn('Token at rest', 'no token stored yet — run velocity-fleet:connect.');

            return;
        }

        $raw = $this->rawAccessToken($row);

        if ($raw === null) {
            $this->recordWarn('Token at rest', 'stored token column is empty.');

            return;
        }

        if ($this->looksEncrypted($raw)) {
            $this->recordPass('Token at rest', 'stored access token appears encrypted.');

            return;
        }

        $this->recordFail('Token at rest', 'stored access token looks like plaintext while encrypt_tokens is on.');
    }

    private function checkRetention(ConfigRepository $config): void
    {
        if (! $this->boolConfig($config, 'velocity-fleet.history.enabled')) {
            $this->recordPass('Retention', 'history disabled — retention not required.');

            return;
        }

        $days = $config->get('velocity-fleet.retention.positions_days');

        if (is_int($days) && $days > 0) {
            $this->recordPass('Retention', "history on, pruning after {$days} day(s).");

            return;
        }

        $this->recordFail('Retention', 'history is enabled but retention.positions_days is not a positive integer.');
    }

    private function checkCacheDriver(ConfigRepository $config): void
    {
        $pollingEnabled = $this->boolConfig($config, 'velocity-fleet.polling.enabled');
        $cacheEnabled = $this->boolConfig($config, 'velocity-fleet.cache.enabled');

        if (! $pollingEnabled && ! $cacheEnabled) {
            $this->recordPass('Cache/lock store', 'caching and polling both off — no shared store required.');

            return;
        }

        $driver = $this->resolveCacheDriver($config);

        if ($driver === 'array' || $driver === 'file') {
            $this->recordWarn(
                'Cache/lock store',
                "driver '{$driver}' is not shared across servers/workers; use redis/memcached/database for multi-node single-flight + locks.",
            );

            return;
        }

        $this->recordPass('Cache/lock store', "driver '{$driver}' is a shared store.");
    }

    private function resolveCacheDriver(ConfigRepository $config): string
    {
        $store = $config->get('velocity-fleet.cache.store');

        if (! is_string($store) || $store === '') {
            $default = $config->get('cache.default');
            $store = is_string($default) && $default !== '' ? $default : 'array';
        }

        $driver = $config->get("cache.stores.{$store}.driver");

        return is_string($driver) && $driver !== '' ? $driver : $store;
    }

    /**
     * Read the raw (as-stored) access token value, bypassing any model cast that
     * would transparently decrypt it, so we can inspect the value at rest.
     */
    private function rawAccessToken(VelocityFleetToken $row): ?string
    {
        $value = $row->getAttributes()['access_token'] ?? null;

        if (! is_string($value)) {
            return null;
        }

        return $value === '' ? null : $value;
    }

    /**
     * Best-effort heuristic: Laravel's encrypter emits base64-encoded JSON
     * carrying iv/value/mac. A plaintext OAuth token will not decode to that.
     */
    private function looksEncrypted(string $value): bool
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload)
            && array_key_exists('iv', $payload)
            && array_key_exists('value', $payload)
            && array_key_exists('mac', $payload);
    }

    private function boolConfig(ConfigRepository $config, string $key): bool
    {
        $value = $config->get($key);

        return is_bool($value) ? $value : false;
    }

    private function recordPass(string $label, string $detail): void
    {
        $this->results[] = ['status' => self::STATUS_PASS, 'label' => $label, 'detail' => $detail];
    }

    private function recordWarn(string $label, string $detail): void
    {
        $this->results[] = ['status' => self::STATUS_WARN, 'label' => $label, 'detail' => $detail];
    }

    private function recordFail(string $label, string $detail): void
    {
        $this->results[] = ['status' => self::STATUS_FAIL, 'label' => $label, 'detail' => $detail];
    }

    private function render(): void
    {
        foreach ($this->results as $row) {
            $line = sprintf('[%s] %s — %s', $row['status'], $row['label'], $row['detail']);

            match ($row['status']) {
                self::STATUS_PASS => $this->info($line),
                self::STATUS_WARN => $this->warn($line),
                default => $this->error($line),
            };
        }
    }
}
