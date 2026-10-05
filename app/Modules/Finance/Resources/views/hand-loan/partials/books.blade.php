{{--
    মোট পাওনা — সব খাত মিলিয়ে, খতিয়ান থেকে; চাপলে তাঁর খতিয়ান-খাতা ([[PartyLedgerReports::PERSON]], হিসাবের রিপোর্টের চাবিতে)।
    ⚠️ হাতধারের বাকির সাথে না মিললে ছোট সতর্কতা — কোনটা ভুল তা নয়, দুই খাতা দুই কথা বলছে সেটা।
--}}
@php($text = \App\Core\Support\Money::drCr($row['books']))
@if ($canBooks)
    <a href="{{ route('accounts.report.show', ['slug' => 'person-ledger', 'person_id' => $row['person']->id, 'from' => \App\Core\Engines\Report\ReportEngine::ALL_TIME]) }}"
       class="text-(--color-brand-500) underline-offset-2 hover:underline">{{ $text }}</a>
@else
    <span>{{ $text }}</span>
@endif
@if ($row['differs'])
    <span class="ms-1 text-2xs text-(--color-badge-warning-ink)" data-books-differ title="{{ __('finance::loan_ledger.differs') }}">⚠ {{ __('finance::loan_ledger.differs_short') }}</span>
@endif
