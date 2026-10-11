{{--
    ⭐ গ্রাহকের একটা বিল — লাইন, দাম, মোট, শোধ আর বাকি; PDF নামানো আর ছাপা (গ্রাহক পোর্টাল, ধাপ ১)।

    ⓘ মালিকের নিয়ম: বিলটা গ্রাহকের নিজের কাগজ, তাই এখানে দামসহ পুরো বিল। মজুদের কোনো অঙ্ক এখানে নেই।
    ⓘ "এই বিলে যে টাকা বসেছে" — কেবল খাতায় বসা আদায় আর রসিদ, যাতে তালিকার যোগ আর "শোধ"-এর অঙ্ক এক কথা বলে।
--}}
<x-sales::portal.layout :customer="$customer" :title="$bill->document_no">
    @php($due = $bill->dueAmount())
    @php($returned = $bill->returnedAmount())

    <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
        <div>
            <h1 class="text-lg font-semibold">{{ $bill->document_no }}</h1>
            <p class="text-sm text-(--color-ink-muted)">
                {{ \App\Core\Support\DateFormat::format($bill->trx_date) }}
                @if ($bill->due_on)
                    · {{ __('sales::portal_bill.due_on') }} {{ \App\Core\Support\DateFormat::format($bill->due_on) }}
                @endif
            </p>
        </div>

        <div class="flex gap-2">
            <a href="{{ route('sales.portal.bill.pdf', [$bill->public_id, 'download' => 1]) }}" data-portal-bill-pdf
               class="inline-flex h-(--spacing-field) items-center gap-1 rounded-(--radius-field) bg-(--color-brand-500) px-3 text-sm font-medium text-white">
                <x-ui.icon name="download" :size="16" />
                {{ __('sales::portal_bill.pdf') }}
            </a>
            <a href="{{ route('sales.portal.bill.pdf', $bill->public_id) }}" target="_blank" rel="noopener" data-portal-bill-print
               class="inline-flex h-(--spacing-field) items-center gap-1 rounded-(--radius-field) border border-(--color-border) px-3 text-sm font-medium">
                <x-ui.icon name="printer" :size="16" />
                {{ __('sales::portal_bill.print') }}
            </a>
        </div>
    </div>

    {{-- বাকি — পাতার আসল উত্তর --}}
    <dl data-boxed class="mb-4 grid grid-cols-3 gap-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <div>
            <dt class="text-2xs text-(--color-ink-muted)">{{ __('sales::portal_bill.total') }}</dt>
            <dd class="num font-medium">{{ \App\Core\Support\Money::format($bill->total) }}</dd>
        </div>
        <div>
            <dt class="text-2xs text-(--color-ink-muted)">{{ __('sales::portal_bill.paid_amount') }}</dt>
            <dd class="num font-medium" data-portal-bill-paid>{{ \App\Core\Support\Money::format($bill->collectedAmount()) }}</dd>
        </div>
        <div>
            <dt class="text-2xs text-(--color-ink-muted)">{{ __('sales::portal_bill.due') }}</dt>
            <dd class="num text-lg font-semibold" data-portal-bill-due>{{ \App\Core\Support\Money::format($due) }}</dd>
        </div>
        @if (bccomp($returned, '0', 4) > 0)
            <div class="col-span-3 text-sm text-(--color-ink-muted)">
                {{ __('sales::portal_bill.returned') }}: <span class="num">{{ \App\Core\Support\Money::format($returned) }}</span>
            </div>
        @endif
    </dl>

    {{-- লাইন — সরু পর্দায় টেবিল নিজের ভিতরে গড়ায়, পাতা নয় --}}
    <div class="mb-4 overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <table class="ui-list w-full text-sm" data-portal-bill-lines>
            <thead class="bg-(--color-section-head) text-2xs uppercase text-(--color-ink-muted)">
                <tr>
                    <th class="text-start">{{ __('sales::portal_bill.product') }}</th>
                    <th class="text-end">{{ __('sales::portal_bill.qty') }}</th>
                    <th class="text-end">{{ __('sales::portal_bill.rate') }}</th>
                    <th class="text-end">{{ __('sales::portal_bill.discount') }}</th>
                    <th class="text-end">{{ __('sales::portal_bill.amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-(--color-border)">
                @foreach ($bill->lines as $line)
                    <tr>
                        <td>{{ $line->product?->name() }}</td>
                        <td class="num text-end">{{ rtrim(rtrim((string) $line->qty, '0'), '.') ?: '0' }}</td>
                        <td class="num text-end">{{ \App\Core\Support\Money::format($line->rate) }}</td>
                        <td class="num text-end">
                            @if (bccomp((string) $line->discount, '0', 4) > 0)
                                {{ \App\Core\Support\Money::format($line->discount) }}
                            @endif
                        </td>
                        <td class="num text-end">{{ \App\Core\Support\Money::format($line->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="text-sm">
                <tr>
                    <td colspan="4" class="text-end text-(--color-ink-muted)">{{ __('sales::portal_bill.subtotal') }}</td>
                    <td class="num text-end">{{ \App\Core\Support\Money::format($bill->subtotal) }}</td>
                </tr>
                @foreach ([
                    'discount' => bcadd((string) $bill->discount, (string) ($bill->bill_discount ?? '0'), 4),
                    'tax' => (string) $bill->tax,
                    'freight' => (string) ($bill->freight_charge ?? '0'),
                ] as $label => $value)
                    @if (bccomp($value, '0', 4) !== 0)
                        <tr>
                            <td colspan="4" class="text-end text-(--color-ink-muted)">{{ __('sales::portal_bill.'.$label) }}</td>
                            <td class="num text-end">{{ \App\Core\Support\Money::format($value) }}</td>
                        </tr>
                    @endif
                @endforeach
                <tr class="font-semibold">
                    <td colspan="4" class="text-end">{{ __('sales::portal_bill.total') }}</td>
                    <td class="num text-end">{{ \App\Core\Support\Money::format($bill->total) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <h2 class="mb-2 text-sm font-semibold">{{ __('sales::portal_bill.payments') }}</h2>
    @php($payments = $bill->collectionLines->map(fn ($line) => [
        'no' => $line->collection?->document_no, 'date' => $line->collection?->trx_date, 'amount' => $line->amount,
    ])->concat($bill->receiptVouchers->map(fn ($voucher) => [
        'no' => $voucher->document_no, 'date' => $voucher->trx_date, 'amount' => $voucher->amount,
    ]))->sortBy(fn ($row) => (string) $row['date'])->values())

    @if ($payments->isEmpty())
        <p class="mb-4 text-sm text-(--color-ink-muted)">{{ __('sales::portal_bill.no_payments') }}</p>
    @else
        <ul class="mb-4 divide-y divide-(--color-border) rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)"
            data-portal-bill-payments>
            @foreach ($payments as $payment)
                <li class="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                    <span>
                        <span class="block font-medium">{{ $payment['no'] }}</span>
                        <span class="block text-2xs text-(--color-ink-muted)">{{ \App\Core\Support\DateFormat::format($payment['date']) }}</span>
                    </span>
                    <span class="num">{{ \App\Core\Support\Money::format($payment['amount']) }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <a href="{{ route('sales.portal.bill.index') }}" class="mt-4 block text-center text-sm text-(--color-brand-500) hover:underline">
        {{ __('sales::portal_bill.back') }}
    </a>
</x-sales::portal.layout>
