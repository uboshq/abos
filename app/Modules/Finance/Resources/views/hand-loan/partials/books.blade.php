{{--
    মোট পাওনা — সব খাত মিলিয়ে, খতিয়ান থেকে; চাপলে তাঁর খতিয়ান-খাতা ([[PartyLedgerReports::PERSON]], হিসাবের রিপোর্টের চাবিতে)।

    ⭐ হাতধারের বাকির সাথে না মিললে নিচে "অন্য খাতে (Dr) 100.00" — মোট বাদ হাতধার, তথ্যের রঙে (মালিক, ৫ অক্টোবর
    ২০২৬: "মেলে না কেন লেখা" — ভুলের মতো শোনাত, অথচ ভুল নেই; টাকাটা কেবল অন্য খাতে, যেমন জাবেদায় প্রাপ্য খাতে তাঁর নামে)।
--}}
@php
    $ledger = $canBooks
        ? route('accounts.report.show', ['slug' => 'person-ledger', 'person_id' => $row['person']->id, 'from' => \App\Core\Engines\Report\ReportEngine::ALL_TIME])
        : null;
@endphp
@if ($ledger)
    <a href="{{ $ledger }}" class="text-(--color-brand-500) underline-offset-2 hover:underline">{{ \App\Core\Support\Money::drCr($row['books']) }}</a>
@else
    <span>{{ \App\Core\Support\Money::drCr($row['books']) }}</span>
@endif
@if ($row['differs'])
    @php($elsewhere = __('finance::loan_ledger.elsewhere', ['amount' => \App\Core\Support\Money::drCr($row['elsewhere'])]))
    <span class="block text-2xs text-(--color-ink-muted)" data-books-elsewhere>
        @if ($ledger)
            <a href="{{ $ledger }}" class="underline-offset-2 hover:underline">{{ $elsewhere }}</a>
        @else
            {{ $elsewhere }}
        @endif
    </span>
@endif
