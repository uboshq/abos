<x-ui.amount :value="$value" />
{{-- ⭐ দাবির বাছা বিল — গ্রহণের আগে হিসাবরক্ষক দেখেন কোথায় মিলবে (টাকার পরিকল্পনা ২, ৭ অক্টোবর ২০২৬) --}}
@if (isset($claim) && ($bills = \App\Modules\Sales\Http\Controllers\DepositRequestController::billFacts($claim)) !== [])
    <span class="block text-2xs text-(--color-ink-muted)" data-claim-bills>
        {{ __('sales::slip.bills_named') }}: @foreach ($bills as $bill){{ $loop->first ? '' : ' · ' }}{{ $bill['no'] }} {{ \App\Core\Support\Money::format($bill['amount']) }}@endforeach
    </span>
@endif
