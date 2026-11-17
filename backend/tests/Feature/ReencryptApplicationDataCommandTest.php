<?php

namespace Tests\Feature;

use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReencryptApplicationDataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_change_data_and_apply_uses_only_the_new_key(): void
    {
        $oldKey = str_repeat('a', 32);
        $newKey = str_repeat('b', 32);
        $old = new Encrypter($oldKey, 'AES-256-CBC');
        $channelId = (string) Str::uuid();
        $oldChannelSecret = $old->encryptString('telegram-token');
        $oldSetting = $old->encryptString('discord-webhook');

        DB::table('channels')->insert([
            'id' => $channelId,
            'name' => 'Telegram test',
            'type' => 'telegram',
            'configuration' => json_encode(['bot_token' => $oldChannelSecret]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('system_settings')->insert([
            'key' => 'discord_alert_webhook_url',
            'value' => $oldSetting,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->configureKeys($oldKey, $newKey);

        $this->assertSame(0, Artisan::call('intera:reencrypt-app-data'));
        $this->assertSame($oldChannelSecret, $this->channelSecret($channelId));
        $this->assertSame($oldSetting, DB::table('system_settings')->value('value'));

        $this->assertSame(0, Artisan::call('intera:reencrypt-app-data', ['--apply' => true]));
        $currentOnly = new Encrypter($newKey, 'AES-256-CBC');
        $this->assertSame('telegram-token', $currentOnly->decryptString($this->channelSecret($channelId)));
        $this->assertSame('discord-webhook', $currentOnly->decryptString(DB::table('system_settings')->value('value')));
    }

    public function test_a_bad_secret_rolls_back_every_change(): void
    {
        $oldKey = str_repeat('a', 32);
        $newKey = str_repeat('b', 32);
        $old = new Encrypter($oldKey, 'AES-256-CBC');
        $channelId = (string) Str::uuid();
        $oldChannelSecret = $old->encryptString('telegram-token');

        DB::table('channels')->insert([
            'id' => $channelId,
            'name' => 'Telegram test',
            'type' => 'telegram',
            'configuration' => json_encode(['bot_token' => $oldChannelSecret]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('system_settings')->insert([
            'key' => 'discord_alert_webhook_url',
            'value' => 'invalid-ciphertext',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->configureKeys($oldKey, $newKey);

        $this->assertSame(1, Artisan::call('intera:reencrypt-app-data', ['--apply' => true]));
        $this->assertSame($oldChannelSecret, $this->channelSecret($channelId));
    }

    private function configureKeys(string $oldKey, string $newKey): void
    {
        config()->set('app.key', 'base64:'.base64_encode($newKey));
        config()->set('app.previous_keys', ['base64:'.base64_encode($oldKey)]);
        $encrypter = new Encrypter($newKey, 'AES-256-CBC');
        $encrypter->previousKeys([$oldKey]);
        Crypt::swap($encrypter);
    }

    private function channelSecret(string $channelId): string
    {
        $configuration = json_decode(DB::table('channels')->where('id', $channelId)->value('configuration'), true);

        return $configuration['bot_token'];
    }
}
