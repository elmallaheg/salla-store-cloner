<?php

namespace Database\Factories;

use App\Models\SallaStore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory لإنشاء بيانات SallaStore تجريبية في الاختبارات.
 */
class SallaStoreFactory extends Factory
{
    protected $model = SallaStore::class;

    public function definition(): array
    {
        return [
            'store_id'        => $this->faker->unique()->randomNumber(6, true),
            'store_name'      => $this->faker->company() . ' متجر',
            'access_token'    => 'access_' . $this->faker->sha256(),
            'refresh_token'   => 'refresh_' . $this->faker->sha256(),
            'token_expires_at' => now()->addDays(14),
            'role'            => 'unassigned',
        ];
    }

    /** متجر بتوكن منتهي الصلاحية */
    public function expired(): static
    {
        return $this->state(['token_expires_at' => now()->subHour()]);
    }

    /** متجر مصدر */
    public function asSource(): static
    {
        return $this->state(['role' => 'source']);
    }

    /** متجر هدف */
    public function asTarget(): static
    {
        return $this->state(['role' => 'target']);
    }
}
