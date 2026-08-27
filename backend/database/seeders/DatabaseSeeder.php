<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Only canonical authorization data belongs in the default seed. The
        // sell catalog is explicit and is run with:
        // php artisan db:seed --class=SellCatalogSeeder --force
        $this->call(RolePermissionSeeder::class);
    }
}
