<?php

namespace Database\Factories;

use App\Models\Vertical;
use Illuminate\Database\Eloquent\Factories\Factory;

class VerticalFactory extends Factory
{
    protected $model = Vertical::class;

    public function definition(): array
    {
        return [
            'slug' => $this->faker->unique()->slug(2),
            'name' => $this->faker->word(),
        ];
    }
}
