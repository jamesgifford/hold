<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use JamesGifford\Hold\Models\Signup;
use JamesGifford\Hold\SignupContext;

/**
 * @extends Factory<Signup>
 */
class SignupFactory extends Factory
{
    protected $model = Signup::class;

    public function definition(): array
    {
        return [
            'email' => $this->faker->unique()->safeEmail(),
            'context' => $this->faker->randomElement(SignupContext::cases()),
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'notified_at' => null,
            'unsubscribed_at' => null,
        ];
    }

    public function prelaunch(): static
    {
        return $this->state(['context' => SignupContext::Prelaunch]);
    }

    public function maintenance(): static
    {
        return $this->state(['context' => SignupContext::Maintenance]);
    }

    public function notified(): static
    {
        return $this->state(['notified_at' => Carbon::now()]);
    }

    public function unsubscribed(): static
    {
        return $this->state(['unsubscribed_at' => Carbon::now()]);
    }
}
