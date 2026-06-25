<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Drivers', 'Woods', 'Hybrids', 'Driving Irons',
            'Irons', 'Wedges', 'Putters',
        ];

        foreach ($categories as $name) {
            DB::table('categories')->updateOrInsert(
                ['name' => $name],
                ['created_at' => now()]
            );
        }
    }
}