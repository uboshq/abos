{{--
    ⭐ এক ব্যাংক-সারি, কয়েকটা কাগজ — টিক দিয়ে মেলানো (মালিক, ২১ নভেম্বর ২০২৬; [[BankStatementService::matchBooks()]])।
    ⓘ প্রার্থী: একই খাতের, একই দিকের, ±৭ দিনের পাকা কাগজ; টিকের চলতি মোট [[match-total.js]]-এ — সমান হলে তবেই "মেলান"।
    ⛔ আসল পাহারা সেবায়: মোট হুবহু, প্রতিটা কাগজ একবার, কেবল পাকা কাগজ।
--}}
@php
    $want = bccomp((string) $line->debit, '0', 4) > 0 ? (string) $line->debit : (string) $line->credit;
@endphp
@if ($papers === null || $papers->isEmpty())
    <span class="text-2xs text-(--color-ink-muted)">{{ __('accounts::recon.no_candidates') }}</span>
@else
    <details data-match-papers="{{ $line->id }}">
        <summary class="cursor-pointer text-sm text-(--color-brand-600)">{{ __('accounts::recon.match_papers') }}</summary>
        <form method="POST" action="{{ route('accounts.reconciliation.match_books', [$recon, $line->id]) }}" data-match-total
              data-line-amount="{{ $want }}" class="mt-2 grid gap-1">
            @csrf
            @foreach ($papers as $paper)
                @php($size = bccomp((string) $paper->debit, '0', 4) > 0 ? (string) $paper->debit : (string) $paper->credit)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="lines[]" value="{{ $paper->id }}" data-share="{{ $size }}">
                    <span>{{ $paper->voucher?->document_no }}</span>
                    <span class="text-2xs text-(--color-ink-muted)">{{ \App\Core\Support\DateFormat::format($paper->voucher?->trx_date) }}@if ($paper->voucher?->instrument_no) · {{ $paper->voucher->instrument_no }}@endif</span>
                    <span class="num ms-auto">{{ \App\Core\Support\Money::format($size) }}</span>
                </label>
            @endforeach
            <div class="flex flex-wrap items-center gap-2 border-t border-(--color-border) pt-1 text-sm">
                <span>{{ __('accounts::recon.ticked_total') }}: <b class="num" data-ticked-total>0.00</b></span>
                <span class="text-(--color-ink-muted)">/ {{ __('accounts::recon.line_amount') }}: <b class="num">{{ \App\Core\Support\Money::format($want) }}</b></span>
                <x-ui.button type="submit" tone="primary" data-match-submit>{{ __('accounts::recon.match_selected') }}</x-ui.button>
            </div>
        </form>
    </details>
@endif
