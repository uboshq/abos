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
            <x-ui.table :rows="$stuck" :columns="$stuckColumns" :empty="__('accounts::control.nothing_stuck')" />
        @else
            <x-ui.table :rows="$posted" :columns="$postedColumns" :empty="__('accounts::control.nothing_posted')" />
        @endif
    </div>
</x-layouts.app>
