<?php

declare(strict_types=1);

namespace JamesGifford\Hold\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use JamesGifford\Hold\Hold;
use JamesGifford\Hold\HoldSignupContext;
use JamesGifford\Hold\Models\HoldSignup;

/**
 * @extends Factory<HoldSignup>
 */
class HoldSignupFactory extends Factory
{
    /**
     * Build the configured HoldSignup model, so the published app model
     * (App\Models\HoldSignup) and the package model both factory correctly.
     */
    public function modelName(): string
    {
        return Hold::signups()->getModel()::class;
    }

    public function definition(): array
    {
        // Verified by default (matching requested_at): most tests exercise
        // behavior downstream of a confirmed signup, and verification itself
        // didn't exist before 1.4.0 — defaulting unverified would silently
        // change the meaning of every pre-existing test. Use unverified()
        // for the cases that specifically need a pending row.
        $requestedAt = Carbon::now();

        return [
            'email' => $this->faker->unique()->safeEmail(),
            'context' => $this->faker->randomElement(HoldSignupContext::cases()),
            'requested_at' => $requestedAt,
            'verified_at' => $requestedAt,
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'notified_at' => null,
            'unsubscribed_at' => null,
        ];
    }

    public function prelaunch(): static
    {
        return $this->state(['context' => HoldSignupContext::Prelaunch]);
    }

    public function maintenance(): static
    {
        return $this->state(['context' => HoldSignupContext::Maintenance]);
    }

    public function notified(): static
    {
        return $this->state(['notified_at' => Carbon::now()]);
    }

    public function unsubscribed(): static
    {
        return $this->state(['unsubscribed_at' => Carbon::now()]);
    }

    public function unverified(): static
    {
        return $this->state(['verified_at' => null]);
    }
}
