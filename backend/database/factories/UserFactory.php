<?php

namespace Database\Factories;

use App\Enums\ProjectType;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake('de_DE')->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::Sales,
            'phone' => fake('de_DE')->phoneNumber(),
            'regions' => fake()->randomElements(['10', '12', '13', '14', '15', '16'], 2),
            'project_types' => fake()->randomElements(ProjectType::values(), 3),
            'max_open_leads' => 20,
            'is_active' => true,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => UserRole::Admin, 'regions' => [], 'project_types' => []]);
    }

    public function manager(): static
    {
        return $this->state(fn () => ['role' => UserRole::Manager, 'regions' => [], 'project_types' => []]);
    }

    public function sales(): static
    {
        return $this->state(fn () => ['role' => UserRole::Sales]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
