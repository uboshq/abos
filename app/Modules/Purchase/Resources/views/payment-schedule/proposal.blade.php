{{-- ⭐ পরিশোধের প্রস্তাব — খসড়া, অনুমোদন, পরিশোধ (টাকা আসা-যাওয়ার পরিকল্পনা, ধাপ খ ১১, ৭ অক্টোবর ২০২৬) --}}
@php
    use App\Core\Support\Money;
    use App\Core\Support\DocumentStatus;
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $no }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$no" :subtitle="__('purchase::schedule.proposal_title')">
            <x-slot:actions>
                <x-ui.button tone="secondary" :href="route('purchase.payment.index', ['proposal' => $no])">{{ __('purchase::schedule.proposal_in_list') }}</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <p role="status" class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    <div data-boxed data-payment-proposal-page class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <p class="border-b border-(--color-border) px-4 py-2 text-sm text-(--color-ink-muted)">
            {{ __('purchase::schedule.proposed_by', ['name' => $payments->first()->creator?->name ?? '—', 'date' => \App\Core\Support\DateFormat::format($payments->first()->trx_date)]) }}
        </p>
        <table class="ui-list w-full text-sm">
            <thead>
                <tr class="border-b border-(--color-border) text-left text-(--color-ink-muted)">
                    <th class="px-3 py-2">{{ __('purchase::doc.payment') }}</th>
                    <th class="px-3 py-2">{{ __('purchase::schedule.supplier') }}</th>
                    <th class="px-3 py-2">{{ __('purchase::schedule.bill') }}</th>
                    <th class="px-3 py-2 text-right">{{ __('purchase::schedule.due') }}</th>
                    <th class="px-3 py-2">{{ __('purchase::schedule.proposal_state') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($payments as $payment)
                    @php
                        $approval = $approvalOf[$payment->id] ?? null;
                        $state = match (true) {
                            $payment->status === DocumentStatus::CONFIRMED => 'paid',
                            $payment->status === DocumentStatus::CANCELLED => 'cancelled',
                            $approval?->status === \App\Models\Approval::PENDING => 'awaiting_signature',
                            $approval?->status === \App\Models\Approval::APPROVED => 'signed_awaiting_payment',
                            $approval?->status === \App\Models\Approval::REJECTED => 'refused',
                            default => 'draft',
                        };
                    @endphp
                    <tr class="border-b border-(--color-border)/60" data-proposal-row="{{ $payment->id }}" data-proposal-state="{{ $state }}">
                        <td class="px-3 py-2"><a class="text-(--color-brand-600) hover:underline" href="{{ route('purchase.payment.show', $payment) }}">{{ $payment->document_no }}</a></td>
                        <td class="px-3 py-2">{{ $payment->supplier?->name() }}</td>
                        <td class="px-3 py-2">{{ $payment->lines->map(fn ($l) => $l->bill?->document_no)->filter()->implode(', ') }}</td>
                        <td class="num px-3 py-2 text-right">{{ Money::format((string) $payment->amount) }}</td>
                        <td class="px-3 py-2">{{ __('purchase::schedule.state_'.$state) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-layouts.app>
