<?php

namespace Database\Seeders;

use App\Models\AuthorityUnit;
use App\Models\News;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('moshkelti.categories') as $category => $name) {
            AuthorityUnit::firstOrCreate(['municipality' => config('moshkelti.municipality'), 'category' => $category, 'governorate' => 'Amman'],
                ['name' => 'Simulated Amman — '.$name, 'active' => true, 'simulated' => true]);
        }
        News::firstOrCreate(['title' => 'Welcome to Moshkelti / أهلاً بكم في مشكلتي'],
            ['body' => 'This is an independent pilot. A human reviews every civic issue before simulated authority delivery. Never use this app for emergencies.',
                'published_at' => now()]);
    }
}
