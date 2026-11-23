{{--
    রান্নাঘরের বোর্ড — এখন আর কী কী বানানো যাবে।

    ── কেন সংখ্যাটা "কেজি" নয়, "প্লেট" ─────────────────────────────────
    গুদামে কত কেজি চাল আছে সেটা কাউন্টারের প্রশ্ন নয়। কাউন্টারের একটাই
    প্রশ্ন: আর কয় প্লেট বেচা যাবে। হিসাবটা [[RecipeService::portionsPossible()]]-এ।

    ── কেন প্রতিটা সারিতে "কোনটা আটকাচ্ছে" ─────────────────────────────
    "বিরিয়ানি ৪" জানা অর্ধেক খবর। ওটা কেন চার, সেটা না জানলে কিছু করার
    থাকে না। "তেল ফুরাচ্ছে" জানলে তেল আনা যায়, আর তখন সংখ্যাটা একটা
    কাজের কথা হয়ে ওঠে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('restaurant::menu.kitchen_board') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('restaurant::menu.kitchen_board')"
                          :subtitle="__('restaurant::message.kitchen_note')" />
    </x-slot:header>

    {{--
        ── কেন পোলিং, আর কেন বিশ সেকেন্ড ────────────────────────────────
        "রিয়েল-টাইম" শুনলেই ওয়েবসকেট মনে আসে, আর ওটা এখানে ভুল উত্তর:
        একটা নতুন সার্ভার, একটা নতুন পোর্ট, ব্যর্থ হওয়ার একটা নতুন
        জায়গা — একটা ডিপোর জন্য, যেখানে সংখ্যাটা মিনিটে কয়েকবার বদলায়।

        বিশ সেকেন্ড কাউন্টারের জন্য যথেষ্ট, আর ইন্টারনেট এক মিনিট গেলে
        পাতাটা নিজেই আবার ধরে নেয়। শেষ কখন মিলিয়ে দেখা হয়েছে সেটা
        উপরে লেখা থাকে — নাহলে জমে থাকা একটা পুরনো সংখ্যা নতুনের মতো
        দেখাত, আর সেটা সংখ্যাটা না থাকার চেয়েও খারাপ।
    --}}
    <div x-data="kitchenBoard({
             at: @js(now()->format('H:i:s')),
             url: @js(route('restaurant.kitchen.refresh', request()->query())),
         })">

        {{-- ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের গুদামের ঘর ("সব" ছাড়া), একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]]) --}}
        @php
            $facetPanel = \App\Core\ListFilter\Facets::panel([
                \App\Core\ListFilter\Facets::one('warehouse', __('inventory::field.warehouse'),
                    $warehouses->mapWithKeys(fn ($w) => [(string) $w->id => $w->name()])->all(), (string) $warehouse?->id),
            ]);
        @endphp
        <x-ui.filter-layout :filters="$facetPanel" form="list-form">
            <form id="list-form" method="GET" class="contents">
                <x-ui.filter-bar :facets="$facetPanel" />
            </form>
        </x-ui.filter-layout>

        <div class="mb-3 flex flex-wrap items-center gap-3">
            <span class="flex-1"></span>

            <span class="text-2xs text-(--color-ink-muted)">
                {{ __('inventory::message.checked_at') }}
                <span class="num" x-text="at"></span>
            </span>

            <span x-show="stale" x-cloak
                  class="rounded-(--radius-field) bg-(--color-badge-warning-bg) px-2 py-0.5
                         text-2xs text-(--color-badge-warning-ink)">
                {{ __('inventory::message.not_refreshing') }}
            </span>

            <x-ui.button type="button" tone="secondary" icon="refresh" x-data @click="pull()">
                {{ __('core.action.refresh') }}
            </x-ui.button>
        </div>

        <x-ui.table
            :empty="__('inventory::message.no_recipes')"
            :rows="$dishes"
            :columns="[
                ['key' => 'dish', 'label' => __('inventory::field.dish'),
                 'render' => fn ($d) => $d['recipe']->product?->name() ?? '—'],
                ['key' => 'portions', 'label' => __('inventory::field.portions_possible'),
                 'numeric' => true, 'width' => '9rem',
                 'render' => fn ($d) => view('restaurant::kitchen.partials.portions', ['dish' => $d])],
                ['key' => 'limiting', 'label' => __('inventory::field.limiting'),
                 'render' => fn ($d) => $d['limiting']?->name() ?? '—'],
            ]" />
    </div>
</x-layouts.app>
