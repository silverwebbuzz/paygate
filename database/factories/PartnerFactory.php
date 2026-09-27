<?php

namespace Database\Factories;

use App\Domain\Partner\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    protected $model = Partner::class;

    public function definition(): array
    {
        return [
            'code' => 'P'.fake()->unique()->numerify('#####'),
            'name' => fake()->company(),
            'email' => fake()->companyEmail(),
            'website_url' => 'https://'.fake()->domainName(),
            'status' => 'active',
            'is_payin_enabled' => true,
            'is_payout_enabled' => true,
        ];
    }
}
