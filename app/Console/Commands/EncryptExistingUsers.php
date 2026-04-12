<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use App\Models\User;

class EncryptExistingUsers extends Command
{
    protected $signature   = 'users:encrypt-existing {--dry-run : Show what would be changed without writing}';
    protected $description = 'Encrypt existing plaintext PII in the users table and populate blind-index hash columns';

    private array $encryptFields = ['name', 'email', 'mobile', 'dob', 'gender'];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $rows   = DB::table('users')->whereNull('deleted_at')->get();

        $this->info("Found {$rows->count()} users. " . ($dryRun ? '[DRY RUN]' : 'Encrypting...'));

        $bar = $this->output->createProgressBar($rows->count());
        $bar->start();

        $updated = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $update = [];

            foreach ($this->encryptFields as $field) {
                $value = $row->$field ?? null;
                if (empty($value)) continue;

                // Skip if already encrypted (Crypt produces base64-encoded JSON)
                if ($this->isAlreadyEncrypted($value)) {
                    continue;
                }

                $update[$field] = Crypt::encryptString($value);
            }

            // Always recompute blind index hash columns to fix any stale/incorrect values.
            // Raw DB value may be plaintext (old data) or encrypted (new data).
            if (!empty($row->email)) {
                if (!$this->isAlreadyEncrypted($row->email)) {
                    $update['email_hash'] = User::makeBlindIndex($row->email);
                } else {
                    try {
                        $plain = Crypt::decryptString($row->email);
                        $update['email_hash'] = User::makeBlindIndex($plain);
                    } catch (\Throwable) {}
                }
            }

            if (!empty($row->mobile)) {
                if (!$this->isAlreadyEncrypted($row->mobile)) {
                    $update['mobile_hash'] = User::makeBlindIndex($row->mobile);
                } else {
                    try {
                        $plain = Crypt::decryptString($row->mobile);
                        $update['mobile_hash'] = User::makeBlindIndex($plain);
                    } catch (\Throwable) {}
                }
            }

            if (!empty($update)) {
                if (!$dryRun) {
                    try {
                        DB::table('users')->where('id', $row->id)->update($update);
                        $updated++;
                    } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                        $bar->clear();
                        $this->warn("  [DUPLICATE] user id={$row->id} email={$row->email} — skipped (duplicate email/mobile in DB)");
                        $bar->display();
                        $skipped++;
                    }
                } else {
                    $updated++;
                }
            } else {
                $skipped++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Done. Updated: {$updated} | Already encrypted / skipped: {$skipped}");

        return self::SUCCESS;
    }

    private function isAlreadyEncrypted(string $value): bool
    {
        // Laravel's Crypt produces base64-encoded JSON starting with eyJ
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
}
