{{--
    রপ্তানির খাতা — কে কোন তালিকা নামিয়ে নিয়ে গেছে।

    ── কেন সারিতেই সব ─────────────────────────────────────────────────
    প্রশ্নটা ওঠে নির্দিষ্ট একটা মুহূর্তে: কেউ চাকরি ছাড়ল, বা দর ফাঁস
    হলো। তখন কেউ বিশটা সারিতে ক্লিক করে বেড়ায় না — তাকিয়েই বুঝতে হয়
    কোনটা স্বাভাবিক আর কোনটা নয়। তাই ছাঁকনি ও সারির সংখ্যা দুইটাই
    তালিকাতেই, আলাদা কোনো পাতায় নয়।
--}}
@php
    $columns = [
        ['key' => 'created_at', 'label' => __('governance::field.when'), 'width' => '12rem',
         'render' => fn ($r) => \App\Core\Support\DateFormat::formatWithTime($r->created_at)],
        ['key' => 'user', 'label' => __('governance::field.who'), 'width' => '11rem',
         'render' => fn ($r) => view('governance::partials.user-link', [
             'id' => $r->user_id, 'label' => $r->who(),
         ])],
        ['key' => 'title', 'label' => __('governance::field.what_was_taken'),
         'render' => fn ($r) => $r->title ?: $r->route],

        /*
         * কয়টা সারি — সবচেয়ে বেশি যেটা দেখে সন্দেহ হয়।
         *
         * দশ সারির একটা ফাইল রোজকার কাজ; দশ হাজার সারির ফাইল একটা
         * প্রশ্ন। দুইটাই "একটা রপ্তানি" লিখলে তফাতটা হারিয়ে যেত।
         */
        ['key' => 'row_count', 'label' => __('governance::field.rows'), 'width' => '7rem',
         'render' => fn ($r) => number_format($r->row_count)],

        ['key' => 'filters', 'label' => __('governance::field.filters'),
         'render' => fn ($r) => $r->filterSummary()],
    ];

    // ⭐ ছাঁকনি ডান পাশের প্যানেলে — আগের ঘরগুলো, একই চাবি (মালিক, ২৩ নভেম্বর ২০২৬; [[Facets]])
    $facetPanel = \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('user', __('governance::field.who'),
            collect($users)->mapWithKeys(fn ($u) => [(string) $u->id => $u->name])->all(), __('governance::field.who')),
        \App\Core\ListFilter\Facets::pick('route', __('governance::field.what_was_taken'),
            collect($routes)->mapWithKeys(fn ($r) => [$r => $r])->all(), __('governance::field.what_was_taken')),
        \App\Core\ListFilter\Facets::dates(__('core.list_filter.date')),
    ], null, ['from' => $dates['from'] ?? null, 'to' => $dates['to'] ?? null]);
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('governance::menu.export_log') }}</x-slot:title>

    <p class="mb-4 rounded-(--radius-field) bg-(--color-surface-hover) px-3 py-2 text-2xs
              text-(--color-ink-muted)">
        {{ __('governance::message.export_why') }}
    </p>

    <x-ui.filter-layout :filters="$facetPanel" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form id="list-form" method="GET" class="contents">
            {{--
                রপ্তানির খাতাটা নিজে রপ্তানি হয় না।

                ── কেন ─────────────────────────────────────────────────
                হলে প্রতিবার নামানোর ফলে খাতায় আরেকটা সারি বসত, আর
                সেই সারিটাও রপ্তানিযোগ্য — একটা খাতা যা নিজের দিকেই
                তাকিয়ে বাড়ে। তার চেয়েও বড় কথা: যিনি নিজের চিহ্ন
                ঢাকতে চান, তাঁর প্রথম কাজই হত পুরো খাতাটা নামিয়ে দেখা
                কী কী ধরা পড়েছে।
            --}}
            <x-ui.toolbar :title="__('governance::menu.export_log')" :count="trans_choice('core.count.records', $rows->total(), ['count' => $rows->total()])" :export="false" :search="false" :facets="$facetPanel">
            </x-ui.toolbar>
        </form>

        @if ($rows->isEmpty())
            <x-ui.empty-state :message="__('governance::message.nothing_exported')" />
        @else
            <x-ui.table :rows="$rows" :columns="$columns" />
        @endif
    </div>

    <x-ui.pager :rows="$rows" />
    <x-ui.list-totals :rows="$rows" />
    </x-ui.filter-layout>
</x-layouts.app>
