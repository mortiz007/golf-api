<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class TokenSeeder extends Seeder
{
    /**
     * Pre-seeds Sanctum tokens (no register/login endpoints).
     * Plain-text tokens are printed once for local API testing.
     */

    public function run(): void
    {
        foreach (User::all() as $user) {
            // Avoid duplicating tokens on re-seed.
            $user->tokens()->where('name', 'seed-token')->delete();

            $token = $user->createToken('seed-token')->plainTextToken;

            $this->command->info("Token for {$user->email}: {$token}");
        }
    }
}