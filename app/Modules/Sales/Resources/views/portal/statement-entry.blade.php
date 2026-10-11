{{--
    ⭐ বিবরণীর একটা সারির কাগজ — আদায়, ফেরত, ভাউচার, খোলার জের (গ্রাহক পোর্টাল, ধাপ ১; [[PortalStatementController::entry()]])।

    ⓘ কেবল এই গ্রাহকের খাতায় বসা অংশ — একই কাগজের অন্য পায়ের সারি (ব্যাংক, আয়, অন্য পার্টি) এখানে নেই।
    ⓘ আদায় হলে কোন কোন বিল শোধ হলো, আর প্রতিটা বিল বিলের ঘরে খোলে।
--}}
<x-sales::portal.layout :customer="$customer" :title="$entry->document_no ?: __('sales::portal_statement.entry_title')">
    <h1 class="text-lg font-semibold">{{ $entry->document_no ?: __('sales::portal_statement.entry_title') }}</h1>
    <p class="mb-3 text-sm text-(--color-ink-muted)">{{ \App\Core\Support\DateFormat::format($entry->trx_date) }}</p>

    <div class="mb-4 overflow-x-auto rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <table class="ui-list w-full text-sm" data-portal-entry-lines>
            <thead class="bg-(--color-section-head) text-2xs uppercase text-(--color-ink-muted)">
                <tr>
                    <th class="text-start">{{ __('sales::portal.particulars') }}</th>
                    <th class="text-end">{{ __('sales::portal.debit') }}</th>
                    <th class="text-end">{{ __('sales::portal.credit') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-(--color-border)">
                @foreach ($lines as $line)
                    <tr>
                        <td>{{ $line->narration ?: $line->document_no }}</td>
                        <td class="num text-end">
                            @if (bccomp((string) $line->debit, '0', 4) > 0)
                                {{ \App\Core\Support\Money::format($line->debit) }}
                            @endif
                        </td>
                        <td class="num text-end">
                            @if (bccomp((string) $line->credit, '0', 4) > 0)
                                {{ \App\Core\Support\Money::format($line->credit) }}
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($paid->isNotEmpty())
        <h2 class="mb-2 text-sm font-semibold">{{ __('sales::portal_statement.paid_bills') }}</h2>
        <ul class="mb-4 divide-y divide-(--color-border) rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)"
            data-portal-entry-bills>
            @foreach ($paid as $line)
                <li class="flex items-center justify-between gap-3 px-4 py-2 text-sm">
                    @if (isset($billLinks[(int) $line->sales_invoice_id]))
                        <a href="{{ route('sales.portal.bill.show', $billLinks[(int) $line->sales_invoice_id]) }}"
                           class="font-medium text-(--color-brand-500) hover:underline">{{ $line->invoice?->document_no }}</a>
                    @else
                        <span class="font-medium">{{ $line->invoice?->document_no }}</span>
                    @endif
                    <span class="num">{{ \App\Core\Support\Money::format($line->amount) }}</span>
                </li>
            @endforeach
        </ul>
    @endif

    <a href="{{ route('sales.portal.ledger') }}" class="mt-4 block text-center text-sm text-(--color-brand-500) hover:underline">
        {{ __('sales::portal_statement.back') }}
    </a>
</x-sales::portal.layout>
