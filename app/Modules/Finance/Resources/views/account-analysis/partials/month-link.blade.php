{{-- মাস → খতিয়ান, ঐ খাত আর ঐ মাসের তারিখ নিয়ে (নিয়ম ১)।

     ⓘ `$text` দিলে ঐ লেখাটাই বসে — একই ঠিকানা, ভিন্ন ঘর: মাসের নাম,
     ডেবিট, ক্রেডিট, আর "কয়টা সারি" (২০ সেপ্টেম্বর ২০২৬)। --}}
<a href="{{ route('accounts.report.show', ['slug' => 'ledger', 'account_id' => $account->id, 'from' => $m['from'], 'to' => $m['to']]) }}"
   class="text-(--color-brand-600) underline-offset-2 hover:underline">{{ $text ?? \Illuminate\Support\Carbon::parse($m['from'])->format('M Y') }}</a>
