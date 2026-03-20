<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EventTypeSeeder extends Seeder
{
    public function run()
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('event_types')->truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $now = now();

        $types = [
            // ── Top-level types ────────────────────────────────────
            [
                'parent_id'   => null,
                'name'        => 'Festival',
                'mr_name'     => 'महोत्सव',
                'code'        => 'festival',
                'icon'        => 'festival.png',
                'status'      => true,
                'is_hot_type' => true,
                'description' => 'Cultural and seasonal festivals',
                'meta_data'   => null,
            ],
            [
                'parent_id'   => null,
                'name'        => 'Cultural',
                'mr_name'     => 'सांस्कृतिक',
                'code'        => 'cultural',
                'icon'        => 'cultural.png',
                'status'      => true,
                'is_hot_type' => true,
                'description' => 'Cultural programmes, dance and music events',
                'meta_data'   => null,
            ],
            [
                'parent_id'   => null,
                'name'        => 'Religious',
                'mr_name'     => 'धार्मिक',
                'code'        => 'religious',
                'icon'        => 'religious.png',
                'status'      => true,
                'is_hot_type' => true,
                'description' => 'Pujas, yatras, and religious gatherings',
                'meta_data'   => null,
            ],
            [
                'parent_id'   => null,
                'name'        => 'Sports',
                'mr_name'     => 'क्रीडा',
                'code'        => 'sports',
                'icon'        => 'sports.png',
                'status'      => true,
                'is_hot_type' => false,
                'description' => 'Sports tournaments and competitions',
                'meta_data'   => null,
            ],
            [
                'parent_id'   => null,
                'name'        => 'Food & Cuisine',
                'mr_name'     => 'खाद्य व पाककला',
                'code'        => 'food',
                'icon'        => 'food.png',
                'status'      => true,
                'is_hot_type' => true,
                'description' => 'Food festivals, cooking events, and cuisine exhibitions',
                'meta_data'   => null,
            ],
            [
                'parent_id'   => null,
                'name'        => 'Workshop',
                'mr_name'     => 'कार्यशाळा',
                'code'        => 'workshop',
                'icon'        => 'workshop.png',
                'status'      => true,
                'is_hot_type' => false,
                'description' => 'Skill-building workshops and training sessions',
                'meta_data'   => null,
            ],
            [
                'parent_id'   => null,
                'name'        => 'Concert & Music',
                'mr_name'     => 'संगीत कार्यक्रम',
                'code'        => 'concert',
                'icon'        => 'concert.png',
                'status'      => true,
                'is_hot_type' => true,
                'description' => 'Live music concerts and performances',
                'meta_data'   => null,
            ],
            [
                'parent_id'   => null,
                'name'        => 'Exhibition',
                'mr_name'     => 'प्रदर्शनी',
                'code'        => 'exhibition',
                'icon'        => 'exhibition.png',
                'status'      => true,
                'is_hot_type' => false,
                'description' => 'Art, craft, and trade exhibitions',
                'meta_data'   => null,
            ],
            [
                'parent_id'   => null,
                'name'        => 'Community',
                'mr_name'     => 'सामुदायिक',
                'code'        => 'community',
                'icon'        => 'community.png',
                'status'      => true,
                'is_hot_type' => false,
                'description' => 'Community gatherings and local events',
                'meta_data'   => null,
            ],
            [
                'parent_id'   => null,
                'name'        => 'Nature & Adventure',
                'mr_name'     => 'निसर्ग व साहस',
                'code'        => 'nature_adventure',
                'icon'        => 'nature.png',
                'status'      => true,
                'is_hot_type' => true,
                'description' => 'Trekking, beach activities, nature camps',
                'meta_data'   => null,
            ],
            [
                'parent_id'   => null,
                'name'        => 'Tourism',
                'mr_name'     => 'पर्यटन',
                'code'        => 'tourism',
                'icon'        => 'tourism.png',
                'status'      => true,
                'is_hot_type' => true,
                'description' => 'Guided tours and tourism-related events',
                'meta_data'   => null,
            ],
            [
                'parent_id'   => null,
                'name'        => 'Health & Wellness',
                'mr_name'     => 'आरोग्य',
                'code'        => 'health_wellness',
                'icon'        => 'health.png',
                'status'      => true,
                'is_hot_type' => false,
                'description' => 'Yoga, meditation, and health camps',
                'meta_data'   => null,
            ],
            [
                'parent_id'   => null,
                'name'        => 'Education',
                'mr_name'     => 'शिक्षण',
                'code'        => 'education',
                'icon'        => 'education.png',
                'status'      => true,
                'is_hot_type' => false,
                'description' => 'Seminars, talks, and educational programmes',
                'meta_data'   => null,
            ],
            [
                'parent_id'   => null,
                'name'        => 'Business & Trade',
                'mr_name'     => 'व्यापार',
                'code'        => 'business_trade',
                'icon'        => 'business.png',
                'status'      => true,
                'is_hot_type' => false,
                'description' => 'Business meets, trade fairs, and networking events',
                'meta_data'   => null,
            ],
            [
                'parent_id'   => null,
                'name'        => 'Other',
                'mr_name'     => 'इतर',
                'code'        => 'other',
                'icon'        => 'other.png',
                'status'      => true,
                'is_hot_type' => false,
                'description' => 'Miscellaneous events',
                'meta_data'   => null,
            ],
        ];

        // Insert top-level types and capture inserted IDs by code
        $insertedIds = [];
        foreach ($types as $type) {
            $id = DB::table('event_types')->insertGetId(array_merge($type, [
                'created_at' => $now,
                'updated_at' => $now,
            ]));
            $insertedIds[$type['code']] = $id;
        }

        // ── Sub-types ──────────────────────────────────────────────
        $subTypes = [
            // Festival sub-types
            ['parent' => 'festival', 'name' => 'Mango Festival',       'mr_name' => 'आंबा महोत्सव',      'code' => 'festival_mango'],
            ['parent' => 'festival', 'name' => 'Coconut Festival',     'mr_name' => 'नारळी महोत्सव',     'code' => 'festival_coconut'],
            ['parent' => 'festival', 'name' => 'Cashew Festival',      'mr_name' => 'काजू महोत्सव',      'code' => 'festival_cashew'],
            ['parent' => 'festival', 'name' => 'Ganesh Festival',      'mr_name' => 'गणेश महोत्सव',      'code' => 'festival_ganesh'],
            ['parent' => 'festival', 'name' => 'Beach Festival',       'mr_name' => 'बीच फेस्टिवल',      'code' => 'festival_beach'],
            ['parent' => 'festival', 'name' => 'Harvest Festival',     'mr_name' => 'हर्वेस्ट फेस्टिवल', 'code' => 'festival_harvest'],

            // Cultural sub-types
            ['parent' => 'cultural', 'name' => 'Folk Dance',           'mr_name' => 'लोकनृत्य',          'code' => 'cultural_folk_dance'],
            ['parent' => 'cultural', 'name' => 'Classical Music',      'mr_name' => 'शास्त्रीय संगीत',   'code' => 'cultural_classical_music'],
            ['parent' => 'cultural', 'name' => 'Drama & Theatre',      'mr_name' => 'नाटक',              'code' => 'cultural_drama'],
            ['parent' => 'cultural', 'name' => 'Art Exhibition',       'mr_name' => 'कला प्रदर्शनी',     'code' => 'cultural_art'],

            // Religious sub-types
            ['parent' => 'religious', 'name' => 'Yatra',               'mr_name' => 'यात्रा',            'code' => 'religious_yatra'],
            ['parent' => 'religious', 'name' => 'Puja & Aarti',        'mr_name' => 'पूजा आरती',         'code' => 'religious_puja'],
            ['parent' => 'religious', 'name' => 'Jatra',               'mr_name' => 'जत्रा',             'code' => 'religious_jatra'],

            // Sports sub-types
            ['parent' => 'sports', 'name' => 'Cricket',                'mr_name' => 'क्रिकेट',           'code' => 'sports_cricket'],
            ['parent' => 'sports', 'name' => 'Kabaddi',                'mr_name' => 'कबड्डी',            'code' => 'sports_kabaddi'],
            ['parent' => 'sports', 'name' => 'Water Sports',           'mr_name' => 'जलक्रीडा',          'code' => 'sports_water'],
            ['parent' => 'sports', 'name' => 'Marathon',               'mr_name' => 'मॅरेथॉन',           'code' => 'sports_marathon'],

            // Food sub-types
            ['parent' => 'food', 'name' => 'Seafood Festival',         'mr_name' => 'मासे महोत्सव',      'code' => 'food_seafood'],
            ['parent' => 'food', 'name' => 'Malvani Cuisine',          'mr_name' => 'मालवणी जेवण',       'code' => 'food_malvani'],
            ['parent' => 'food', 'name' => 'Street Food Fair',         'mr_name' => 'स्ट्रीट फूड',       'code' => 'food_street'],

            // Nature sub-types
            ['parent' => 'nature_adventure', 'name' => 'Trekking',     'mr_name' => 'ट्रेकिंग',          'code' => 'nature_trekking'],
            ['parent' => 'nature_adventure', 'name' => 'Beach Cleanup', 'mr_name' => 'समुद्र किनारा',    'code' => 'nature_beach_cleanup'],
            ['parent' => 'nature_adventure', 'name' => 'Bird Watching', 'mr_name' => 'पक्षी निरीक्षण',   'code' => 'nature_bird_watching'],
            ['parent' => 'nature_adventure', 'name' => 'Camping',       'mr_name' => 'कॅम्पिंग',         'code' => 'nature_camping'],
        ];

        foreach ($subTypes as $sub) {
            DB::table('event_types')->insert([
                'parent_id'   => $insertedIds[$sub['parent']] ?? null,
                'name'        => $sub['name'],
                'mr_name'     => $sub['mr_name'],
                'code'        => $sub['code'],
                'icon'        => $sub['code'] . '.png',
                'status'      => true,
                'is_hot_type' => false,
                'description' => null,
                'meta_data'   => null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        $this->command->info('Event types seeded: ' . count($types) . ' top-level, ' . count($subTypes) . ' sub-types.');
    }
}
