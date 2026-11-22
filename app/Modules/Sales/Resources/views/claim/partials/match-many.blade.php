{{--
    ⭐ এক ব্যাংক-সারি, কয়েকটা খবর — টিক দিয়ে (মালিক, ২১ নভেম্বর ২০২৬; [[DepositMatchController::matchLine()]])।
    ⓘ একই খাতের (বা খাতহীন) খোলা খবর, সারির অঙ্কের বেশি নয়; একই রেফারেন্স আগে। টিকের চলতি মোট [[match-total.js]]-এ —
    মোট সারির অঙ্কের সমান হলে তবেই "মেলান" চালু। ⛔ আসল পাহারা সেবায় (মোট হুবহু, প্রতিটা খবর একবার)।
--}}
@php
    $plain = fn ($v) => strtoupper((string) preg_replace('/\s+/', '', trim((string) $v)));
    $choices = $waiting
        ->filter(fn ($c) => ($c->bank_account_id === null || (int) $c->bank_account_id === (int) $line->bank_account_id)
            && bccomp((string) $c->amount, (string) $line->credit, 4) <= 0)
        ->sortBy(fn ($c) => ($line->reference !== null && $plain($c->reference) === $plain($line->reference) ? 0 : 1).'|'.$c->claimed_on?->format('Ymd'))
        ->take(30);
@endphp
@if ($choices->isEmpty())
    <span class="text-2xs text-(--color-ink-muted)">{{ __('sales::deposit.no_waiting_report') }}</span>
@else
    <details data-match-many="{{ $line->id }}">
        <summary class="cursor-pointer text-sm text-(--color-brand-500)">{{ __('sales::deposit.match_reports') }}</summary>
        <form method="POST" action="{{ route('sales.claim.match.many', $line->id) }}" data-match-total
              data-line-amount="{{ $line->credit }}" class="mt-2 grid gap-1">
            @csrf
            @foreach ($choices as $claim)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="claims[]" value="{{ $claim->id }}" data-share="{{ $claim->amount }}">
                    <span class="truncate">{{ $claim->customer?->nameWithPoint() ?? '—' }}</span>
                    <span class="text-2xs text-(--color-ink-muted)">{{ \App\Core\Support\DateFormat::format($claim->claimed_on) }}@if ($claim->reference) · {{ $claim->reference }}@endif</span>
                    <span class="num ms-auto">{{ \App\Core\Support\Money::format($claim->amount) }}</span>
                </label>
            @endforeach
            <div class="flex flex-wrap items-center gap-2 border-t border-(--color-border) pt-1 text-sm">
                <span>{{ __('sales::deposit.ticked_total') }}: <b class="num" data-ticked-total>0.00</b></span>
                <span class="text-(--color-ink-muted)">/ {{ __('sales::deposit.line_amount') }}: <b class="num">{{ \App\Core\Support\Money::format($line->credit) }}</b></span>
                <x-ui.button type="submit" tone="primary" data-match-submit>{{ __('sales::deposit.match') }}</x-ui.button>
            </div>
        </form>
    </details>
@endif
