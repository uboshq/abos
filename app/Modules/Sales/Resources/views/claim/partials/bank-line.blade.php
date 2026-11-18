{{-- ⭐ ব্যাংকের সারি — তারিখ, খাত, রেফারেন্স, এসেছে (জমার খবর ধাপ গ) --}}
@if ($line)
    <span class="block" data-bank-line="{{ $line->id }}">{{ \App\Core\Support\DateFormat::format($line->trx_date) }} · <span class="num">{{ \App\Core\Support\Money::format($line->credit) }}</span></span>
    <span class="block max-w-64 truncate text-2xs text-(--color-ink-muted)" title="{{ $line->description }}">{{ $line->bankAccount?->label() }}@if ($line->reference) · {{ $line->reference }}@endif @if ($line->description) · {{ $line->description }}@endif</span>
@else
    —
@endif
