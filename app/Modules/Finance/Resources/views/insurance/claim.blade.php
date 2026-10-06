{{--
    ⭐ একটা বীমা দাবির পাতা — অর্থ-মডিউলের পরিকল্পনা ৬.৪ ([[InsuranceClaimService]])।

    ⓘ উপরে দাবির ছয়টা সংখ্যা আর অবস্থা; নিচে চার কাজ — লিখিত অনুমোদন, টাকা এল, বন্ধ, নাকচ — খোলা দাবিতে আর কেবল
    বীমা চালানোর চাবিতে। জমা থাকা দাবি খাতায় নেই (IAS 37) — তাই "খাতায়" লাইনটা বলে কোন ভাউচারে উঠল।
--}}
@php
    use App\Core\Support\Money;
    use App\Modules\Finance\Models\InsuranceClaim;

    $policy = $claim->policy;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::insurance_claim.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::insurance_claim.title').' — '.($claim->claim_no ?: $policy?->policy_no)"
                          :subtitle="trim(($policy?->policy_no ?? '').' · '.($policy?->subject ?? '').' · '.($policy?->institution?->label() ?? ''), ' ·')">
            <x-slot:actions>
                @if ($policy)
                    <x-ui.button tone="secondary" :href="route('finance.insurance.show', $policy)">
                        {{ __('finance::insurance_claim.back_to_policy') }}
                    </x-ui.button>
                @endif
                <x-ui.button tone="secondary" :href="route('finance.report.show', ['slug' => 'insurance-claims'])">
                    {{ __('finance::insurance_claim.register') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @foreach (['saved' => 'success', 'warning' => 'pending'] as $flash => $tone)
        @if (session($flash))
            <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-{{ $tone }}-bg) px-3 py-2
                                    text-sm text-(--color-badge-{{ $tone }}-ink)">{{ session($flash) }}</p>
        @endif
    @endforeach

    @if ($errors->any())
        <div role="alert" class="mb-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <section data-boxed data-insurance-claim
             class="mb-4 max-w-screen-2xl rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <p class="mb-3 text-sm">
            <x-ui.badge :tone="match ($claim->status) {
                InsuranceClaim::SETTLED => 'success', InsuranceClaim::REJECTED => 'danger', InsuranceClaim::LODGED => 'pending', default => 'info',
            }">{{ __('finance::insurance_claim.state_'.$claim->status) }}</x-ui.badge>
            <span class="ms-2">{{ $claim->incident }}</span>
        </p>

        <dl class="grid gap-3 text-sm sm:grid-cols-3 xl:grid-cols-6">
            @foreach ([
                'incident_on' => $claim->incident_on->format('d/m/Y'),
                'claimed_on' => $claim->claimed_on->format('d/m/Y'),
                'claimed_amount' => Money::format($claim->claimed_amount),
                'approved_amount' => $claim->approved_amount === null ? '—' : Money::format($claim->approved_amount),
                'received_amount' => Money::format($claim->received_amount),
                'outstanding' => Money::format($claim->outstanding()),
            ] as $key => $value)
                <div>
                    <dt class="text-2xs text-(--color-ink-muted)">{{ __('finance::insurance_claim.'.$key) }}</dt>
                    <dd class="font-semibold tabular-nums" data-claim-{{ $key }}>{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        <p class="mt-3 text-2xs text-(--color-ink-muted)">
            @if ($claim->approval_ref)
                {{ __('finance::insurance_claim.approved_by_letter', ['ref' => $claim->approval_ref, 'date' => $claim->approved_on?->format('d/m/Y')]) }}
                @if ($claim->approvalVoucher) · {{ $claim->approvalVoucher->document_no }} @endif
            @else
                {{ __('finance::insurance_claim.not_in_books_yet') }}
            @endif
            @if ($claim->closed_on)
                · {{ __('finance::insurance_claim.closed_note', ['date' => $claim->closed_on->format('d/m/Y'), 'note' => $claim->close_note]) }}
                @if ($claim->closeVoucher) · {{ $claim->closeVoucher->document_no }} @endif
            @endif
        </p>
    </section>

    {{-- ⓘ টাকা আসার রসিদগুলো — খসড়া (সইয়ের অপেক্ষায়) সহ --}}
    @if ($receipts->isNotEmpty())
        <section data-boxed class="mb-4 max-w-screen-2xl overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <h2 class="border-b border-(--color-border) px-3 py-2 text-sm font-semibold">{{ __('finance::insurance_claim.receipts') }}</h2>
            <x-ui.table :rows="$receipts" :empty="'—'" :columns="[
                ['key' => 'trx_date', 'label' => __('finance::insurance_claim.received_on'), 'width' => '8rem',
                 'render' => fn ($v) => $v->trx_date->format('d/m/Y')],
                ['key' => 'document_no', 'label' => __('finance::insurance_claim.voucher'),
                 'render' => fn ($v) => $v->document_no],
                ['key' => 'status', 'label' => __('finance::insurance_claim.voucher_state'), 'width' => '8rem',
                 'render' => fn ($v) => __('core.status.'.$v->status)],
                ['key' => 'amount', 'label' => __('finance::insurance_claim.amount'), 'numeric' => true, 'width' => '10rem',
                 'render' => fn ($v) => Money::format($v->totals()['debit'])],
            ]" />
        </section>
    @endif

    @can('finance.insurance.manage')
        @if ($claim->isOpen())
            <div class="grid max-w-screen-2xl gap-4 xl:grid-cols-2">
                {{-- ⭐ টাকা এল — রসিদ ভাউচার, হিসাবের রসিদের সইয়ের নিয়মে --}}
                @if (bccomp($claim->outstanding(), '0', 4) > 0)
                    <section data-boxed data-claim-money-in class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                        <h2 class="mb-1 text-sm font-semibold">{{ __('finance::insurance_claim.receive') }}</h2>
                        <p class="mb-3 text-2xs text-(--color-ink-muted)">{{ __('finance::insurance_claim.receive_note', ['code' => $head->code, 'name' => $head->name()]) }}</p>
                        <form method="POST" action="{{ route('finance.insurance.claim.receive', $claim) }}" class="grid gap-3 sm:grid-cols-2">
                            @csrf
                            <x-ui.field name="amount" type="number" step="0.01" numeric required
                                        :label="__('finance::insurance_claim.amount')" :value="old('amount', bcadd($claim->outstanding(), '0', 2))" />
                            <x-ui.field name="received_on" type="date" required
                                        :label="__('finance::insurance_claim.received_on')" :value="old('received_on', now()->toDateString())" />
                            <x-ui.money-account name="money_account_id" required :label="__('finance::field.money_account')"
                                                :accounts="$accounts" :selected="old('money_account_id')" />
                            <div class="flex items-end sm:col-span-2">
                                <x-ui.button type="submit" tone="primary">{{ __('finance::insurance_claim.receive') }}</x-ui.button>
                            </div>
                        </form>
                    </section>
                @endif

                {{-- ⭐ লিখিত অনুমোদন — চিঠির নম্বর ছাড়া নয়; একবারই --}}
                @if ($claim->approved_amount === null)
                    <section data-boxed data-claim-approve class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                        <h2 class="mb-1 text-sm font-semibold">{{ __('finance::insurance_claim.approve') }}</h2>
                        <p class="mb-3 text-2xs text-(--color-ink-muted)">{{ __('finance::insurance_claim.approve_note') }}</p>
                        <form method="POST" action="{{ route('finance.insurance.claim.approve', $claim) }}" class="grid gap-3 sm:grid-cols-3">
                            @csrf
                            <x-ui.field name="approved_amount" type="number" step="0.01" numeric required
                                        :label="__('finance::insurance_claim.approved_amount')" :value="old('approved_amount')" />
                            <x-ui.field name="approved_on" type="date" required
                                        :label="__('finance::insurance_claim.approved_on')" :value="old('approved_on', now()->toDateString())" />
                            <x-ui.field name="approval_ref" required
                                        :label="__('finance::insurance_claim.approval_ref')" :value="old('approval_ref')" />
                            <div class="flex items-end sm:col-span-3">
                                <x-ui.button type="submit" tone="secondary">{{ __('finance::insurance_claim.approve') }}</x-ui.button>
                            </div>
                        </form>
                    </section>
                @endif

                {{-- ⭐ বন্ধ (বাকিটা আর আসবে না) বা নাকচ (কিছুই আসেনি) --}}
                <section data-boxed data-claim-close class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 xl:col-span-2">
                    <h2 class="mb-1 text-sm font-semibold">{{ __('finance::insurance_claim.finish') }}</h2>
                    <p class="mb-3 text-2xs text-(--color-ink-muted)">{{ __('finance::insurance_claim.finish_note') }}</p>
                    <form method="POST" class="grid gap-3 sm:grid-cols-3">
                        @csrf
                        <x-ui.field name="close_note" required :label="__('finance::insurance_claim.close_note')" :value="old('close_note')" />
                        <x-ui.field name="closed_on" type="date" required
                                    :label="__('finance::insurance_claim.closed_on')" :value="old('closed_on', now()->toDateString())" />
                        <div class="flex flex-wrap items-end gap-2">
                            @if (bccomp((string) $claim->received_amount, '0', 4) > 0)
                                <x-ui.button type="submit" tone="secondary" formaction="{{ route('finance.insurance.claim.close', $claim) }}">
                                    {{ __('finance::insurance_claim.close') }}
                                </x-ui.button>
                            @else
                                <x-ui.button type="submit" tone="danger" formaction="{{ route('finance.insurance.claim.reject', $claim) }}">
                                    {{ __('finance::insurance_claim.reject') }}
                                </x-ui.button>
                            @endif
                        </div>
                    </form>
                </section>
            </div>
        @endif
    @endcan
</x-layouts.app>
