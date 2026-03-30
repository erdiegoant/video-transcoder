<?php

use App\Enums\SubscriptionTier;
use App\Models\User;
use Livewire\Livewire;

// --- SubscriptionTier enum unit tests ---

test('SubscriptionTier storageLimitBytes returns correct values', function () {
    expect(SubscriptionTier::Free->storageLimitBytes())->toBe(524_288_000)
        ->and(SubscriptionTier::Pro->storageLimitBytes())->toBe(5_368_709_120)
        ->and(SubscriptionTier::Enterprise->storageLimitBytes())->toBe(53_687_091_200);
});

test('SubscriptionTier storageLimitLabel returns human-readable strings', function () {
    expect(SubscriptionTier::Free->storageLimitLabel())->toBe('500 MB')
        ->and(SubscriptionTier::Pro->storageLimitLabel())->toBe('5 GB')
        ->and(SubscriptionTier::Enterprise->storageLimitLabel())->toBe('50 GB');
});

test('SubscriptionTier monthlyUploadLimit returns correct values', function () {
    expect(SubscriptionTier::Free->monthlyUploadLimit())->toBe(20)
        ->and(SubscriptionTier::Pro->monthlyUploadLimit())->toBe(200)
        ->and(SubscriptionTier::Enterprise->monthlyUploadLimit())->toBe(2000);
});

// --- User model ---

test('upgradeTier updates subscription_tier and storage_limit_bytes', function () {
    $user = User::factory()->create(['subscription_tier' => 'free']);

    $user->upgradeTier(SubscriptionTier::Pro);

    expect($user->fresh()->subscription_tier)->toEqual(SubscriptionTier::Pro)
        ->and($user->fresh()->storage_limit_bytes)->toBe(SubscriptionTier::Pro->storageLimitBytes());
});

test('upgradeTier can downgrade back to free', function () {
    $user = User::factory()->create(['subscription_tier' => 'pro', 'storage_limit_bytes' => SubscriptionTier::Pro->storageLimitBytes()]);

    $user->upgradeTier(SubscriptionTier::Free);

    expect($user->fresh()->subscription_tier)->toEqual(SubscriptionTier::Free)
        ->and($user->fresh()->storage_limit_bytes)->toBe(SubscriptionTier::Free->storageLimitBytes());
});

// --- Livewire subscription component ---

test('subscription component renders with current tier highlighted', function () {
    $user = User::factory()->create(['subscription_tier' => 'free']);

    Livewire::actingAs($user)
        ->test('pages::dashboard.subscription')
        ->assertSee('Current plan')
        ->assertSee('Free')
        ->assertSee('Pro')
        ->assertSee('Enterprise');
});

test('user can change tier to pro via subscription component', function () {
    $user = User::factory()->create(['subscription_tier' => 'free']);

    Livewire::actingAs($user)
        ->test('pages::dashboard.subscription')
        ->call('changeTier', 'pro');

    expect($user->fresh()->subscription_tier)->toEqual(SubscriptionTier::Pro)
        ->and($user->fresh()->storage_limit_bytes)->toBe(SubscriptionTier::Pro->storageLimitBytes());
});

test('subscription component rejects invalid tier value', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::dashboard.subscription')
        ->call('changeTier', 'invalid-tier')
        ->assertStatus(422);
});
