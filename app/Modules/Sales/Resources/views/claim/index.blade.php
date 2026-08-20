{{--
    ডিপোর দিক — ডিলারদের তোলা দাবি।

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
            'key' => 'dealer',
            'label' => __('sales::portal.dealer'),
            'render' => fn ($c) => view('sales::claim.partials.dealer', ['claim' => $c]),
        ],
        [
            'key' => 'claimed_on',
            'label' => __('sales::portal.claimed_on'),
            'width' => '9rem',
            'render' => fn ($c) => $c->claimed_on?->format('d M Y'),
        ],
        [
            'key' => 'amount',
            'label' => __('sales::portal.claimed'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($c) => view('sales::claim.partials.amount', ['value' => $c->amount]),
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

    <x-slot:header>
        <x-ui.page-header :title="__('sales::portal.desk_title')"
                          :subtitle="__('sales::portal.desk_subtitle')" />
    </x-slot:header>

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

    <div class="mb-4 flex gap-2">
        <a href="{{ route('sales.claim.index') }}"
           @class([
               'rounded-(--radius-field) border px-3 py-1.5 text-sm',
               'border-(--color-brand-500) text-(--color-brand-500)' => $status === 'pending',
               'border-(--color-border)' => $status !== 'pending',
           ])>
            {{ __('sales::portal.only_pending') }} ({{ $pendingCount }})
        </a>
        <a href="{{ route('sales.claim.index', ['status' => 'all']) }}"
           @class([
               'rounded-(--radius-field) border px-3 py-1.5 text-sm',
               'border-(--color-brand-500) text-(--color-brand-500)' => $status === 'all',
               'border-(--color-border)' => $status !== 'all',
           ])>
            {{ __('sales::portal.show_all') }}
        </a>
    </div>

    <x-ui.table :rows="$claims"
                :columns="$columns"
                :empty="__('sales::portal.empty')" />

    {{ $claims->links() }}

</x-layouts.app>
