<?php

namespace App\Console\Commands;

use App\Casts\EncryptedChannelConfiguration;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ReencryptApplicationDataCommand extends Command
{
    protected $signature = 'intera:reencrypt-app-data {--apply : Write values using the current APP_KEY}';

    protected $description = 'Re-encrypt channel credentials and system settings after APP_KEY rotation';

    public function handle(): int
    {
        if (empty(config('app.previous_keys'))) {
            $this->error('APP_PREVIOUS_KEYS must contain the old key before running this command.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $current = new Encrypter(Crypt::getFacadeRoot()->getKey(), config('app.cipher'));
        $counts = ['channels' => 0, 'channel_secrets' => 0, 'system_settings' => 0];

        try {
            DB::transaction(function () use ($apply, $current, &$counts): void {
                $channels = DB::table('channels')->select('id', 'configuration');
                if ($apply) {
                    $channels->lockForUpdate();
                }

                foreach ($channels->get() as $channel) {
                    if ($channel->configuration === null || $channel->configuration === '') {
                        continue;
                    }

                    $configuration = json_decode($channel->configuration, true);
                    if (! is_array($configuration)) {
                        throw new RuntimeException("Invalid configuration JSON for channel {$channel->id}.");
                    }

                    $changed = false;
                    foreach (EncryptedChannelConfiguration::SECRET_KEYS as $key) {
                        $value = $configuration[$key] ?? null;
                        if (! is_string($value) || $value === '') {
                            continue;
                        }

                        $replacement = $this->reencrypt($current, $value, "channel {$channel->id} field {$key}");
                        if ($replacement !== null) {
                            $configuration[$key] = $replacement;
                            $counts['channel_secrets']++;
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        $counts['channels']++;
                        if ($apply) {
                            DB::table('channels')->where('id', $channel->id)->update([
                                'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }

                $settings = DB::table('system_settings')->select('id', 'value');
                if ($apply) {
                    $settings->lockForUpdate();
                }

                foreach ($settings->get() as $setting) {
                    if (! is_string($setting->value) || $setting->value === '') {
                        continue;
                    }

                    $replacement = $this->reencrypt($current, $setting->value, "system setting {$setting->id}");
                    if ($replacement !== null) {
                        $counts['system_settings']++;
                        if ($apply) {
                            DB::table('system_settings')->where('id', $setting->id)->update([
                                'value' => $replacement,
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }
            });
        } catch (Throwable $exception) {
            $this->error('Re-encryption stopped; no changes were committed. '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(($apply ? 'Re-encrypted' : 'Dry run').": {$counts['channels']} channels, {$counts['channel_secrets']} channel secrets, {$counts['system_settings']} system settings.");

        return self::SUCCESS;
    }

    private function reencrypt(Encrypter $current, string $ciphertext, string $label): ?string
    {
        try {
            $current->decryptString($ciphertext);

            return null;
        } catch (DecryptException) {
            // Continue with the previous keys configured in Laravel's encrypter.
        }

        try {
            return $current->encryptString(Crypt::decryptString($ciphertext));
        } catch (DecryptException) {
            throw new RuntimeException("Cannot decrypt {$label} with the current or previous keys.");
        }
    }
}
