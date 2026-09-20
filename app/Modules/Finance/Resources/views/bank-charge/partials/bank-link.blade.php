{{--
    ব্যাংকের খাতের নাম → তার খতিয়ান।

    ⓘ একই পথ [[account-analysis/partials/month-link]] আর [[institution/show]]-এ
    — "এই খাতে কী কী বসেছে" প্রশ্নের একটাই উত্তরপাতা।

    ⚠️ মডেল নয়, আইডি আর নাম — কারণ নিচের তালিকায় খাতটা আসে উপ-কোয়েরির
    ছদ্মনাম হয়ে (`$e->bank_id`), গোটা মডেল হয়ে নয়।

    @param $id     খাতের আইডি, নয়তো null
    @param $label  যা লেখা থাকবে
--}}
@if ($id)
    <a href="{{ route('accounts.report.show', ['slug' => 'ledger', 'account_id' => $id]) }}"
       class="text-(--color-brand-600) underline-offset-2 hover:underline">{{ $label }}</a>
@else
    <span class="text-(--color-ink-muted)">{{ __('finance::bank_charge.unknown_bank') }}</span>
@endif
