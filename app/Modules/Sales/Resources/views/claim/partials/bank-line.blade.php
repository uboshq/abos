{{-- ⭐ ব্যাংকের সারি — তারিখ, খাত, রেফারেন্স, এসেছে (জমার খবর ধাপ গ) --}}
@if ($line)
    <span class="block" data-bank-line="{{ $line->id }}">{{ \App\Core\Support\DateFormat::format($line->trx_date) }} · <span class="num">{{ \App\Core\Support\Money::format($line->credit) }}</span></span>
    @if (($shared ?? 0) > 1)
        {{-- ⭐ ভাগের সারি — এক ব্যাংক-সারি, কয়েকটা খবর (মালিক, ২১ নভেম্বর ২০২৬) --}}
        <span class="block text-2xs text-(--color-badge-info-ink)" data-shared-line="{{ $shared }}">{{ __('sales::deposit.shared_line', ['count' => $shared]) }}</span>
    @endif
    <span class="block max-w-64 truncate text-2xs text-(--color-ink-muted)" title="{{ $line->description }}">{{ $line->bankAccount?->label() }}@if ($line->reference) · {{ $line->reference }}@endif @if ($line->description) · {{ $line->description }}@endif</span>
@else
    —
@endif
