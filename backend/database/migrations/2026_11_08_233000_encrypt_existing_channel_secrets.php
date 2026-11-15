<?php

use App\Casts\EncryptedChannelConfiguration;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $channels = DB::table('channels')->get(['id', 'configuration']);

        foreach ($channels as $channel) {
            if (empty($channel->configuration)) {
                continue;
            }

            $config = is_string($channel->configuration)
                ? json_decode($channel->configuration, true)
                : (array) $channel->configuration;

            if (! is_array($config)) {
                continue;
            }

            $modified = false;
            foreach (EncryptedChannelConfiguration::SECRET_KEYS as $secretKey) {
                if (isset($config[$secretKey]) && is_string($config[$secretKey]) && $config[$secretKey] !== '') {
                    try {
                        Crypt::decryptString($config[$secretKey]);
                    } catch (DecryptException) {
                        $config[$secretKey] = Crypt::encryptString($config[$secretKey]);
                        $modified = true;
                    }
                }
            }

            if ($modified) {
                DB::table('channels')
                    ->where('id', $channel->id)
                    ->update([
                        'configuration' => json_encode($config),
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $channels = DB::table('channels')->get(['id', 'configuration']);

        foreach ($channels as $channel) {
            if (empty($channel->configuration)) {
                continue;
            }

            $config = is_string($channel->configuration)
                ? json_decode($channel->configuration, true)
                : (array) $channel->configuration;

            if (! is_array($config)) {
                continue;
            }

            $modified = false;
            foreach (EncryptedChannelConfiguration::SECRET_KEYS as $secretKey) {
                if (isset($config[$secretKey]) && is_string($config[$secretKey]) && $config[$secretKey] !== '') {
                    try {
                        $config[$secretKey] = Crypt::decryptString($config[$secretKey]);
                        $modified = true;
                    } catch (DecryptException) {
                        // Was not encrypted, leave alone
                    }
                }
            }

            if ($modified) {
                DB::table('channels')
                    ->where('id', $channel->id)
                    ->update([
                        'configuration' => json_encode($config),
                        'updated_at' => now(),
                    ]);
            }
        }
    }
};
