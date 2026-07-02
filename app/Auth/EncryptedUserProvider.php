<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Custom UserProvider that uses blind-index hash columns
 * for email/mobile lookups when the actual columns are AES-256 encrypted.
 */
class EncryptedUserProvider extends EloquentUserProvider
{
    public function __construct(Hasher $hasher)
    {
        parent::__construct($hasher, User::class);
    }

    /**
     * Override credential retrieval to use hash columns for encrypted fields.
     * Called by Auth::attempt() and JWTAuth internally.
     */
    public function retrieveByCredentials(array $credentials): ?User
    {
        $query = $this->createModel()->newQuery();

        foreach ($credentials as $key => $value) {
            if (str_contains($key, 'password')) {
                continue;
            }

            // Route encrypted searchable fields through their blind-index hash column
            if (in_array($key, ['email', 'mobile'])) {
                $query->where($key . '_hash', User::makeBlindIndex($value));
            } else {
                $query->where($key, $value);
            }
        }

        return $query->first();
    }
}
