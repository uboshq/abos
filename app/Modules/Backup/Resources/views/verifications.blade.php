{{--
    যাচাই — প্রতিটা রাত, আর সেটা ফিরিয়ে এনে দেখা গিয়েছিল কি না।

    ⓘ সারিগুলো রাতের (BackupRun), যাচাইয়ের নয়: যে রাতে ডাম্পই হয়নি তার
    কোনো যাচাই নেই, অথচ সেটাই সবচেয়ে জরুরি লাল সারি। ব্যর্থ রাতে কারণটা
    হুবহু দেখানো হয় — ১৯ সেপ্টেম্বর ২০২৬-এর "Access denied … verify"
    মালিক আগে কেবল সার্ভারের লগে পেতেন।
--}}
@php
    $check = fn ($run) => $run->verifications->sortByDesc('verified_at')->first();
    $columns = [
        ['key' => 'when', 'label' => __('backup::screen.col_when'), 'width' => '11rem',
         'render' => fn ($run) => \App\Core\Support\DateFormat::formatWithTime($run->started_at)],
        ['key' => 'how', 'label' => __('backup::screen.col_how'), 'width' => '9rem',
         'render' => fn ($run) => $run->triggered_by === 'schedule' ? __('backup::screen.trigger_schedule') : __('backup::screen.trigger_manual')],
        ['key' => 'result', 'label' => __('backup::screen.col_result'), 'wrap' => true,
         'render' => fn ($run) => view('backup::partials.run-result', ['run' => $run, 'seesRawErrors' => $seesRawErrors])],
        ['key' => 'check', 'label' => __('backup::screen.col_check'), 'wrap' => true,
         'render' => fn ($run) => view('backup::partials.run-check', ['check' => $check($run), 'seesRawErrors' => $seesRawErrors])],
    ];
    $rowAttributes = fn ($run) => [
        'class' => $run->status === 'failed' || $check($run)?->status === 'failed' ? 'bg-(--color-badge-danger-bg)' : '',
        'data-run-status' => $run->status,
    ];
    $abosHead = \App\Core\Support\AbosHead::on();
    $sinceLine = $since
        ? __('backup::screen.records_since', ['date' => \App\Core\Support\DateFormat::format(\Illuminate\Support\Carbon::parse($since))])
        : __('backup::screen.no_records_yet');
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('status', __('backup::screen.col_result'),
            collect(['success', 'partial', 'local_only', 'failed', 'running'])->mapWithKeys(fn ($s) => [$s => __('backup::screen.status_'.$s)])->all()),
        \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('backup::screen.verify_title') }}</x-slot:title>

    @unless ($abosHead)
    <x-slot:header>
        <x-ui.page-header :title="__('backup::screen.verify_title')"
                          :subtitle="__('backup::screen.verify_subtitle')" />
    </x-slot:header>
    @endunless

    @unless ($abosHead)
    <p class="mb-3 text-sm text-(--color-ink-muted)">
        @if ($since)
            {{ __('backup::screen.records_since', ['date' => \App\Core\Support\DateFormat::format(\Illuminate\Support\Carbon::parse($since))]) }}
        @else
            {{ __('backup::screen.no_records_yet') }}
        @endif
    </p>
    @endunless

    <x-ui.filter-layout :filters="$abosHead ? $facetPanel : \App\Core\ListFilter\Facets::panel([])" form="list-form">
    @if ($abosHead)
        <form id="list-form" method="GET" class="contents">
            <x-ui.toolbar :title="__('backup::screen.verify_title')" :info="__('backup::screen.verify_subtitle').' '.$sinceLine"
                          :search="false" :export="false" :share="false" :columns="$columns" :facets="$facetPanel" />
        </form>
    @endif

    @if ($runs->isNotEmpty())
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <x-ui.table :rows="$runs" :columns="$columns" :row-attributes="$rowAttributes" />

            <x-ui.pager :rows="$runs" />
        </section>
    @endif

    {{-- ⓘ খালি হলেও "০টি সারি" — যে রাতগুলো নেই, সেটাও একটা উত্তর --}}
    <x-ui.list-totals :rows="$runs" />
    </x-ui.filter-layout>
</x-layouts.app>
