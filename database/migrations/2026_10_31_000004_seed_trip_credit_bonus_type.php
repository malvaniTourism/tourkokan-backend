<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The bonus type trip credits are booked against.
 *
 * Credits live on `wallets`, which already is an append-only ledger with a
 * user, an amount and a description — so there is no credits table. The one
 * thing it needs is a `bonus_types` row, because `wallets.bonus_id` is NOT NULL.
 *
 * A side effect worth having: referral bonuses already written to `wallets`
 * sit in the same ledger, so a referral can pay for a trip with no extra work.
 */
class SeedTripCreditBonusType extends Migration
{
    private const CODE = 'trip_credit';

    public function up()
    {
        $exists = DB::table('bonus_types')->where('code', self::CODE)->exists();

        if ($exists) {
            return;
        }

        DB::table('bonus_types')->insert([
            'name'        => 'Trip credit',
            'code'        => self::CODE,
            // One credit buys one generated itinerary.
            'amount'      => 1.00,
            'description' => 'One AI trip plan. Granted on email verification and per referral.',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down()
    {
        DB::table('bonus_types')->where('code', self::CODE)->delete();
    }
}
