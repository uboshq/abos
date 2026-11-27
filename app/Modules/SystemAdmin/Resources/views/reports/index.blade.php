{{--
    নির্ধারিত রিপোর্ট — যা নিজে তৈরি হয়ে সঠিক মানুষের কাছে পৌঁছায়।

    প্রতিটা সারি একটা সূচি; নিচে সাম্প্রতিক তৈরি হওয়া ফাইলগুলো, download-রুট
    দিয়ে (private ডিস্ক, অনুমতি যাচাই করা)।
--}}
@php
    // ⭐ এক সারির পুরো বার কেবল তিন ABOS রূপে ([[AbosHead]]); বাকি রূপ আগের মতো
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = ! $abosHead ? null : \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('frequency', __('system_admin::schedule.frequency'), __('system_admin::schedule.freq')),
        \App\Core\ListFilter\Facets::pick('state', __('system_admin::schedule.status_col'),
            ['on' => __('system_admin::schedule.active'), 'off' => __('system_admin::schedule.inactive')]),
    ]);
    $columns = [
        ['key' => 'report', 'label' => __('system_admin::schedule.report'), 'render' => fn ($s) => new \Illuminate\Support\HtmlString(
            '<span class="font-medium text-(--color-ink)">'.e($reportTitles[$s->report_key] ?? $s->report_key).'</span>'
            .'<span class="block text-2xs uppercase text-(--color-ink-muted)">'.e((string) $s->format).'</span>')],
        ['key' => 'frequency', 'label' => __('system_admin::schedule.frequency'), 'render' => fn ($s) => new \Illuminate\Support\HtmlString(e(__('system_admin::schedule.freq.'.$s->frequency)).' · '
            // ⓘ কোম্পানির সময়ের ছকে, অঙ্কের ঘরে (num) — পুরনো সারির মতোই (Mac run srt of 05d74158c)
            .'<span class="num">'.e($s->at_time ? \Illuminate\Support\Carbon::createFromFormat('H:i', substr((string) $s->at_time, 0, 5))->format(\App\Core\Support\DateFormat::time()) : '—').'</span>')],
        ['key' => 'next_run', 'label' => __('system_admin::schedule.next_run'), 'render' => fn ($s) => $s->next_run_at ? \App\Core\Support\DateFormat::spoken($s->next_run_at) : '—'],
        ['key' => 'status', 'label' => __('system_admin::schedule.status_col'), 'render' => fn ($s) => new \Illuminate\Support\HtmlString(
            '<span class="rounded-(--radius-badge) px-2 py-0.5 text-2xs '.($s->is_active ? 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' : 'bg-(--color-badge-draft-bg) text-(--color-badge-draft-ink)').'">'
            .e(__($s->is_active ? 'system_admin::schedule.active' : 'system_admin::schedule.inactive')).'</span>')],
        ['key' => 'act', 'label' => '', 'render' => fn ($s) => new \Illuminate\Support\HtmlString(view('system_admin::reports.partials.row-actions', ['s' => $s])->render())],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::schedule.title') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::schedule.title')"
                          :subtitle="__('system_admin::schedule.subtitle')">
            <x-slot:actions>
                <x-ui.button tone="primary" :href="route('system_admin.reports.schedule.create')">
                    {{ __('system_admin::schedule.add') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>
    @endunless

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    {{-- সূচিগুলো --}}
    <x-ui.filter-layout :filters="$facetPanel ?? \App\Core\ListFilter\Facets::panel([])" form="list-form">
    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        @if ($abosHead)
            <form id="list-form" method="GET" class="contents">
                <x-ui.toolbar :title="__('system_admin::schedule.title')" :info="__('system_admin::schedule.subtitle')"
                              :count="trans_choice('core.count.records', $schedules->total(), ['count' => $schedules->total()])"
                              :search-placeholder="__('system_admin::schedule.report')" :facets="$facetPanel" :share="false" :export="false"
                              :sort="['newest' => __('core.notify.sort.newest'), 'oldest' => __('core.notify.sort.oldest'), 'next' => __('system_admin::schedule.next_run')]" :columns="$columns">
                    <x-slot:actions>
                        <x-ui.button tone="primary" icon="plus" :href="route('system_admin.reports.schedule.create')">{{ __('system_admin::schedule.add') }}</x-ui.button>
                    </x-slot:actions>
                </x-ui.toolbar>
            </form>
        @endif
        @if ($abosHead && ! $schedules->isEmpty())
            {{-- ⭐ পুরো বার — সাধারণ টেবিল, কলাম বাছাই (মালিক, ২৪ নভেম্বর ২০২৬; ২৭ নভেম্বর); বাকি রূপে আগের টেবিল --}}
            <x-ui.table :rows="$schedules" :columns="$columns" />
        @elseif ($schedules->isEmpty())
            {{-- ⭐ খালি অবস্থায় পরের কাজ — "প্রথম রিপোর্ট নির্ধারণ করুন" (সিস্টেম পর্দার নকশা §৫, ১০ অক্টোবর ২০২৬) --}}
            <x-ui.empty-state :message="__('system_admin::schedule.none')"
                              :action="['url' => route('system_admin.reports.schedule.create'), 'label' => __('system_admin::schedule.first')]" />
        @else
            <table class="ui-list w-full text-sm">
                <thead>
                    <tr class="border-b border-(--color-border) text-start text-(--color-ink-muted)">
                        <th class="text-start">{{ __('system_admin::schedule.report') }}</th>
                        <th class="text-start">{{ __('system_admin::schedule.frequency') }}</th>
                        <th class="text-start">{{ __('system_admin::schedule.next_run') }}</th>
                        <th class="text-start">{{ __('system_admin::schedule.status_col') }}</th>
                        <th ></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($schedules as $s)
                        <tr class="border-b border-(--color-border)/60">
                            <td >
                                <span class="font-medium text-(--color-ink)">
                                    {{ $reportTitles[$s->report_key] ?? $s->report_key }}
                                </span>
                                <span class="block text-2xs uppercase text-(--color-ink-muted)">{{ $s->format }}</span>
                            </td>
                            <td class="text-(--color-ink-muted)">
                                {{ __('system_admin::schedule.freq.'.$s->frequency) }} ·
                                {{-- ⓘ কোম্পানির সময়ের ছকে (DateFormat::time()) — কাঁচা "08:00" নয়, অন্য সব পর্দার মতো --}}
                                <span class="num">{{ $s->at_time ? \Illuminate\Support\Carbon::createFromFormat('H:i', substr((string) $s->at_time, 0, 5))->format(\App\Core\Support\DateFormat::time()) : '—' }}</span>
                            </td>
                            <td class="num text-(--color-ink-muted)">
                                {{ $s->next_run_at ? \App\Core\Support\DateFormat::spoken($s->next_run_at) : '—' }}
                            </td>
                            <td >
                                <x-ui.badge :tone="$s->is_active ? 'success' : 'draft'">
                                    {{ __($s->is_active ? 'system_admin::schedule.active' : 'system_admin::schedule.inactive') }}
                                </x-ui.badge>
                            </td>
                            <td class="text-end">
                                @include('system_admin::reports.partials.row-actions')
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>
    <x-ui.pager :rows="$schedules" />
    <x-ui.list-totals :rows="$schedules" />
    </x-ui.filter-layout>

    {{-- সাম্প্রতিক ফাইল --}}
    @if ($runs->isNotEmpty())
        <section class="mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('system_admin::schedule.runs_heading') }}
            </h2>
            <table class="ui-list w-full text-sm">
                <tbody>
                    @foreach ($runs as $run)
                        <tr class="border-b border-(--color-border)/60">
                            <td class="text-(--color-ink-muted)">
                                {{ $reportTitles[$run->schedule?->report_key] ?? ($run->schedule?->report_key ?? '—') }}
                                <span class="block text-2xs uppercase text-(--color-ink-muted)">{{ $run->format }}</span>
                            </td>
                            <td class="num text-(--color-ink-muted)">
                                {{ \App\Core\Support\DateFormat::spoken($run->ran_at) }}
                            </td>
                            <td >
                                {{ __('system_admin::schedule.run_status.'.$run->status) }}
                                @if ($run->status === 'ok')
                                    <span class="text-2xs text-(--color-ink-muted)">· {{ $run->row_count }}</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if ($run->hasFile())
                                    <a href="{{ route('system_admin.reports.download', $run) }}"
                                       class="text-(--color-brand-600) hover:underline">
                                        {{ __('system_admin::schedule.download') }}
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif
</x-layouts.app>
