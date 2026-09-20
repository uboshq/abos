{{--
    পোস্টিং মনিটর — একটা দিনে কী খাতায় উঠল, আর কী খসড়ায় আটকে আছে।

    ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
    ফিন্যান্স মানচিত্রের §২ "পোস্টিং মনিটর"। বিল নিশ্চিত হলে খাতা নিজে
    লেখা হয়, আর সেটা চোখের আড়ালে। এই পর্দা বলে আজ কোন ধরনের কাগজ কতগুলো
    উঠল। ⓘ কোনো ধরন একদম না থাকলে (যেমন আজ বিক্রি হলো অথচ বিক্রির বিল
    খাতায় নেই), সেটাই প্রথম সংকেত।
--}}
@php
    $postedColumns = [
        ['key' => 'source', 'label' => __('accounts::control.source')],
        ['key' => 'documents', 'label' => __('accounts::control.documents'), 'numeric' => true, 'width' => '7rem'],
        ['key' => 'lines', 'label' => __('accounts::control.lines'), 'numeric' => true, 'width' => '7rem'],
        ['key' => 'debit', 'label' => __('accounts::control.debit'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($r) => \App\Core\Support\Money::format($r['debit'])],
        ['key' => 'last_at', 'label' => __('accounts::control.last_at'), 'width' => '11rem',
         'render' => fn ($r) => \App\Core\Support\DateFormat::formatWithTime($r['last_at'])],
    ];

    /*
     * ⭐ খাতায় না-ওঠা কাগজের কলাম — নম্বরটা ক্লিকযোগ্য, মালিকের
     * "sob jaygay hyper link dewar kotha" ধরে।
     *
     * ⚠️ ছকটা এখানে, `:columns="[…]"`-এর ভিতরে নয় — abos-f9-এর ধরা, ২০
     * সেপ্টেম্বর ২০২৬। ⛔ অ্যাট্রিবিউটের ভিতরে একটা ASCII ডবল কোট থাকলেই
     * (এমনকি মন্তব্যের ভিতরে) Blade ওখানেই অ্যাট্রিবিউট শেষ ধরে, আর
     * পুরো ট্যাগটা লেখা হিসেবে ছাপা হয় — পাতা তবু ২০০ দেয়, তাই টেরও
     * পাওয়া যায় না।
     *
     * ⓘ কোন কাগজ কোথায় খোলে সেটা x-ui.drill জানে, তাই এখানে রুট বাছা হয় না।
     */
    $notPostedColumns = [
        ['key' => 'trx_date', 'label' => __('accounts::control.date'), 'width' => '8rem',
         'render' => fn ($r) => \App\Core\Support\DateFormat::format($r['trx_date'])],
        ['key' => 'source', 'label' => __('accounts::control.source'), 'width' => '11rem',
         'render' => fn ($r) => \Illuminate\Support\Facades\Lang::has('core.source.'.$r['source'])
             ? __('core.source.'.$r['source'])
             : $r['source']],
        ['key' => 'document_no', 'label' => __('core.print.document_no'), 'width' => '12rem',
         'render' => fn ($r) => view('accounts::control.partials.paper-link', ['row' => $r])],
        ['key' => 'amount', 'label' => __('accounts::control.amount'), 'numeric' => true, 'width' => '10rem',
         'render' => fn ($r) => $r['amount'] === null ? '—' : \App\Core\Support\Money::format($r['amount'])],
    ];

    $stuckColumns = [
        ['key' => 'trx_date', 'label' => __('accounts::control.date'), 'width' => '8rem',
         'render' => fn ($v) => \App\Core\Support\DateFormat::format($v->trx_date)],
        ['key' => 'document_no', 'label' => __('accounts::control.voucher'), 'width' => '10rem',
         'render' => fn ($v) => view('accounts::control.partials.voucher-link', ['voucher' => $v])],
        ['key' => 'type', 'label' => __('accounts::control.kind'), 'width' => '8rem',
         'render' => fn ($v) => __('accounts::menu.'.$v->type)],
        ['key' => 'narration', 'label' => __('core.table.description')],
        ['key' => 'amount', 'label' => __('accounts::control.amount'), 'numeric' => true, 'width' => '9rem',
         'render' => fn ($v) => \App\Core\Support\Money::format($v->amount)],
        ['key' => 'why', 'label' => __('accounts::control.why_stuck'), 'width' => '11rem',
         'render' => fn ($v) => in_array($v->id, $awaitingIds, true)
             ? __('accounts::control.awaiting')
             : __('accounts::control.old_draft')],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::control.posting_title') }}</x-slot:title>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            @if ($tab === 'stuck')
                <input type="hidden" name="tab" value="stuck">
            @endif

            <x-ui.toolbar :title="__('accounts::control.posting_title')"
                          :subtitle="$tab === 'stuck'
                              ? __('accounts::control.stuck_note', ['days' => \App\Modules\Accounts\Http\Controllers\FinanceControlController::STUCK_AFTER_DAYS])
                              : __('accounts::control.posting_note')"
                          :search="false" :filter="false" :density="false"
                          :export="false" :share="false" :quiet="['tab', 'date']">
                @if ($tab === 'posted')
                    <x-slot:actions>
                        <label class="flex items-center gap-2 text-sm">
                            <span class="text-(--color-ink-muted)">{{ __('accounts::control.date') }}</span>
                            <input type="date" name="date" value="{{ $date->toDateString() }}"
                                   class="min-h-(--spacing-touch) rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-card) px-2">
                        </label>
                        <x-ui.button type="submit">{{ __('accounts::control.show') }}</x-ui.button>
                    </x-slot:actions>
                @endif
            </x-ui.toolbar>
        </form>

        @include('accounts::control.partials.posting-tabs', [
            'active' => $tab,
            'stuckCount' => $stuck->count(),
            'failedCount' => $failedCount,
        ])

        @if ($tab === 'stuck')
            {{-- ⭐ নিশ্চিত হয়েও খাতায় ওঠেনি — ২০ সেপ্টেম্বর ২০২৬, মালিকের
                 *"Accounts e post pending hoye thakle bujha zay na"*।

                 ⓘ সবার উপরে, আর খসড়ার আগে: খসড়া দেখলে বোঝা যায় কাজ বাকি,
                 কিন্তু নিশ্চিত হওয়া বিল দেখে সবাই ভাবেন কাজ শেষ — অথচ
                 খাতায় নেই মানে লাভ-ক্ষতি আর বকেয়া দুইটাই ভুল বলছে। --}}
            @if ($notPosted !== [])
                <section class="border-b border-(--color-border)" data-not-posted>
                    <h2 class="flex items-baseline gap-2 bg-(--color-badge-danger-bg) px-4 py-2
                               text-sm font-semibold text-(--color-badge-danger-ink)">
                        {{ __('accounts::control.not_posted') }}
                        <span class="num">{{ count($notPosted) }}</span>
                    </h2>

                    <p class="border-b border-(--color-border) px-4 py-2 text-2xs text-(--color-ink-muted)">
                        {{ __('accounts::control.not_posted_note', ['days' => \App\Modules\Accounts\Services\PostingBacklog::LOOK_BACK_DAYS]) }}
                    </p>

                    <x-ui.table :rows="$notPosted" :columns="$notPostedColumns" />
                </section>
            @endif

            {{-- অন্য মডিউলের কাগজ সইয়ের অপেক্ষায় — ভাউচারগুলো নিচের তালিকায় --}}
            @if ($awaiting !== [])
                <p class="flex flex-wrap items-center gap-2 border-b border-(--color-border) px-4 py-2 text-sm">
                    <span class="text-(--color-ink-muted)">{{ __('accounts::control.awaiting_elsewhere') }}</span>
                    @foreach ($awaiting as $module => $count)
                        <a href="{{ route('approval.inbox.index', ['module' => $module]) }}"
                           class="rounded-(--radius-pill) bg-(--color-badge-pending-bg) px-2.5 py-1 text-2xs
                                  text-(--color-badge-pending-ink) hover:underline">
                            {{ \Illuminate\Support\Facades\Lang::has('core.module.'.$module) ? __('core.module.'.$module) : $module }}
                            <span class="num">{{ $count }}</span>
                        </a>
                    @endforeach
                </p>
            @endif

            <x-ui.table :rows="$stuck" :columns="$stuckColumns" :empty="__('accounts::control.nothing_stuck')" />
        @else
            <x-ui.table :rows="$posted" :columns="$postedColumns" :empty="__('accounts::control.nothing_posted')" />
        @endif
    </div>
</x-layouts.app>
