<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            ['name' => 'Alice Walker', 'email' => 'alice@golf.test'],
            ['name' => 'Bob Stone',    'email' => 'bob@golf.test'],
        ];

        foreach ($users as $u) {
            DB::table('users')->updateOrInsert(
                ['email' => $u['email']],
                [
                    'name'       => $u['name'],
                    'password'   => Hash::make('password'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}