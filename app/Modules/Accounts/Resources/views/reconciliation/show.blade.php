{{--
    মিলকরণের কাজের পর্দা।

    উপরে অঙ্কটা, নিচে সারিগুলো — আর ক্রমটা ইচ্ছাকৃত। ব্যবহারকারী টিক
    দেন নিচে, কিন্তু দেখেন উপরে: প্রতিটা টিকের পরে "এখনো ব্যাখ্যাহীন"
    সংখ্যাটা শূন্যের দিকে যাচ্ছে কি না। ওটা নিচে থাকলে প্রতিবার
    স্ক্রল করে দেখতে হত, আর তখন কেউ আর দেখত না।

    তফাতের ঘরটা কেবল শূন্য হলেই সবুজ। "প্রায় মিলে গেছে" বলে কিছু নেই —
    দুই টাকার তফাতও একটা এন্ট্রি ভুল হওয়ার প্রমাণ।
--}}
@php
    /*
        কলাম ধরে, স্লটে নয় — কম্পোনেন্ট স্লট পড়ে না।

        টিকের ঘরটা একটা ইনপুট, আর ওটা ফর্মের ভেতরে থাকতেই হবে
        (`name="lines[]"`)। তাই টেবিলটা ফর্মের ভেতরে বসে, আর ঘরটা
        একটা partial হয়ে `render` ক্লোজার থেকে আসে।
    */
    $locked = $recon->isConfirmed();

    $columns = [
        [
            'key' => 'tick',
            'label' => __('accounts::recon.seen_by_bank'),
            'width' => '7rem',
            'render' => fn ($l) => view('accounts::reconciliation.partials.tick',
                ['line' => $l, 'locked' => $locked]),
        ],
        [
            'key' => 'date',
            'label' => __('accounts::recon.date'),
            'width' => '9rem',
            'render' => fn ($l) => $l->voucher?->trx_date?->format('d M Y'),
        ],
        [
            'key' => 'document',
            'label' => __('accounts::recon.document'),
            'render' => fn ($l) => $l->voucher?->document_no,
        ],
        [
            'key' => 'narration',
            'label' => __('accounts::recon.narration_col'),
            'render' => fn ($l) => $l->narration ?? $l->voucher?->narration,
        ],
        [
            'key' => 'debit',
            'label' => __('accounts::recon.paid_in'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($l) => view('accounts::reconciliation.partials.money', ['value' => $l->debit]),
        ],
        [
            'key' => 'credit',
            'label' => __('accounts::recon.paid_out'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($l) => view('accounts::reconciliation.partials.money', ['value' => $l->credit]),
        ],
    ];

    /*
        ⭐ ব্যাংকের নিজের সারিগুলোর কলাম (২০ সেপ্টেম্বর ২০২৬)।

        ⚠️ নামগুলো ব্যাংকের চোখে লেখা — "ব্যাংক নিয়েছে" আর "ব্যাংক জমা
        করেছে"। ⓘ আমাদের "জমা/উত্তোলন" লিখলে মানুষ দুই তালিকার দুই দিক
        মিলিয়ে ফেলতেন, কারণ ব্যাংকের কাগজে দিকটা উল্টো।
    */
    $bankColumns = [
        [
            'key' => 'trx_date',
            'label' => __('accounts::recon.date'),
            'width' => '7rem',
            'render' => fn ($b) => $b->trx_date?->format('d M Y'),
        ],
        [
            'key' => 'description',
            'label' => __('accounts::recon.narration'),
            'render' => fn ($b) => $b->description ?: '—',
        ],
        [
            'key' => 'reference',
            'label' => __('accounts::field.instrument_no'),
            'width' => '9rem',
            'render' => fn ($b) => $b->reference ?: '—',
        ],
        [
            'key' => 'debit',
            'label' => __('accounts::field.withdrawn'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($b) => bccomp((string) $b->debit, '0', 4) > 0
                ? view('accounts::reconciliation.partials.money', ['value' => $b->debit])
                : '',
        ],
        [
            'key' => 'credit',
            'label' => __('accounts::field.deposited'),
            'numeric' => true,
            'width' => '10rem',
            'render' => fn ($b) => bccomp((string) $b->credit, '0', 4) > 0
                ? view('accounts::reconciliation.partials.money', ['value' => $b->credit])
                : '',
        ],
    ];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::recon.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$recon->bankAccount?->label() ?? __('accounts::recon.title')"
                          :subtitle="$recon->statement_date?->format('d M Y')" />
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

    {{-- অঙ্কটা — পাঁচটা সংখ্যা, আর শেষেরটাই আসল। --}}
    <div class="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ([
            ['accounts::recon.statement', $summary['statement']],
            ['accounts::recon.deposits_pending', $summary['deposits']],
            ['accounts::recon.cheques_pending', $summary['cheques']],
            ['accounts::recon.ledger', $summary['ledger']],
        ] as [$label, $value])
            <div data-boxed class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) px-4 py-3">
                <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">{{ __($label) }}</p>
                <p class="num text-xl font-semibold">{{ \App\Core\Support\Money::format($value) }}</p>
            </div>
        @endforeach

        <div @class([
            'rounded-(--radius-card) border px-4 py-3',
            'border-(--color-badge-success-ink)/30 bg-(--color-badge-success-bg)' => $summary['agrees'],
            'border-(--color-badge-danger-ink)/30 bg-(--color-badge-danger-bg)' => ! $summary['agrees'],
        ])>
            <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                {{ __('accounts::recon.difference') }}
            </p>
            <p class="num text-xl font-semibold">{{ \App\Core\Support\Money::format($summary['difference']) }}</p>
        </div>
    </div>

    @if (! $summary['agrees'])
        <p class="mb-4 text-sm text-(--color-ink-muted)">{{ __('accounts::recon.does_not_agree_hint') }}</p>
    @endif

    {{--
        ⭐ ব্যাংক যা জানে, আমাদের বই জানে না — মানচিত্র §৯, ২০ সেপ্টেম্বর ২০২৬।

        ── ⚠️ কেন এই অংশটা টিকের তালিকার **উপরে** ──────────────────────
        নিচের তালিকাটা বলে "আমাদের কোন সারি ব্যাংকে ওঠেনি" — সেটা সাধারণত
        সময়ের ব্যাপার, চেক পাশ হতে দেরি। ⓘ কিন্তু তফাত থেকে যাওয়ার আসল
        কারণ প্রায়ই এই উপরের তালিকাটা: চার্জ, সুদ, এসএমএস ফি, ফেরত আসা
        চেক — যেগুলো কেউ বইয়ে তোলেনি, কারণ কেউ জানতই না ঘটেছে।

        ⛔ এখান থেকে কোনো দাখিলা নিজে থেকে বসে না। ব্যাংক "SERVICE CHARGE"
        লিখলে সেটা কোন খরচের খাতে যাবে ব্যাংক জানে না — মানুষ জানেন, আর
        তিনি স্বাভাবিক দরজা দিয়েই ভাউচার বসান।
    --}}
    @if ($recon->isDraft())
        @can('accounts.reconciliation.manage')
            <form method="POST" action="{{ route('accounts.reconciliation.statement', $recon) }}"
                  enctype="multipart/form-data"
                  class="mb-4 flex flex-wrap items-end gap-2 rounded-(--radius-card)
                         border border-(--color-border) bg-(--color-surface-card) p-3">
                @csrf

                <label class="text-2xs text-(--color-ink-muted)">
                    {{ __('accounts::recon.statement_file') }}
                    <input type="file" name="file" accept=".csv,text/csv,text/plain" required
                           class="block h-(--spacing-field) rounded-(--radius-field)
                                  border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                </label>

                <x-ui.button type="submit" tone="secondary">
                    {{ __('accounts::recon.load_statement') }}
                </x-ui.button>

                {{-- ⓘ নমুনা ফাইলটা কাঠামোরই — কলামের নাম ওখান থেকেই আসে --}}
                <a href="{{ route('system_admin.import.template', 'bank_statement') }}"
                   class="text-2xs text-(--color-brand-600) underline-offset-2 hover:underline">
                    {{ __('accounts::recon.statement_sample') }}
                </a>
            </form>
        @endcan
    @endif

    @if ($fromBank->isNotEmpty())
        <section data-boxed
                 class="mb-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card)">
            <header class="border-b border-(--color-border) px-4 py-2">
                <h2 class="text-sm font-semibold">{{ __('accounts::recon.only_at_the_bank') }}</h2>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('accounts::recon.only_at_the_bank_hint') }}</p>
            </header>

            <x-ui.table :rows="$fromBank" :columns="$bankColumns" :empty="'—'" />
        </section>
    @endif

    <form method="POST" action="{{ route('accounts.reconciliation.mark', $recon) }}">
        @csrf

        <x-ui.table :rows="$lines"
                    :columns="$columns"
                    :empty="__('accounts::recon.empty_lines')" />

        @if ($recon->isDraft() && $lines->isNotEmpty())
            @can('accounts.reconciliation.manage')
                <div class="mt-4">
                    <x-ui.button type="submit">{{ __('accounts::recon.save_ticks') }}</x-ui.button>
                </div>
            @endcan
        @endif
    </form>

    <div class="mt-3 flex flex-wrap gap-2">
        @if ($recon->isDraft())
            @can('accounts.reconciliation.manage')
                {{--
                    বন্ধ করার বোতামটা তফাত শূন্য না হলে থাকে না।
                    সার্ভারেও একই পাহারা আছে, আর সেটাই আসল পাহারা;
                    এটা কেবল যাতে কেউ চেপে ভুল বার্তা না পান।
                --}}
                @if ($summary['agrees'])
                    <form method="POST" action="{{ route('accounts.reconciliation.confirm', $recon) }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">
                            {{ __('accounts::recon.confirm') }}
                        </x-ui.button>
                    </form>
                @endif
            @endcan
        @else
            @can('accounts.reconciliation.reopen')
                <form method="POST" action="{{ route('accounts.reconciliation.reopen', $recon) }}">
                    @csrf
                    <x-ui.button type="submit">{{ __('accounts::recon.reopen') }}</x-ui.button>
                </form>
            @endcan
        @endif
    </div>

</x-layouts.app>
