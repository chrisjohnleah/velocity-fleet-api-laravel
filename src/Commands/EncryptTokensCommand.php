<?php

declare(strict_types=1);

namespace ChrisJohnLeah\VelocityFleet\Laravel\Commands;

use ChrisJohnLeah\VelocityFleet\Laravel\Models\VelocityFleetToken;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\Encrypter;
use Throwable;

class EncryptTokensCommand extends Command
{
    protected $signature = 'velocity-fleet:encrypt-tokens';

    protected $description = 'Encrypt any plaintext token rows at rest (idempotent — skips already-encrypted rows).';

    public function handle(Encrypter $encrypter): int
    {
        if (config('velocity-fleet.encrypt_tokens', true) === false) {
            $this->warn('Token encryption is disabled (velocity-fleet.encrypt_tokens=false). Nothing to do.');

            return self::SUCCESS;
        }

        $migrated = 0;

        foreach (VelocityFleetToken::query()->get() as $row) {
            $rawAccess = $row->getRawOriginal('access_token');

            if (! is_string($rawAccess) || $rawAccess === '') {
                continue;
            }

            if ($this->isEncrypted($encrypter, $rawAccess)) {
                continue;
            }

            // Plaintext — re-assign and save so the `encrypted` cast writes ciphertext.
            $rawRefresh = $row->getRawOriginal('refresh_token');
            $row->access_token = $rawAccess;
            $row->refresh_token = is_string($rawRefresh) && $rawRefresh !== '' ? $rawRefresh : null;
            $row->save();

            $migrated++;
        }

        $this->info($migrated === 0
            ? 'All token rows are already encrypted.'
            : sprintf('Encrypted %d token row(s).', $migrated));

        return self::SUCCESS;
    }

    private function isEncrypted(Encrypter $encrypter, string $value): bool
    {
        try {
            $encrypter->decrypt($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
