<?php

namespace Database\Factories;

use App\Models\News;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<News> */
class NewsFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => 'Pilot community update', 'body' => 'This is a test civic-reporting pilot, not an official government service.', 'published_at' => now()];
    }
}
