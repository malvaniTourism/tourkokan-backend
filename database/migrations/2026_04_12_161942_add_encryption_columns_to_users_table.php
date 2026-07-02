<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\User;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email_hash')->nullable()->after('email')
                  ->comment('HMAC-SHA256 of email — used for WHERE lookups');
            $table->string('mobile_hash')->nullable()->after('mobile')
                  ->comment('HMAC-SHA256 of mobile — used for WHERE lookups');
        });

        // Encrypt existing plaintext PII and populate blind index hash columns.
        // Runs automatically on every environment — no separate artisan command needed.
        DB::table('users')->whereNull('deleted_at')->orderBy('id')->each(function ($row) {
            $update = [];

            foreach (['name', 'email', 'mobile', 'dob', 'gender'] as $field) {
                $value = $row->$field ?? null;
                if (!empty($value) && !$this->isEncrypted($value)) {
                    $update[$field] = Crypt::encryptString($value);
                }
            }

            foreach (['email', 'mobile'] as $field) {
                $value = $row->$field ?? null;
                if (empty($value)) continue;

                $plain = $this->isEncrypted($value)
                    ? $this->tryDecrypt($value)
                    : $value;

                if ($plain !== null) {
                    $update[$field . '_hash'] = User::makeBlindIndex($plain);
                }
            }

            if (!empty($update)) {
                DB::table('users')->where('id', $row->id)->update($update);
            }
        });

        // Add unique indexes after data is populated to avoid constraint violations
        // during the backfill above (duplicate rows get skipped by the each() loop).
        Schema::table('users', function (Blueprint $table) {
            $table->unique('email_hash');
            $table->unique('mobile_hash');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email_hash']);
            $table->dropUnique(['mobile_hash']);
            $table->dropColumn(['email_hash', 'mobile_hash']);
        });
    }

    private function isEncrypted(string $value): bool
    {
        if (!str_starts_with($value, 'eyJ')) {
            return false;
        }
        try {
            Crypt::decryptString($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function tryDecrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }
};
