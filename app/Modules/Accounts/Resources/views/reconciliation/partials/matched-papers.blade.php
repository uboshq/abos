{{-- ⭐ ব্যাংক-সারির কাগজগুলো — এক সারি, কয়েকটা কাগজ (মালিক, ২১ নভেম্বর ২০২৬); পুরনো এক-এক মিল `matched_line_id` থেকে --}}
@php
    $papers = $line->matches->isNotEmpty()
        ? $line->matches->map(fn ($m) => ['no' => $m->voucherLine?->voucher?->document_no, 'amount' => (string) $m->amount])
        : collect([$line->matchedLine])->filter()->map(fn ($l) => [
            'no' => $l->voucher?->document_no,
            'amount' => bccomp((string) $l->debit, '0', 4) > 0 ? (string) $l->debit : (string) $l->credit,
        ]);
@endphp
<span data-matched-papers="{{ $line->id }}">
    @foreach ($papers as $paper)
        <span class="block">{{ $paper['no'] ?? '—' }} · <span class="num">{{ \App\Core\Support\Money::format($paper['amount']) }}</span></span>
    @endforeach
</span>
