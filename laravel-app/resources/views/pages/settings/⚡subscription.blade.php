<?php

use App\Enums\SubscriptionTier;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Subscription settings')] class extends Component {
    public function changeTier(string $tier): void
    {
        $parsed = SubscriptionTier::tryFrom($tier);

        abort_if($parsed === null, 422);

        Auth::user()->upgradeTier($parsed);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Subscription settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Subscription')" :subheading="__('Choose the plan that fits your needs.')">
        <div class="space-y-3">
            @foreach (App\Enums\SubscriptionTier::cases() as $tier)
                @php $active = Auth::user()->subscription_tier === $tier; @endphp

                <div class="flex items-center justify-between rounded-xl border p-4 transition-colors {{ $active ? 'border-blue-500 bg-blue-50 dark:border-blue-500 dark:bg-blue-950/30' : 'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900' }}">
                    <div>
                        <div class="flex items-center gap-2">
                            <flux:text class="font-semibold">{{ $tier->label() }}</flux:text>
                            @if ($active)
                                <flux:badge color="blue" size="sm">Current plan</flux:badge>
                            @endif
                        </div>
                        <flux:text class="mt-0.5 text-sm text-zinc-500">
                            {{ $tier->storageLimitLabel() }} storage · {{ number_format($tier->monthlyUploadLimit()) }} uploads/month
                        </flux:text>
                    </div>

                    @if (! $active)
                        <flux:button
                            wire:click="changeTier('{{ $tier->value }}')"
                            wire:loading.attr="disabled"
                            size="sm"
                            variant="primary"
                        >
                            Select
                        </flux:button>
                    @endif
                </div>
            @endforeach
        </div>
    </x-pages::settings.layout>
</section>
