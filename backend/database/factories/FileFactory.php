<?php

namespace Database\Factories;

use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<File>
 */
class FileFactory extends Factory
{
    /**
     * Define the model's default state: an active, unprotected file.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $extension = fake()->fileExtension();

        return [
            'user_id' => User::factory(),
            'token' => Str::random(22),
            'original_name' => fake()->word().'.'.$extension,
            'stored_path' => now()->format('Y/m/d').'/'.fake()->uuid(),
            'mime_type' => fake()->mimeType(),
            'size' => fake()->numberBetween(1024, 10_000_000),
            'password' => null,
            'expires_at' => now()->addDays(7),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function protected(string $password = 'secret123'): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => Hash::make($password),
        ]);
    }
}
