<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ListingSeeder extends Seeder
{
    /**
     * Seeds 10 listings covering moderation/enrichment states, visibility,
     * expired listings, filter-friendly categories/prices, and search terms.
     */
    public function run(): void
    {
        $userIds = DB::table('users')->pluck('id', 'email');
        $categoryIds = DB::table('categories')->pluck('id', 'name');

        $alice = $userIds['alice@golf.test'];
        $bob = $userIds['bob@golf.test'];

        $now = now();

        $rows = [
            // 1) Approved + enriched + visible (end_date futuro)
            [
                'user_id' => $alice,
                'category_id' => $categoryIds['Drivers'],
                'title' => 'Titanium Driver Pro',
                'price' => 299.99,
                'condition' => 'Like New',
                'description' => 'Lightweight titanium driver in excellent condition, barely used.',
                'end_date' => $now->copy()->addDays(30)->toDateString(),
                'moderation_status' => 'approved',
                'moderation_result' => json_encode([
                    'status' => 'approved',
                    'labels' => [],
                    'scores' => ['spam' => 0.01, 'inappropriate' => 0.00],
                    'explanation' => 'No issues detected.',
                    'model' => 'mock-moderation-v1',
                    'timestamp' => $now->toIso8601String(),
                ]),
                'ai_enrichment' => json_encode([
                    'model_evaluation' => [
                        'summary' => 'Premium driver with strong resale value.',
                        'features' => ['titanium', 'lightweight'],
                        'confidence' => 0.92,
                    ],
                    'estimated_market_value' => [
                        'value' => 315.00,
                        'currency' => 'USD',
                        'confidence_interval' => [280.0, 350.0],
                        'confidence' => 0.88,
                        'basis' => 'condition + category heuristic',
                    ],
                    'model' => 'mock-enrichment-v1',
                    'generated_at' => $now->toIso8601String(),
                ]),
                'ai_enrichment_status' => 'succeeded',
                'cancelled_at' => null,
            ],

            // 2) Approved + visible, sin end_date (null)
            [
                'user_id' => $bob,
                'category_id' => $categoryIds['Putters'],
                'title' => 'Classic Blade Putter',
                'price' => 149.50,
                'condition' => 'Used',
                'description' => 'Reliable blade putter, great feel on the greens for any player.',
                'end_date' => null,
                'moderation_status' => 'approved',
                'moderation_result' => json_encode([
                    'status' => 'approved',
                    'labels' => [],
                    'scores' => ['spam' => 0.02, 'inappropriate' => 0.00],
                    'explanation' => 'No issues detected.',
                    'model' => 'mock-moderation-v1',
                    'timestamp' => $now->toIso8601String(),
                ]),
                'ai_enrichment' => json_encode([
                    'model_evaluation' => [
                        'summary' => 'Solid mid-range putter.',
                        'features' => ['blade', 'classic'],
                        'confidence' => 0.81,
                    ],
                    'estimated_market_value' => [
                        'value' => 140.00,
                        'currency' => 'USD',
                        'confidence_interval' => [120.0, 165.0],
                        'confidence' => 0.79,
                        'basis' => 'condition + category heuristic',
                    ],
                    'model' => 'mock-enrichment-v1',
                    'generated_at' => $now->toIso8601String(),
                ]),
                'ai_enrichment_status' => 'succeeded',
                'cancelled_at' => null,
            ],

            // 3) Pending moderation + enrichment pending (no visible)
            [
                'user_id' => $alice,
                'category_id' => $categoryIds['Irons'],
                'title' => 'Forged Iron Set',
                'price' => 520.00,
                'condition' => 'New',
                'description' => 'Brand new forged iron set, full range from four to pitching wedge.',
                'end_date' => $now->copy()->addDays(60)->toDateString(),
                'moderation_status' => 'pending',
                'moderation_result' => null,
                'ai_enrichment' => null,
                'ai_enrichment_status' => 'pending',
                'cancelled_at' => null,
            ],

            // 4) Rejected moderation + enrichment failed (no visible)
            [
                'user_id' => $bob,
                'category_id' => $categoryIds['Wedges'],
                'title' => 'Sand Wedge Special',
                'price' => 89.00,
                'condition' => 'Refurbished',
                'description' => 'Refurbished sand wedge with regripped handle and clean grooves.',
                'end_date' => $now->copy()->addDays(15)->toDateString(),
                'moderation_status' => 'rejected',
                'moderation_result' => json_encode([
                    'status' => 'rejected',
                    'labels' => ['suspicious_url'],
                    'scores' => ['spam' => 0.74, 'inappropriate' => 0.10],
                    'explanation' => 'Suspicious content detected.',
                    'model' => 'mock-moderation-v1',
                    'timestamp' => $now->toIso8601String(),
                ]),
                'ai_enrichment' => null,
                'ai_enrichment_status' => 'failed',
                'cancelled_at' => null,
            ],

            // 5) Approved pero cancelado (soft-delete, no visible)
            [
                'user_id' => $alice,
                'category_id' => $categoryIds['Woods'],
                'title' => 'Fairway Wood Three',
                'price' => 199.99,
                'condition' => 'Used',
                'description' => 'Three fairway wood with headcover, smooth swing and good distance.',
                'end_date' => $now->copy()->addDays(45)->toDateString(),
                'moderation_status' => 'approved',
                'moderation_result' => json_encode([
                    'status' => 'approved',
                    'labels' => [],
                    'scores' => ['spam' => 0.01, 'inappropriate' => 0.00],
                    'explanation' => 'No issues detected.',
                    'model' => 'mock-moderation-v1',
                    'timestamp' => $now->toIso8601String(),
                ]),
                'ai_enrichment' => json_encode([
                    'model_evaluation' => [
                        'summary' => 'Good condition fairway wood.',
                        'features' => ['fairway', 'headcover'],
                        'confidence' => 0.85,
                    ],
                    'estimated_market_value' => [
                        'value' => 190.00,
                        'currency' => 'USD',
                        'confidence_interval' => [165.0, 215.0],
                        'confidence' => 0.83,
                        'basis' => 'condition + category heuristic',
                    ],
                    'model' => 'mock-enrichment-v1',
                    'generated_at' => $now->toIso8601String(),
                ]),
                'ai_enrichment_status' => 'succeeded',
                'cancelled_at' => $now->copy()->subDays(2),
            ],

            // 6) Approved + enriched pero expirado (no visible por defecto)
            [
                'user_id' => $bob,
                'category_id' => $categoryIds['Hybrids'],
                'title' => 'Adjustable Hybrid Club',
                'price' => 175.00,
                'condition' => 'Used',
                'description' => 'Versatile hybrid club with adjustable loft, sold as-is after season end.',
                'end_date' => $now->copy()->subDays(3)->toDateString(),
                'moderation_status' => 'approved',
                'moderation_result' => json_encode([
                    'status' => 'approved',
                    'labels' => [],
                    'scores' => ['spam' => 0.01, 'inappropriate' => 0.00],
                    'explanation' => 'No issues detected.',
                    'model' => 'mock-moderation-v1',
                    'timestamp' => $now->toIso8601String(),
                ]),
                'ai_enrichment' => json_encode([
                    'model_evaluation' => [
                        'summary' => 'Reliable used hybrid for mid handicaps.',
                        'features' => ['adjustable', 'hybrid'],
                        'confidence' => 0.84,
                    ],
                    'estimated_market_value' => [
                        'value' => 168.00,
                        'currency' => 'USD',
                        'confidence_interval' => [150.0, 190.0],
                        'confidence' => 0.80,
                        'basis' => 'condition + category heuristic',
                    ],
                    'model' => 'mock-enrichment-v1',
                    'generated_at' => $now->toIso8601String(),
                ]),
                'ai_enrichment_status' => 'succeeded',
                'cancelled_at' => null,
                'created_at' => $now->copy()->subDays(5),
                'updated_at' => $now->copy()->subDays(5),
            ],

            // 7) Approved + enrichment pending (visible, ai_enrichment null)
            [
                'user_id' => $alice,
                'category_id' => $categoryIds['Driving Irons'],
                'title' => 'Compact Driving Iron',
                'price' => 62.00,
                'condition' => 'Used',
                'description' => 'Compact driving iron with clean grooves and a neutral flight bias.',
                'end_date' => null,
                'moderation_status' => 'approved',
                'moderation_result' => json_encode([
                    'status' => 'approved',
                    'labels' => [],
                    'scores' => ['spam' => 0.01, 'inappropriate' => 0.00],
                    'explanation' => 'No issues detected.',
                    'model' => 'mock-moderation-v1',
                    'timestamp' => $now->toIso8601String(),
                ]),
                'ai_enrichment' => null,
                'ai_enrichment_status' => 'pending',
                'cancelled_at' => null,
                'created_at' => $now->copy()->subDays(4),
                'updated_at' => $now->copy()->subDays(4),
            ],

            // 8) Approved + enrichment failed (visible, ai_enrichment null)
            [
                'user_id' => $bob,
                'category_id' => $categoryIds['Wedges'],
                'title' => 'Lob Wedge Pro',
                'price' => 110.00,
                'condition' => 'Refurbished',
                'description' => 'High-loft lob wedge refurbished with fresh grip and polished face.',
                'end_date' => $now->copy()->addDays(20)->toDateString(),
                'moderation_status' => 'approved',
                'moderation_result' => json_encode([
                    'status' => 'approved',
                    'labels' => [],
                    'scores' => ['spam' => 0.02, 'inappropriate' => 0.00],
                    'explanation' => 'No issues detected.',
                    'model' => 'mock-moderation-v1',
                    'timestamp' => $now->toIso8601String(),
                ]),
                'ai_enrichment' => null,
                'ai_enrichment_status' => 'failed',
                'cancelled_at' => null,
                'created_at' => $now->copy()->subDays(3),
                'updated_at' => $now->copy()->subDays(3),
            ],

            // 9) Premium New hybrid (filtros category/condition/price, show_all order)
            [
                'user_id' => $alice,
                'category_id' => $categoryIds['Hybrids'],
                'title' => 'Rescue Hybrid Max',
                'price' => 899.00,
                'condition' => 'New',
                'description' => 'Brand-new rescue hybrid with premium shaft and headcover included.',
                'end_date' => $now->copy()->addDays(90)->toDateString(),
                'moderation_status' => 'approved',
                'moderation_result' => json_encode([
                    'status' => 'approved',
                    'labels' => [],
                    'scores' => ['spam' => 0.00, 'inappropriate' => 0.00],
                    'explanation' => 'No issues detected.',
                    'model' => 'mock-moderation-v1',
                    'timestamp' => $now->toIso8601String(),
                ]),
                'ai_enrichment' => json_encode([
                    'model_evaluation' => [
                        'summary' => 'Top-tier new hybrid with strong demand.',
                        'features' => ['rescue', 'premium-shaft'],
                        'confidence' => 0.95,
                    ],
                    'estimated_market_value' => [
                        'value' => 920.00,
                        'currency' => 'USD',
                        'confidence_interval' => [850.0, 990.0],
                        'confidence' => 0.91,
                        'basis' => 'condition + category heuristic',
                    ],
                    'model' => 'mock-enrichment-v1',
                    'generated_at' => $now->toIso8601String(),
                ]),
                'ai_enrichment_status' => 'succeeded',
                'cancelled_at' => null,
                'created_at' => $now->copy()->subDays(2),
                'updated_at' => $now->copy()->subDays(2),
            ],

            // 10) Búsqueda + precio bajo (q=utility, owner Bob)
            [
                'user_id' => $bob,
                'category_id' => $categoryIds['Driving Irons'],
                'title' => 'Utility Driving Iron',
                'price' => 45.00,
                'condition' => 'Used',
                'description' => 'Perfect utility iron for tight lies and search demo queries.',
                'end_date' => $now->copy()->addDays(14)->toDateString(),
                'moderation_status' => 'approved',
                'moderation_result' => json_encode([
                    'status' => 'approved',
                    'labels' => [],
                    'scores' => ['spam' => 0.01, 'inappropriate' => 0.00],
                    'explanation' => 'No issues detected.',
                    'model' => 'mock-moderation-v1',
                    'timestamp' => $now->toIso8601String(),
                ]),
                'ai_enrichment' => json_encode([
                    'model_evaluation' => [
                        'summary' => 'Budget-friendly utility iron.',
                        'features' => ['utility', 'driving-iron'],
                        'confidence' => 0.78,
                    ],
                    'estimated_market_value' => [
                        'value' => 42.00,
                        'currency' => 'USD',
                        'confidence_interval' => [35.0, 50.0],
                        'confidence' => 0.76,
                        'basis' => 'condition + category heuristic',
                    ],
                    'model' => 'mock-enrichment-v1',
                    'generated_at' => $now->toIso8601String(),
                ]),
                'ai_enrichment_status' => 'succeeded',
                'cancelled_at' => null,
                'created_at' => $now->copy()->subDays(1),
                'updated_at' => $now->copy()->subDays(1),
            ],
        ];

        foreach ($rows as $row) {
            $row['created_at'] = $row['created_at'] ?? $now;
            $row['updated_at'] = $row['updated_at'] ?? $now;
            DB::table('listings')->insert($row);
        }
    }
}
