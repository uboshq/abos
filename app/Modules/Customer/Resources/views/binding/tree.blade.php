{{--
    ⭐ কে কার উপরে — SM · TSM · RSM · DSM (মালিকের উত্তর ৩, ২৬ সেপ্টেম্বর ২০২৬)।

    ⓘ প্রত্যেকে নিজের নিচের গোটা গাছের বাঁধা ডিলার দেখেন — পাশের সংখ্যাটা ঠিক সেটাই
    ([[DealerScope::reachOf()]])। প্রতি লাইনে একজন, নিচের লোক ভিতরে সরে বসে।
--}}
@php
    $card = 'rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4';
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('customer::binding.tree') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('customer::binding.tree')" :subtitle="__('customer::binding.tree_hint')">
            <x-slot:actions>
                <a href="{{ route('customer.binding.index') }}" class="text-sm text-(--color-brand-500) underline-offset-2 hover:underline">
                    {{ __('customer::binding.title') }}
                </a>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <section data-boxed data-tree class="{{ $card }}">
        @if ($roots === [])
            <p class="text-sm text-(--color-ink-muted)">{{ __('customer::binding.tree_empty') }}</p>
        @else
            @include('customer::binding.partials.branch', ['ids' => $roots, 'depth' => 0, 'seen' => []])
        @endif
    </section>
</x-layouts.app>
