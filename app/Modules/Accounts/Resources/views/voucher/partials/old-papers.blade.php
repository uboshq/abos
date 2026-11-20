{{--
    ⭐ রসিদের তালিকায় আগের আদায় (COL-…) — কেবল দেখা, "আদায় (পুরনো)" দাগে (মালিক, ২০ নভেম্বর ২০২৬: টাকা ঢোকার কাগজ একটাই, রসিদ)।
    ⓘ রসিদের পাতা-ভাঙা থেকে আলাদা ছোট টেবিল, একই ছাঁকনিতে; সারি চাপলে কাগজের নিজের পাতা (দেখার নিয়ম সেই মডিউলের)।
    ⓘ সারিগুলো আসে Core-এর চুক্তি থেকে ([[\App\Core\Contracts\OldReceiptPapers]]) — হিসাব বিক্রয়কে চেনে না।
--}}
@if (($papers['count'] ?? 0) > 0)
    <section class="border-t border-(--color-border)" data-old-papers aria-labelledby="old-papers-title">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 px-3 pt-3">
            <h2 id="old-papers-title" class="text-sm font-semibold">{{ __('accounts::voucher.old_papers') }}</h2>
            <span class="text-xs text-(--color-ink-muted)">{{ __('accounts::voucher.old_papers_note') }}</span>
            @if ($papers['more_url'] ?? null)
                <a href="{{ $papers['more_url'] }}" class="text-xs text-(--color-brand-500) hover:underline" data-old-papers-more>
                    {{ __('accounts::voucher.old_papers_more', ['count' => $papers['count']]) }}
                </a>
            @endif
        </div>
        <div class="overflow-x-auto px-3 pb-3 pt-2">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-(--color-ink-muted)">
                        <th class="py-1 pe-3 font-medium">{{ __('core.print.document_no') }}</th>
                        <th class="py-1 pe-3 font-medium">{{ __('core.print.date') }}</th>
                        <th class="py-1 pe-3 font-medium">{{ __('core.print.party') }}</th>
                        <th class="py-1 pe-3 font-medium">{{ __('accounts::voucher.filter.state') }}</th>
                        <th class="py-1 text-end font-medium">{{ __('core.print.amount') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($papers['rows'] as $row)
                        <tr class="border-t border-(--color-border)" data-old-paper="{{ $row['no'] }}">
                            <td class="py-1.5 pe-3">
                                @if ($row['url'])
                                    <a href="{{ $row['url'] }}" class="text-(--color-brand-500) hover:underline">{{ $row['no'] }}</a>
                                @else
                                    {{ $row['no'] }}
                                @endif
                                <span class="ms-1 rounded-(--radius-pill) bg-(--color-badge-pending-bg) px-2 text-2xs text-(--color-badge-pending-ink)">{{ __('accounts::voucher.old_papers') }}</span>
                            </td>
                            <td class="py-1.5 pe-3 whitespace-nowrap">{{ \App\Core\Support\DateFormat::format($row['date']) }}</td>
                            <td class="py-1.5 pe-3">{{ $row['party'] }}</td>
                            <td class="py-1.5 pe-3">{{ $row['status'] }}</td>
                            <td class="num py-1.5 text-end">{{ \App\Core\Support\Money::format($row['amount']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-(--color-border) font-semibold">
                        <td colspan="4" class="py-1.5 pe-3">{{ __('accounts::voucher.old_papers_total') }}</td>
                        <td class="num py-1.5 text-end">{{ \App\Core\Support\Money::format($papers['total'] ?? '0') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
@endif
