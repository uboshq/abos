{{--
    এই কাগজটা কে কখন বের করেছে।

    ── ⭐ কেন সংখ্যাটার পিছনে একটা পাতা ─────────────────────────────────
    মালিক গোনাটা চেয়েছিলেন *"কয়টা কাগজ প্রিন্ট হল কয়টা শেয়ার হইল"* —
    কিন্তু গোনা দিয়ে কোনো তর্ক থামে না। ⓘ গ্রাহক "বিল পাইনি" বললে
    দরকার হয় **কে পাঠিয়েছিল, কখন, আর তিনি খুলেছিলেন কি না** — সেই তিনটা
    উত্তর এই পাতায়, আর সংখ্যাটা এখানেই নিয়ে আসে।

    ⚠️ IP দেখানো হয় কেবল গ্রাহকের খোলার সারিতে, নিজেদের ছাপায় নয় — নিজের
    অফিসের ভিতরের IP কোনো তথ্য নয়, কিন্তু লিংক কোথা থেকে খোলা হল সেটা
    "সত্যিই তিনি খুলেছেন কি না" প্রশ্নের একমাত্র সূত্র।
--}}
@php
    // ⭐ এক সারির পুরো বার কেবল তিন ABOS রূপে ([[AbosHead]]); বাকি রূপ আগের মতো — কাগজের নম্বর বারের গোনার জায়গায়, জীবিত লিংক বারের নিচে
    $abosHead = \App\Core\Support\AbosHead::on();
    $facetPanel = ! $abosHead ? null : \App\Core\ListFilter\Facets::panel([
        \App\Core\ListFilter\Facets::pick('how', __('core.print.history_how'), [
            \App\Models\DocumentDelivery::PRINTED => __('core.print.way_printed'), \App\Models\DocumentDelivery::DOWNLOADED => __('core.print.way_downloaded'),
            \App\Models\DocumentDelivery::SHARED => __('core.print.way_shared'), \App\Models\DocumentDelivery::OPENED => __('core.print.way_opened'),
        ]),
    ]);
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.print.history_title') }}</x-slot:title>

    <x-ui.filter-layout :filters="$facetPanel ?? \App\Core\ListFilter\Facets::panel([])" form="list-form">
    @if ($abosHead)
        <div data-boxed class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <form id="list-form" method="GET" class="contents">
                <input type="hidden" name="type" value="{{ $type }}">
                <input type="hidden" name="id" value="{{ $id }}">
                <x-ui.toolbar :title="__('core.print.history_title')" :count="__('core.print.history_of', ['no' => $documentNo ?? '—'])" :quiet="['type', 'id']"
                              :search-placeholder="__('core.print.history_who')" :facets="$facetPanel" :share="false" :export="false"
                              :sort="['newest' => __('core.notify.sort.newest'), 'oldest' => __('core.notify.sort.oldest')]" />
            </form>
        </div>
    @endif

    <div class="mb-3" @if ($abosHead) hidden @endif>
        <p class="text-sm text-(--color-ink-muted)">
            {{ __('core.print.history_of', ['no' => $documentNo ?? '—']) }}
        </p>
    </div>

    {{--
        ⭐ এখনো বেঁচে থাকা গোপন লিংক — আর থামানোর বোতাম (২১ সেপ্টেম্বর ২০২৬)।

        ── ⚠️ কেন এটা না থাকা একটা ফাঁক ছিল ────────────────────────────
        ভুল নম্বরে বিলটা পাঠিয়ে ফেললে ৩০ দিন ধরে অচেনা কারো হাতে কাগজটা
        খোলা থাকত, আর থামানোর কোনো পথ ছিল না। ⓘ ডেটাবেসে ঘরটা প্রথম দিন
        থেকেই ছিল — কেবল কেউ কোনোদিন ওতে লিখত না।
    --}}
    @if ($shares->isNotEmpty())
        <section data-boxed
                 class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <header class="border-b border-(--color-border) px-4 py-2">
                <h2 class="text-sm font-semibold">{{ __('core.print.live_links') }}</h2>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('core.print.live_links_hint') }}</p>
            </header>

            <ul class="divide-y divide-(--color-border)">
                @foreach ($shares as $share)
                    <li class="flex flex-wrap items-center gap-3 px-4 py-2 text-sm">
                        <span class="text-(--color-ink-muted)">
                            {{ __('core.print.days_left', ['n' => $share->daysLeft()]) }}
                        </span>

                        <span class="tabular-nums text-2xs text-(--color-ink-muted)">
                            {{ __('core.print.opened_times', ['n' => $share->opened_count]) }}
                        </span>

                        <span class="flex-1"></span>

                        <form method="POST" action="{{ route('paper.revoke', $share) }}">
                            @csrf
                            <x-ui.button type="submit" tone="secondary">
                                {{ __('core.print.revoke_link') }}
                            </x-ui.button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @php
        $ways = [
            \App\Models\DocumentDelivery::PRINTED => 'core.print.way_printed',
            \App\Models\DocumentDelivery::DOWNLOADED => 'core.print.way_downloaded',
            \App\Models\DocumentDelivery::SHARED => 'core.print.way_shared',
            \App\Models\DocumentDelivery::OPENED => 'core.print.way_opened',
        ];

        $columns = [
            ['key' => 'when', 'label' => __('core.print.history_when'),
             'render' => fn ($r) => $r->created_at?->format('d/m/Y h:i A')],

            ['key' => 'how', 'label' => __('core.print.history_how'),
             'render' => fn ($r) => __($ways[$r->how] ?? 'core.print.way_printed')],

            /*
                ⓘ গ্রাহকের খোলায় কোনো ব্যবহারকারী নেই — লগইনই নেই, সেটাই
                নকশা। তাই ফাঁকা নয়, "গ্রাহক" লেখা হয়: ফাঁকা ঘর দেখলে
                মানুষ ভাবতেন তথ্য হারিয়ে গেছে।
            */
            ['key' => 'who', 'label' => __('core.print.history_who'),
             'render' => fn ($r) => $r->user?->name
                 ?? ($r->how === \App\Models\DocumentDelivery::OPENED
                     ? __('core.print.history_customer')
                     : '—')],

            ['key' => 'paper', 'label' => __('core.print.history_paper'),
             'render' => fn ($r) => \App\Core\Engines\Print\PaperSize::of($r->paper)->label()],

            ['key' => 'from', 'label' => __('core.print.history_from'),
             'render' => fn ($r) => $r->how === \App\Models\DocumentDelivery::OPENED
                 ? ($r->from_ip ?: '—')
                 : '—'],
        ];
    @endphp

    <x-ui.table :rows="$rows"
                :columns="$columns"
                :empty="__('core.print.history_empty')" />

    <x-ui.pager :rows="$rows" />
    <x-ui.list-totals :rows="$rows" />
    </x-ui.filter-layout>
</x-layouts.app>
