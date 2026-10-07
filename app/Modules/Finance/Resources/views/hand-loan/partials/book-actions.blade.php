{{--
    ⭐ খাতার পাশে কাজের দরজা — মালিক, ৫ অক্টোবর ২০২৬: "খাতা থেকে নতুন দেওয়া/নেওয়া"। ⓘ খোলা হিসাব থাকলে তার পাতায়
    (দেওয়া/নেওয়ার ঘর সেখানে, সইয়ের ছকসহ), না থাকলে নতুন হাতধার এই মানুষের নামে। ⓘ আর সব খাত মিলিয়ে তাঁর খতিয়ান।
--}}
@if (! empty($bookPerson))
    @if (! empty($bookAccount))
        @can('finance.hand_loan.move')
            <x-ui.button tone="primary" icon="plus" data-book-move
                         :href="route('finance.hand_loan.show', $bookAccount)">{{ __('finance::loan_ledger.give_or_take') }}</x-ui.button>
        @endcan
    @else
        @can('finance.hand_loan.create')
            <x-ui.button tone="primary" icon="plus" data-book-new
                         :href="route('finance.hand_loan.create', ['person_id' => $bookPerson->id])">{{ __('finance::action.new_hand_loan') }}</x-ui.button>
        @endcan
    @endif
    {{-- ⭐ জের নিশ্চিতকরণের চিঠি — খাতার শেষ তারিখে (পরিকল্পনা ১.৯, ৫ অক্টোবর ২০২৬) --}}
    <x-ui.button icon="print" data-hand-loan-letter target="_blank"
                 :href="route('finance.hand_loan.letter', ['person' => $bookPerson->id, 'as_of' => $result->filters['to'] ?? null])">{{ __('finance::hand_loan_letter.button') }}</x-ui.button>
    @can('accounts.report')
        <x-ui.button icon="book"
                     :href="route('accounts.report.show', ['slug' => 'person-ledger', 'person_id' => $bookPerson->id, 'from' => \App\Core\Engines\Report\ReportEngine::ALL_TIME])">{{ __('finance::loan_ledger.books_total') }}</x-ui.button>
    @endcan
@endif
