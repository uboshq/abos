{{--
    ⭐ একটা খরচের দাবি বা অগ্রিম অনুরোধ — অবস্থা, অঙ্ক, অগ্রিম থেকে কতটা, নগদে কতটা, আর দুই ভাউচার (মালিকের আদেশ, ৭ অক্টোবর
    ২০২৬)। ⓘ রসিদের ছবি সাধারণ কাগজের বাক্সে ([[components/ui/attachments]])।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $claim->document_no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('hr::claim.kind_'.$claim->kind).' — '.$claim->document_no"
                          :subtitle="__('hr::claim.state_'.$claim->status)" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <section data-claim-facts class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            [__('hr::claim.employee'), (string) $claim->employee?->name()],
            [__('hr::claim.amount'), \App\Core\Support\Money::format($claim->amount)],
            [__('hr::claim.from_advance'), \App\Core\Support\Money::format($claim->from_advance)],
            [__('hr::claim.cash'), \App\Core\Support\Money::format($claim->cashPart())],
        ] as [$label, $value])
            <div class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3">
                <span class="block text-2xs text-(--color-ink-muted)">{{ $label }}</span>
                <span class="num text-lg font-semibold">{{ $value }}</span>
            </div>
        @endforeach
    </section>

    <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm">
        <dl class="grid gap-2 sm:grid-cols-2">
            @foreach (array_filter([
                __('hr::claim.head') => $claim->expenseAccount?->name(),
                __('hr::claim.spent_on') => $claim->spent_on?->format('d/m/Y'),
                __('hr::claim.reason') => $claim->reason,
                __('hr::claim.requested_by') => $claim->requester?->name,
                __('hr::claim.submitted_at') => $claim->created_at?->format('d/m/Y H:i'),
                __('hr::claim.decided_at') => $claim->decided_at?->format('d/m/Y H:i'),
                __('hr::claim.paid_at') => $claim->paid_at?->format('d/m/Y H:i'),
            ], fn ($v) => filled($v)) as $label => $value)
                <div>
                    <dt class="text-2xs text-(--color-ink-muted)">{{ $label }}</dt>
                    <dd>{{ $value }}</dd>
                </div>
            @endforeach

            @foreach (['settle_voucher' => $claim->settleVoucher, 'payment_voucher' => $claim->paymentVoucher] as $key => $voucher)
                @if ($voucher)
                    <div>
                        <dt class="text-2xs text-(--color-ink-muted)">{{ __('hr::claim.'.$key) }}</dt>
                        <dd>
                            <a href="{{ route('accounts.voucher.show', $voucher) }}" class="text-(--color-brand-500) underline-offset-2 hover:underline">
                                {{ $voucher->document_no }}
                            </a>
                        </dd>
                    </div>
                @endif
            @endforeach
        </dl>

        @if ($claim->status === \App\Modules\Hr\Models\ExpenseClaim::APPROVED && $claim->paymentVoucher)
            <p data-cashier-note class="mt-3 rounded-(--radius-field) bg-(--color-badge-warning-bg) px-3 py-2 text-(--color-badge-warning-ink)">
                {{ __('hr::claim.cashier_note') }}
            </p>
        @endif
    </section>

    {{-- ⭐ বাকি অগ্রিম নগদে ফেরত — খসড়া আদায়, ক্যাশিয়ার নিজের টিলে পাকা করেন (টাকার পরিকল্পনা দফা ১৩, ধাপ ৪) --}}
    @if ($claim->status === \App\Modules\Hr\Models\ExpenseClaim::PAID && $openAdvance !== null
        && bccomp((string) $openAdvance, '0', 2) > 0)
        @can('accounts.voucher.create')
            <section data-take-back class="mb-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm">
                <h2 class="mb-2 font-semibold">{{ __('hr::claim.take_back_title') }}</h2>
                <p class="mb-3 text-2xs text-(--color-ink-muted)">
                    {{ __('hr::claim.take_back_note', ['open' => \App\Core\Support\Money::format($openAdvance)]) }}
                </p>
                <form method="POST" action="{{ route('hr.claim.take_back', $claim) }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <x-ui.field name="amount" type="number" step="0.01" inputmode="decimal" :label="__('hr::claim.amount')"
                                :value="old('amount', \App\Core\Support\Money::round($openAdvance, 2))" required />
                    <x-ui.button type="submit" tone="primary">{{ __('hr::claim.take_back') }}</x-ui.button>
                </form>
            </section>
        @endcan
    @endif

    <x-ui.attachments :document="$claim" :slip="true" />
</x-layouts.app>
