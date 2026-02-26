<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->name();
        $usernameBase = str()->slug($name, '_');

        return [
            'name' => $name,
            'chef_name' => null,
            'username' => $usernameBase.'_'.fake()->unique()->numberBetween(1000, 9999),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'date_of_birth' => null,
            'address' => null,
            'referral_code' => strtoupper(fake()->unique()->bothify('GRV#####')),
            'referred_by_user_id' => null,
            'role' => \App\Models\User::ROLE_USER,
            'chef_niche_id' => null,
            'profile_picture' => null,
            'wallet_balance' => 0,
            'onboarding_completed' => false,
            'email_verified_at' => now(),
            'password' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
