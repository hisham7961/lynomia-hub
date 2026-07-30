<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // حارس: لا تكرار للبذرة على قاعدة فيها مستخدمون
        if (\App\Models\User::count() > 0) {
            $this->command?->warn('البذرة موجودة مسبقاً — تم التخطي.');

            return;
        }

        $this->call(CoreSeeder::class);
    }
}
