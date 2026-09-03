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
        // Widen BEFORE encrypting. Crypt::encryptString turns a 10-digit mobile
        // into ~200 characters and a 34-character email into 256 — mobile was
        // varchar(16) and email/name varchar(255), so MySQL silently truncated
        // the ciphertext and the plaintext was gone for good.
        //
        // The unique indexes have to go first: MySQL cannot index TEXT without a
        // prefix length, and they stopped meaning anything the moment these
        // columns held ciphertext — encryption uses a random IV, so the same
        // address encrypts differently every time and never collides. Real
        // uniqueness now belongs on the deterministic *_hash columns.
        foreach ([['email', 'users_email_unique'], ['mobile', 'users_mobile_unique']] as [$column, $index]) {
            if ($this->indexExists('users', $index)) {
                Schema::table('users', fn (Blueprint $table) => $table->dropUnique($index));
            }
        }

        // dob is in the encrypt list below but was left a DATE column: MySQL
        // stores '0000-00-00' for ciphertext in non-strict mode and aborts the
        // migration in strict mode. It is display-only — never sorted or
        // filtered — so TEXT costs nothing and keeps the date readable.
        Schema::table('users', function (Blueprint $table) {
            $table->text('name')->nullable()->change();
            $table->text('email')->nullable()->change();
            $table->text('mobile')->nullable()->change();
            $table->text('gender')->nullable()->change();
            $table->text('dob')->nullable()->change();
        });

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

    /** Environments differ on whether these indexes were ever created. */
    private function indexExists(string $table, string $index): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}`"))
            ->contains(fn ($row) => $row->Key_name === $index);
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
