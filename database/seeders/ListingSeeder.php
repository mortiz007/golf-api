<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ListingSeeder extends Seeder
{
    /**
     * Seeds 5 listings covering a representative mix of
     * moderation/enrichment states and visibility scenarios.
     */
    public function run(): void
    {
        $userIds     = DB::table('users')->pluck('id', 'email');
        $categoryIds = DB::table('categories')->pluck('id', 'name');

        $alice = $userIds['alice@golf.test'];
        $bob   = $userIds['bob@golf.test'];

        $now = now();

        $rows = [
            // 1) Approved + enriched + visible (end_date futuro)
            [
                'user_id'              => $alice,
                'category_id'          => $categoryIds['Drivers'],
                'title'                => 'Titanium Driver Pro',
                'price'                => 299.99,
                'condition'            => 'Like New',
                'description'          => 'Lightweight titanium driver in excellent condition, barely used.',
                'end_date'             => $now->copy()->addDays(30)->toDateString(),
                'moderation_status'    => 'approved',
                'moderation_result'    => json_encode([
                    'status'      => 'approved',
                    'labels'      => [],
                    'scores'      => ['spam' => 0.01, 'inappropriate' => 0.00],
                    'explanation' => 'No issues detected.',
                    'model'       => 'mock-moderation-v1',
                    'timestamp'   => $now->toIso8601String(),
                ]),
                'ai_enrichment'        => json_encode([
                    'model_evaluation' => [
                        'summary'    => 'Premium driver with strong resale value.',
                        'features'   => ['titanium', 'lightweight'],
                        'confidence' => 0.92,
                    ],
                    'estimated_market_value' => [
                        'value'               => 315.00,
                        'currency'            => 'USD',
                        'confidence_interval' => [280.0, 350.0],
                        'confidence'          => 0.88,
                        'basis'               => 'condition + category heuristic',
                    ],
                    'model'        => 'mock-enrichment-v1',
                    'generated_at' => $now->toIso8601String(),
                ]),
                'ai_enrichment_status' => 'succeeded',
                'cancelled_at'         => null,
            ],

            // 2) Approved + visible, sin end_date (null)
            [
                'user_id'              => $bob,
                'category_id'          => $categoryIds['Putters'],
                'title'                => 'Classic Blade Putter',
                'price'                => 149.50,
                'condition'            => 'Used',
                'description'          => 'Reliable blade putter, great feel on the greens for any player.',
                'end_date'             => null,
                'moderation_status'    => 'approved',
                'moderation_result'    => json_encode([
                    'status'      => 'approved',
                    'labels'      => [],
                    'scores'      => ['spam' => 0.02, 'inappropriate' => 0.00],
                    'explanation' => 'No issues detected.',
                    'model'       => 'mock-moderation-v1',
                    'timestamp'   => $now->toIso8601String(),
                ]),
                'ai_enrichment'        => json_encode([
                    'model_evaluation' => [
                        'summary'    => 'Solid mid-range putter.',
                        'features'   => ['blade', 'classic'],
                        'confidence' => 0.81,
                    ],
                    'estimated_market_value' => [
                        'value'               => 140.00,
                        'currency'            => 'USD',
                        'confidence_interval' => [120.0, 165.0],
                        'confidence'          => 0.79,
                        'basis'               => 'condition + category heuristic',
                    ],
                    'model'        => 'mock-enrichment-v1',
                    'generated_at' => $now->toIso8601String(),
                ]),
                'ai_enrichment_status' => 'succeeded',
                'cancelled_at'         => null,
            ],

            // 3) Pending moderation + enrichment pending (no visible)
            [
                'user_id'              => $alice,
                'category_id'          => $categoryIds['Irons'],
                'title'                => 'Forged Iron Set',
                'price'                => 520.00,
                'condition'            => 'New',
                'description'          => 'Brand new forged iron set, full range from four to pitching wedge.',
                'end_date'             => $now->copy()->addDays(60)->toDateString(),
                'moderation_status'    => 'pending',
                'moderation_result'    => null,
                'ai_enrichment'        => null,
                'ai_enrichment_status' => 'pending',
                'cancelled_at'         => null,
            ],

            // 4) Rejected moderation + enrichment failed (no visible)
            [
                'user_id'              => $bob,
                'category_id'          => $categoryIds['Wedges'],
                'title'                => 'Sand Wedge Special',
                'price'                => 89.00,
                'condition'            => 'Refurbished',
                'description'          => 'Refurbished sand wedge with regripped handle and clean grooves.',
                'end_date'             => $now->copy()->addDays(15)->toDateString(),
                'moderation_status'    => 'rejected',
                'moderation_result'    => json_encode([
                    'status'      => 'rejected',
                    'labels'      => ['suspicious_url'],
                    'scores'      => ['spam' => 0.74, 'inappropriate' => 0.10],
                    'explanation' => 'Suspicious content detected.',
                    'model'       => 'mock-moderation-v1',
                    'timestamp'   => $now->toIso8601String(),
                ]),
                'ai_enrichment'        => null,
                'ai_enrichment_status' => 'failed',
                'cancelled_at'         => null,
            ],

            // 5) Approved pero cancelado (soft-delete, no visible)
            [
                'user_id'              => $alice,
                'category_id'          => $categoryIds['Woods'],
                'title'                => 'Fairway Wood Three',
                'price'                => 199.99,
                'condition'            => 'Used',
                'description'          => 'Three fairway wood with headcover, smooth swing and good distance.',
                'end_date'             => $now->copy()->addDays(45)->toDateString(),
                'moderation_status'    => 'approved',
                'moderation_result'    => json_encode([
                    'status'      => 'approved',
                    'labels'      => [],
                    'scores'      => ['spam' => 0.01, 'inappropriate' => 0.00],
                    'explanation' => 'No issues detected.',
                    'model'       => 'mock-moderation-v1',
                    'timestamp'   => $now->toIso8601String(),
                ]),
                'ai_enrichment'        => json_encode([
                    'model_evaluation' => [
                        'summary'    => 'Good condition fairway wood.',
                        'features'   => ['fairway', 'headcover'],
                        'confidence' => 0.85,
                    ],
                    'estimated_market_value' => [
                        'value'               => 190.00,
                        'currency'            => 'USD',
                        'confidence_interval' => [165.0, 215.0],
                        'confidence'          => 0.83,
                        'basis'               => 'condition + category heuristic',
                    ],
                    'model'        => 'mock-enrichment-v1',
                    'generated_at' => $now->toIso8601String(),
                ]),
                'ai_enrichment_status' => 'succeeded',
                'cancelled_at'         => $now->copy()->subDays(2),
            ],
        ];

        foreach ($rows as $row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            DB::table('listings')->insert($row);
        }
    }
}