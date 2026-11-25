{{--
    মাসের হাজিরা — কে কতদিন, আর কতদিন বেতন থেকে কাটা যাবে।

    "কাটা যাবে" কলামটাই এই পর্দার আসল কাজ: বেতনের রান ওই সংখ্যাটাই
    ব্যবহার করে, তাই মাস শেষের আগে সেটা দেখে নেওয়ার একটা জায়গা লাগে।
--}}
@php
    // ⭐ এক সারির মাথা কেবল তিন ABOS রূপে ([[AbosHead]]); বাকি রূপ আগের মতো
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = ! $abosHead ? null : \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::month('month', __('hr::field.month'), $month->format('Y-m')),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('hr::action.monthly_sheet') }}</x-slot:title>
    @php \App\Core\Support\ReportPaper::describe((string) __('hr::action.monthly_sheet'), $month->format('m/Y')); @endphp

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header
            :title="__('hr::action.monthly_sheet')"
            :subtitle="$month->translatedFormat('F Y')">
            <x-slot:actions>
                {{-- ⭐ প্রিন্ট ভিউ — হাজিরার কাগজ নতুন ট্যাবে (মালিক, ২৩ নভেম্বর ২০২৬; [[ReportPaper]]) --}}
                <x-ui.print-view-button />
                <x-ui.button :href="route('hr.attendance.index')">
                    {{ __('hr::menu.attendance') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>
    @endunless

    @unless ($affectsSalary || $abosHead)
        {{-- সুইচ বন্ধ থাকলে সংখ্যাগুলো কেবল তথ্য — বেতনে যাচ্ছে না।
             না বললে কেউ ভাবতেন কাটা হচ্ছে, অথচ হচ্ছে না। --}}
        <div class="mb-4 rounded-(--radius-field) bg-(--color-surface-hover) px-3 py-2 text-2xs
                    text-(--color-ink-muted)">
            {{ __('hr::message.attendance_off_note') }}
        </div>
    @endunless

    {{-- ⭐ রিপোর্ট: মাস বাছলেই চলে না — "দেখান" বা Enter (মালিক, ২৩ নভেম্বর ২০২৬) --}}
    @unless ($abosHead)
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2" data-report>
        <label class="block">
            <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                         text-(--color-ink-muted)">{{ __('hr::field.month') }}</span>
            <x-ui.month name="month" :value="$month->format('Y-m')"
                        class="rounded-(--radius-field) border border-(--color-border)
                               bg-(--color-surface-app) px-2 py-1.5 text-sm" />
        </label>

        <x-ui.button type="submit" tone="secondary">{{ __('core.action.show') }}</x-ui.button>
    </form>
    @endunless

    <x-ui.filter-layout :filters="$facetPanel ?? \App\Core\ListFilter\Facets::panel([])" form="list-form" :report="true">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        @if ($abosHead)
            <form id="list-form" method="GET" class="contents" data-report>
                <x-ui.toolbar :title="__('hr::action.monthly_sheet')" :count="$month->translatedFormat('F Y')" :info="$affectsSalary ? null : __('hr::message.attendance_off_note')" :search="false" :report="true"
                              :share="false" :print="false" :facets="$facetPanel">
                    <x-slot:actions>
                        <x-ui.print-view-button />
                        <x-ui.button :href="route('hr.attendance.index')">{{ __('hr::menu.attendance') }}</x-ui.button>
                    </x-slot:actions>
                </x-ui.toolbar>
            </form>
        @endif
        <x-ui.table
            :empty="__('hr::message.no_employees_for_day')"
            :rows="$rows"
            :columns="[
                ['key' => 'code', 'label' => __('hr::field.code'), 'width' => '8rem',
                 'render' => fn ($r) => $r['employee']->code],
                ['key' => 'name', 'label' => __('hr::field.name'),
                 'render' => fn ($r) => $r['employee']->name()],
                ['key' => 'present', 'label' => __('hr::field.present'), 'numeric' => true, 'width' => '7rem',
                 'render' => fn ($r) => $r['summary']['present']],
                ['key' => 'absent', 'label' => __('hr::field.absent'), 'numeric' => true, 'width' => '7rem',
                 'render' => fn ($r) => $r['summary']['absent']],
                ['key' => 'leave', 'label' => __('hr::kind.leave'), 'numeric' => true, 'width' => '7rem',
                 'render' => fn ($r) => $r['summary']['leave']],
                ['key' => 'late', 'label' => __('hr::field.is_late'), 'numeric' => true, 'width' => '6rem',
                 'render' => fn ($r) => $r['summary']['late']],
                ['key' => 'marked', 'label' => __('hr::field.marked'), 'numeric' => true, 'width' => '7rem',
                 'render' => fn ($r) => $r['summary']['marked']],
                ['key' => 'unpaid', 'label' => __('hr::field.unpaid_days'), 'numeric' => true, 'width' => '8rem',
                 'render' => fn ($r) => $r['summary']['unpaid']],
            ]" />

        <x-ui.pager :rows="$rows" />
        <x-ui.list-totals :rows="$rows" />
    </div>
    </x-ui.filter-layout>
</x-layouts.app>
