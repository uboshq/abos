{{-- মাস → খতিয়ান, ঐ খাত আর ঐ মাসের তারিখ নিয়ে (নিয়ম ১) --}}
<a href="{{ route('accounts.report.show', ['slug' => 'ledger', 'account_id' => $account->id, 'from' => $m['from'], 'to' => $m['to']]) }}"
   class="text-(--color-brand-600) underline-offset-2 hover:underline">{{ \Illuminate\Support\Carbon::parse($m['from'])->format('M Y') }}</a>
