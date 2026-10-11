{{--
    ⭐ বিবরণী "বিল ধরে" — সময়ের প্রতিটা পাকা বিল, মোট, শোধ, ফেরত আর বাকি (গ্রাহক পোর্টাল, ধাপ ১)।

    ⓘ অঙ্কগুলো বিলের ঘরের সেই একই হিসাব ([[CustomerPapers::billsBetween()]] → [[SalesInvoice::dueAmount()]]) — দুই
    পাতায় একই বিলের দুই রকম বাকি দেখা যায় না। প্রতিটা বিল বিলের ঘরে খোলে।
--}}
@if ($bills->isEmpty())
    <p class="text-sm text-(--color-ink-muted)">{{ __('sales::portal_statement.no_bills') }}</p>
@else
    @php($sum = ['total' => '0', 'paid' => '0', 'returned' => '0', 'due' => '0'])
    <div class="overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <table class="ui-list w-full text-sm" data-portal-statement-bills>
            <thead class="bg-(--color-section-head) text-2xs uppercase text-(--color-ink-muted)">
                <tr>
                    <th class="text-start">{{ __('sales::portal.date') }}</th>
                    <th class="text-start">{{ __('sales::portal_bill.bill_no') }}</th>
                    <th class="text-end">{{ __('sales::portal_bill.total') }}</th>
                    <th class="text-end">{{ __('sales::portal_bill.paid_amount') }}</th>
                    <th class="text-end">{{ __('sales::portal_bill.returned') }}</th>
                    <th class="text-end">{{ __('sales::portal_bill.due') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-(--color-border)">
                @foreach ($bills as $bill)
                    @php($paid = $bill->collectedAmount())
                    @php($returned = $bill->returnedAmount())
                    @php($due = $bill->dueAmount())
                    @php($sum = [
                        'total' => bcadd($sum['total'], (string) $bill->total, 4),
                        'paid' => bcadd($sum['paid'], $paid, 4),
                        'returned' => bcadd($sum['returned'], $returned, 4),
                        'due' => bcadd($sum['due'], $due, 4),
                    ])
                    <tr>
                        <td class="whitespace-nowrap">{{ \App\Core\Support\DateFormat::format($bill->trx_date) }}</td>
                        <td>
                            <a href="{{ route('sales.portal.bill.show', $bill->public_id) }}" data-portal-statement-link="bill"
                               class="text-(--color-brand-500) hover:underline">{{ $bill->document_no }}</a>
                        </td>
                        <td class="num text-end">{{ \App\Core\Support\Money::format($bill->total) }}</td>
                        <td class="num text-end">{{ \App\Core\Support\Money::format($paid) }}</td>
                        <td class="num text-end">{{ \App\Core\Support\Money::format($returned) }}</td>
                        <td class="num text-end font-medium">{{ \App\Core\Support\Money::format($due) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="font-semibold">
                <tr>
                    <td colspan="2">{{ __('sales::portal_statement.bills_total') }}</td>
                    <td class="num text-end">{{ \App\Core\Support\Money::format($sum['total']) }}</td>
                    <td class="num text-end">{{ \App\Core\Support\Money::format($sum['paid']) }}</td>
                    <td class="num text-end">{{ \App\Core\Support\Money::format($sum['returned']) }}</td>
                    <td class="num text-end">{{ \App\Core\Support\Money::format($sum['due']) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
@endif
