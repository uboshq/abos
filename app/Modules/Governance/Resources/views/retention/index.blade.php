{{--
    কাগজ সংরক্ষণ নীতি — কোনটা কতদিন থাকে।

    ── কেন পর্দাটা নিয়ম বসায় না ────────────────────────────────────────
    এখানে "৬ বছর" লিখে রাখা যেত। ⛔ কিন্তু লেখা এক আর ঘটা আরেক হলে নীতিটাই
    সবচেয়ে বিপজ্জনক কাগজ — নিরীক্ষকের সামনে ওটা দেখিয়ে বলা হত "আমাদের নীতি
    আছে", অথচ সারিগুলো কবে মুছেছে কেউ জানত না।

    ⭐ তাই প্রতিটা সারিতে তিনটা কথা: কী, কতদিন, আর **কে সেটা মানায়**।
--}}
@php
    use App\Modules\Governance\Services\WhatIsKeptHowLong;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('governance::retention.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('governance::retention.title')"
                          :subtitle="__('governance::retention.note', ['years' => $lawYears])" />
    </x-slot:header>

    {{-- ⚠️ সবচেয়ে দুর্বল জায়গাটা উপরে, কারণ দুইটা জিনিস এক মনে হয়:
         খাতা চিরকাল থাকে, কিন্তু ব্যাকআপ ত্রিশ দিনের। ডাটাবেস হারালে
         ছয় বছরের খাতা নয়, ত্রিশ দিনের পিছন পর্যন্তই ফেরানো যায়। --}}
    <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-warning)
                    bg-(--color-badge-warning-bg) p-4 text-sm text-(--color-badge-warning-ink)">
        {{ __('governance::retention.backup_gap', ['days' => $backupDays, 'years' => $lawYears]) }}
    </section>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <table class="ui-list table-cards w-full border-collapse">
            <thead>
                <tr>
                    <th class="text-start">{{ __('governance::retention.what') }}</th>
                    <th class="text-start">{{ __('governance::retention.how_long') }}</th>
                    <th class="text-start">{{ __('governance::retention.enforced_by') }}</th>
                    <th class="text-end">{{ __('governance::retention.rows_now') }}</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($rows as $row)
                    <tr data-kept="{{ $row['key'] }}">
                        <td>
                            @if ($row['route'])
                                <a href="{{ \App\Modules\Finance\Support\FinancePlan::urlFor($row['route']) }}"
                                   class="text-(--color-link) hover:underline">{{ __('governance::retention.kind.'.$row['key']) }}</a>
                            @else
                                {{ __('governance::retention.kind.'.$row['key']) }}
                            @endif
                        </td>

                        <td>
                            @if ($row['kept'] === WhatIsKeptHowLong::FOREVER)
                                <span class="rounded-(--radius-pill) bg-(--color-badge-success-bg) px-2 py-0.5
                                             text-2xs text-(--color-badge-success-ink)">
                                    {{ __('governance::retention.forever') }}
                                </span>
                            @else
                                <span class="rounded-(--radius-pill) bg-(--color-badge-pending-bg) px-2 py-0.5
                                             text-2xs text-(--color-badge-pending-ink)">
                                    {{ trans_choice('governance::retention.days', $row['days'], ['count' => $row['days']]) }}
                                </span>
                            @endif
                        </td>

                        <td class="text-2xs text-(--color-ink-muted)">
                            {{ __('governance::retention.by_'.$row['by']) }}
                        </td>

                        <td class="num text-end">{{ $row['rows'] === null ? '—' : $row['rows'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-2xs text-(--color-ink-muted)">{{ __('governance::retention.footer') }}</p>
</x-layouts.app>
