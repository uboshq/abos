{{--
    ডিপোর দিক — গ্রাহকদের তোলা দাবি।

    ডিফল্টে কেবল অপেক্ষমাণ, কারণ এই পাতাটার একটাই কাজ: আজ কোনগুলো
    যাচাই করা বাকি। গৃহীত ও প্রত্যাখ্যাত দাবি ইতিহাস, আর ইতিহাস রোজ
    চোখের সামনে থাকলে আজকের কাজটা হারিয়ে যায়।

    সিদ্ধান্ত সারি থেকেই — দিনে বিশটা দাবিতে যাওয়া-আসা করলে কেউ আর
    তালিকাটা খুলত না।
--}}
@php
    /*
        কলাম ধরে, স্লটে নয় — `x-ui.table` স্লট পড়ে না, আর প্রতিটা
        কলামে `key` ও `label` দুইটাই চায়। প্রথম লেখায় ভেতরে হাতে
        `<tr>` বসানো ছিল, ফলে পাতাটা খালি অবস্থায় ঠিক চলত আর প্রথম
        দাবিটা আসামাত্র ৫০০ দিত।
    */
    $columns = [
        [
            'key' => 'customer',
            'label' => __('sales::portal.customer'),
            'render' => fn ($c) => view('sales::claim.partials.customer', ['claim' => $c]),
        ],
        [
            'key' => 'claimed_on',
            'label' => __('sales::portal.claimed_on'),
            'width' => '9rem',
            'render' => fn ($c) => \App\Core\Support\DateFormat::format($c->claimed_on),
        ],
        [
            'key' => 'amount',
            'total' => 'money',
            'label' => __('sales::portal.claimed'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($c) => view('sales::claim.partials.amount', ['value' => $c->amount, 'claim' => $c]),
        ],
        [
            'key' => 'reference',
            'label' => __('sales::portal.reference'),
            'render' => fn ($c) => $c->reference ?? '—',
        ],
        [
            'key' => 'status',
            'label' => __('sales::portal.status'),
            'width' => '9rem',
            'render' => fn ($c) => view('sales::claim.partials.status', ['claim' => $c]),
        ],
        [
            'key' => 'decide',
            'label' => '',
            'render' => fn ($c) => view('sales::claim.partials.decide',
                ['claim' => $c, 'moneyAccounts' => $moneyAccounts]),
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('sales::portal.desk_title') }}</x-slot:title>

    @if (session('status'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- অপেক্ষমাণ / সব — এই পাতার প্রথম প্রশ্ন, তাই ছাঁকনির ভিতরে নয়, ট্যাবে; খোঁজা সাথে যায়, পাতার নম্বর যায় না --}}
    <x-ui.list-tabs :label="__('sales::portal.desk_title')" :tabs="[
        ['key' => 'pending', 'label' => __('sales::portal.only_pending'), 'count' => $pendingCount, 'active' => $status === 'pending',
            'url' => route('sales.claim.index', request()->except(['status', 'page']))],
        ['key' => 'all', 'label' => __('sales::portal.show_all'), 'active' => $status === 'all',
            'url' => route('sales.claim.index', [...request()->except(['status', 'page']), 'status' => 'all'])],
    ]" />

    <x-ui.filter-layout :filters="$filters" form="list-form">
    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        {{-- ⭐ শিরোনাম আর খোঁজা — বাকি তালিকার মতো এক বাক্সে।
             মালিক, ১৯ সেপ্টেম্বর ২০২৬: *"সব মডিউলেই একই অবস্থা, সব ঠিক করো"*। --}}
        <form id="list-form" method="GET" class="contents">
            {{-- ⓘ "সব" দেখার সময় খোঁজা বা ঘনত্ব বদলালেও "সব"-ই থাকে --}}
            @if ($status !== 'pending')
                <input type="hidden" name="status" value="{{ $status }}">
            @endif

            <x-ui.toolbar :sort="\App\Core\ListFilter\ListSort::labels(['newest', 'oldest', 'amount_high', 'amount_low'])" :facets="$filters" :title="__('sales::portal.desk_title')"
                :subtitle="__('sales::portal.desk_subtitle')"
                :columns="$columns"
                :search-placeholder="__('sales::message.claim_search')"
                {{-- ⓘ অপেক্ষায়/সব — নিচের দুই লিংকই দেখায়, চিপ নয় --}}
                :quiet="['status']" />
        </form>

        <x-ui.table :rows="$claims"
                    :grand="$grand ?? []"
                    :columns="$columns"
                    :compact="\App\Core\Support\Ui::compact()"
                    :empty="request('q') ? __('core.empty.no_results') : __('sales::portal.empty')" />
    </div>
    </x-ui.filter-layout>

    {{ $claims->links() }}
    <x-ui.list-totals :rows="$claims" :grand="$grand ?? []" :columns="$columns" />

</x-layouts.app>
