<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class EncryptedChannelConfiguration implements CastsAttributes
{
    public const SECRET_KEYS = [
        'access_token',
        'verify_token',
        'app_secret',
        'bot_token',
        'webhook_secret_token',
        'page_access_token',
    ];

    /**
     * Cast the given value from the database into PHP array, decrypting secret keys.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (empty($value)) {
            return [];
        }

        $config = is_string($value) ? json_decode($value, true) : (array) $value;
        if (! is_array($config)) {
            return [];
        }

        foreach (self::SECRET_KEYS as $secretKey) {
            if (isset($config[$secretKey]) && is_string($config[$secretKey]) && $config[$secretKey] !== '') {
                try {
                    $config[$secretKey] = Crypt::decryptString($config[$secretKey]);
                } catch (DecryptException) {
                    // Plaintext legacy fallback - preserve as is without breaking existing data
                }
            }
        }

        return $config;
    }

    /**
     * Prepare the given array for storage into database JSON column, encrypting secret keys.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (is_null($value)) {
            return json_encode([]);
        }

        $config = is_string($value) ? json_decode($value, true) : (array) $value;
        if (! is_array($config)) {
            $config = [];
        }

        foreach (self::SECRET_KEYS as $secretKey) {
            if (isset($config[$secretKey]) && is_string($config[$secretKey]) && $config[$secretKey] !== '') {
                // If it is already an encrypted string under current key, preserve it
                try {
                    Crypt::decryptString($config[$secretKey]);
                } catch (DecryptException) {
                    // Not encrypted or plaintext: encrypt with APP_KEY
                    $config[$secretKey] = Crypt::encryptString($config[$secretKey]);
                }
            }
        }

        return json_encode($config);
    }
}
