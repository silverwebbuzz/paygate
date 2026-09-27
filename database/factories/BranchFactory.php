<?php

namespace Database\Factories;

use App\Domain\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

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
