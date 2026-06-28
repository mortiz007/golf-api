<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class TokenSeeder extends Seeder
{
    /**
     * Pre-seeds Sanctum tokens (no register/login endpoints).
     * Plain-text tokens are deterministic for local API testing.
     */
    private const SEED_TOKENS = [
        'alice@golf.test' => 'golf-seed-token-alice',
        'bob@golf.test' => 'golf-seed-token-bob',
    ];

    public function run(): void
    {
        foreach (User::whereIn('email', array_keys(self::SEED_TOKENS))->get() as $user) {
            $plainTextToken = self::SEED_TOKENS[$user->email];

            $tokenRecord = $user->tokens()->updateOrCreate(
                ['name' => 'seed-token'],
                [
                    'token' => hash('sha256', $plainTextToken),
                    'abilities' => ['*'],
                ]
            );

            $bearerToken = $tokenRecord->getKey().'|'.$plainTextToken;

            $this->command->info("Token for {$user->email}: {$bearerToken}");
        }
    }
}
