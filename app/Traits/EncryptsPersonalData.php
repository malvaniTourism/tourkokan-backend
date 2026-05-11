<?php

namespace App\Traits;

/**
 * Handles encryption of PII fields and blind-index hashing for searchable fields.
 *
 * Model must define:
 *   protected array $blindIndexFields = ['name', 'email', 'mobile'];
 *
 * Hash columns are auto-populated on every save.
 * Use Model::findByEmail($email) / findByMobile($mobile) for lookups.
 */
trait EncryptsPersonalData
{
    public static function bootEncryptsPersonalData(): void
    {
        static::saving(function ($model) {
            foreach ($model->blindIndexFields ?? [] as $field) {
                $hashColumn = $field . '_hash';
                if ($model->isDirty($field)) {
                    // Use getAttribute (goes through cast) to get the plaintext value,
                    // since getAttributes() returns the already-encrypted ciphertext.
                    $plainValue = $model->$field;
                    $model->$hashColumn = !empty($plainValue)
                        ? static::makeBlindIndex($plainValue)
                        : null;
                }
            }
        });

        // Clear hash columns on soft-delete so the email/mobile slot is freed
        // and another user can register with the same email after deletion.
        static::deleting(function ($model) {
            if (method_exists($model, 'runSoftDelete')) {
                foreach ($model->blindIndexFields ?? [] as $field) {
                    $model->setAttribute($field . '_hash', null);
                }
                $model->saveQuietly();
            }
        });
    }

    /**
     * Generate a consistent HMAC-SHA256 blind index for a value.
     * Same input always produces the same hash → safe for WHERE lookups.
     */
    public static function makeBlindIndex(string $value): string
    {
        return hash_hmac('sha256', strtolower(trim($value)), config('app.key'));
    }

    /**
     * Find a user by encrypted email using the blind index.
     */
    public static function findByEmail(string $email): ?static
    {
        return static::where('email_hash', static::makeBlindIndex($email))->first();
    }

    /**
     * Find a user by encrypted mobile using the blind index.
     */
    public static function findByMobile(string $mobile): ?static
    {
        return static::where('mobile_hash', static::makeBlindIndex($mobile))->first();
    }

    /**
     * Hash an OTP for secure storage.
     * Use verifyOtp() to check instead of direct comparison.
     */
    public static function hashOtp(string $otp): string
    {
        return hash_hmac('sha256', $otp, config('app.key'));
    }

    /**
     * Verify a plain OTP against a stored hash.
     */
    public static function verifyOtp(string $plainOtp, string $storedHash): bool
    {
        return hash_equals(static::hashOtp($plainOtp), $storedHash);
    }
}
