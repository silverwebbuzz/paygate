<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'B'.fake()->unique()->numerify('#####'),
            'name' => fake()->city().' branch',
            'status' => 'active',
            'is_deposit_enabled' => true,
            'is_withdrawal_enabled' => true,
        ];
    }
}
