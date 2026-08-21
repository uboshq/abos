{{--
    হিসাবের ড্যাশবোর্ড।

    প্রতিটা সংখ্যা ক্লিকযোগ্য (নিয়ম ১)। যে সংখ্যায় ক্লিক করা যায় না
    সেটা এখানে থাকে না — ব্যবহারকারীকে সংখ্যাটা বিশ্বাস করতে বলা হয়
    না, দেখতে দেওয়া হয়।

    উপরে "কী করতে বাকি", তারপর অঙ্কগুলো: খসড়া ভাউচার ও অপেক্ষমাণ
    হস্তান্তর — দুইটাই এমন অবস্থা যা কেউ ইচ্ছাকৃতভাবে রাখে না, ভুলে যায়।
--}}
@php
    $range = ['from' => now()->startOfMonth()->format('Y-m-d'), 'to' => now()->format('Y-m-d')];
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::menu.dashboard') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('accounts::menu.dashboard')"
                          :subtitle="now()->translatedFormat('F Y')" />
    </x-slot:header>

    @if ($draftVouchers > 0 || $pendingTransfers > 0)
        <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-warning)
                        bg-(--color-badge-warning-bg) p-4">
            <h2 class="font-semibold text-(--color-badge-warning-ink)">
                {{ __('accounts::message.needs_attention') }}
            </h2>

            <ul class="mt-2 space-y-1 text-sm text-(--color-badge-warning-ink)">
                @if ($draftVouchers > 0)
                    <li>
                        <a href="{{ route('accounts.voucher.index', ['type' => 'journal', 'status' => 'draft']) }}"
                           class="underline underline-offset-2">
                            {{ trans_choice('accounts::message.draft_vouchers', $draftVouchers, ['count' => $draftVouchers]) }}
                        </a>
                    </li>
                @endif

                @if ($pendingTransfers > 0)
                    <li>
                        <a href="{{ route('accounts.transfer.index') }}" class="underline underline-offset-2">
                            {{ trans_choice('accounts::message.pending_transfers', $pendingTransfers, ['count' => $pendingTransfers]) }}
                        </a>
                    </li>
                @endif
            </ul>
        </section>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['accounts::field.in_hand', $cashInHand, route('accounts.till.index'), 'brand'],
            ['accounts::menu.bank_book', $bankBalance, route('accounts.report.show', ['slug' => 'bank-book'] + $range), 'brand'],
            ['core.accounting.receivable', $receivable, route('accounts.report.show', ['slug' => 'ledger'] + $range), 'receivable'],
            ['core.accounting.payable', $payable, route('accounts.report.show', ['slug' => 'ledger'] + $range), 'payable'],
        ] as [$label, $value, $href, $tone])
            <a href="{{ $href }}"
               class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4
                      shadow-(--shadow-card) transition-colors hover:bg-(--color-surface-hover)">
                <p class="text-sm text-(--color-ink-muted)">{{ __($label) }}</p>
                <p class="num mt-1 text-2xl font-semibold"
                   @if ($tone !== 'brand') style="color: var(--color-{{ $tone }})" @endif>
                    {{ \App\Core\Support\Money::format($value) }}
                </p>
            </a>
        @endforeach
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-3">
        {{-- এই মাসের আয় ও খরচ — লাভ-লোকসানের এক নজর --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="mb-3 font-semibold">{{ __('accounts::menu.profit_loss') }}</h2>

            <dl class="space-y-2">
                @foreach ([
                    'accounts::type.income' => $incomeThisMonth,
                    'accounts::type.expense' => $expenseThisMonth,
                ] as $label => $value)
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-sm text-(--color-ink-muted)">{{ __($label) }}</dt>
                        <dd class="num font-medium">{{ \App\Core\Support\Money::format($value) }}</dd>
                    </div>
                @endforeach

                <div class="flex items-baseline justify-between gap-3 border-t border-(--color-border) pt-2">
                    <dt class="text-sm font-medium">{{ __('accounts::field.net_change') }}</dt>
                    <dd @class([
                        'num font-semibold',
                        'text-(--color-danger)' => bccomp(bcsub($incomeThisMonth, $expenseThisMonth, 4), '0', 4) < 0,
                    ])>
                        {{ \App\Core\Support\Money::format(bcsub($incomeThisMonth, $expenseThisMonth, 4)) }}
                    </dd>
                </div>
            </dl>

            @can('accounts.report.final')
                <p class="mt-3">
                    <a href="{{ route('accounts.report.show', ['slug' => 'profit-loss'] + $range) }}"
                       class="text-sm text-(--color-brand-500) underline-offset-2 hover:underline">
                        {{ __('core.action.more') }} →
                    </a>
                </p>
            @endcan
        </section>

        {{-- কার কাছে কত — ড্যাশবোর্ডে এই প্রশ্নটাই সবচেয়ে বেশি করা হয় --}}
        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) lg:col-span-2">
            <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                {{ __('accounts::menu.cash_tills') }}
            </h2>

            <x-ui.table
                :empty="__('accounts::message.no_tills')"
                :rows="$tills"
                :columns="[
                    ['key' => 'code', 'label' => __('accounts::field.code'), 'width' => '9rem',
                     'render' => fn ($t) => view('accounts::till.partials.code', ['till' => $t])],
                    ['key' => 'name_en', 'label' => __('accounts::field.name'),
                     'render' => fn ($t) => view('accounts::till.partials.name', ['till' => $t])],
                    ['key' => 'holder_id', 'label' => __('accounts::field.holder'), 'width' => '11rem',
                     'render' => fn ($t) => $t->holder?->name ?? '—'],
                    ['key' => 'balance', 'label' => __('accounts::field.in_hand'), 'numeric' => true,
                     'width' => '11rem',
                     'render' => fn ($t) => view('accounts::till.partials.balance', [
                         'till' => $t, 'balance' => $tillBalances[$t->id] ?? '0',
                     ])],
                ]" />
        </section>
    </div>
</x-layouts.app>
