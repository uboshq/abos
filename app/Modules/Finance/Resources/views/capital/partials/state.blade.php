{{--
    খসড়া হলে "টাকা এসেছে" — আর সেটা এখন একটা **লিংক**, ফর্ম নয়।

    ── ⭐ মালিকের সিদ্ধান্ত, ১৪ সেপ্টেম্বর ২০২৬ ─────────────────────────
    *"ক্যাপিটাল থেকেই টাকা রিসিভ করার ব্যবস্থা করো।"*

    আর তার আগের দিন: *"অর্থে মূলধন লিখে হিসাবে রিসিভ করলেই তো সমাধান —
    এক জায়গায় হয়, এত কী করো?"*

    ⓘ দুইটা মিলে একটাই নিয়ম: **শুরুটা এখান থেকে, গ্রহণটা রসিদে।**

    ── ⛔ আগে এখানে কী ছিল, আর কেন সেটা ভুল ছিল ─────────────────────────
    টেবিলের এই সরু ঘরটার ভিতরে একটা খাত-বাছাইয়ের ড্রপডাউন গোঁজা ছিল,
    সাথে শর্তসাপেক্ষে লেনদেন নম্বর আর চার্জের ঘর। ⚠️ দেখতে খারাপ ছিল
    (মালিক দুইবার বলেছেন), কিন্তু আসল সমস্যা চেহারা নয় —

    **টাকা ঢোকার দুইটা আলাদা পথ তৈরি হয়েছিল।** একটা এখানে, একটা রসিদে।
    ⛔ দুইটা পথ থাকলে একদিন একটায় চার্জের ঘর যোগ হত, অন্যটায় না; একটায়
    ব্যাংকের নাম চাওয়া হত, অন্যটায় না। আর কেউ ধরত না, কারণ দুইটা পথই
    "কাজ করত"।

    ⭐ রসিদের পর্দা এই প্রশ্নগুলো আগে থেকেই করে: কোন খাতে, কোন ব্যাংক,
    কোন হিসাব নম্বর, লেনদেন নম্বর, চার্জ কত, কোন শ্রেণিতে। এখানে
    ওগুলোর একটাও চাওয়া যেত না।

    ── ⚠️ আর সারিটা নিজে নিষ্পন্ন হয় কীভাবে ────────────────────────────
    লিংকটা `against_type=capital_entry` নিয়ে যায়। রসিদ পোস্ট হলে
    [[App\Modules\Accounts\Services\VoucherService]] ওই নামটা
    `drill_sources` দিয়ে খুলে [[CapitalEntry::settleWith()]] ডাকে —
    **পোস্টিংয়ের একই লেনদেনের ভেতরে**, তাই অর্ধেক নিষ্পন্ন সারি থাকে না।
--}}
@if ($entry->status === \App\Modules\Finance\Models\CapitalEntry::POSTED)
    <span class="inline-flex items-center gap-1">
        <span class="rounded-(--radius-field) bg-(--color-badge-success-bg) px-2 py-0.5 text-2xs
                     text-(--color-badge-success-ink)">
            {{ __('finance::state.posted') }}
        </span>

        {{-- ⓘ এখন নম্বরটা একটা লিংক — নিয়ম ১, "সংখ্যা থেকে কাগজে"।
             আগে কেবল খাতের নাম লেখা থাকত, আর কোন রসিদে টাকাটা এসেছে
             সেটা দেখতে হলে ভাউচারের তালিকায় গিয়ে খুঁজতে হত। --}}
        @if ($entry->voucher)
            <a href="{{ route('accounts.voucher.show', $entry->voucher) }}"
               class="text-2xs text-(--color-brand-600) underline-offset-2 hover:underline">
                {{ $entry->voucher->document_no }}
            </a>
        @else
            <span class="text-2xs text-(--color-ink-muted)">{{ $entry->account?->name() }}</span>
        @endif
    </span>
@else
    {{-- ⚠️ অনুমতিটা দেখা হয় এখানেই, কারণ যিনি মূলধনের সারি লিখতে পারেন
         তিনি খাতায় টাকা বসাতে পারবেন এমন নয় — `finance.capital.post`
         আর রসিদ লেখা দুইটা আলাদা ক্ষমতা। ⓘ না দেখালে বোতামটা চাপার পর
         ৪০৩ আসত, আর সেটা ব্যাখ্যা ছাড়া একটা বন্ধ দরজা। --}}
    @can('accounts.voucher.create')
        <x-ui.button tone="primary"
                     :href="route('accounts.voucher.create', [
                         'type' => 'receipt',
                         'against_type' => 'capital_entry',
                         'against_id' => $entry->getKey(),
                         'party_type' => 'person',
                         'party_id' => $entry->person_id,
                         'amount' => $entry->amount,
                         'narration' => $entry->narration,
                     ])">
            {{ __('finance::action.money_arrived') }}
        </x-ui.button>
    @else
        <span class="text-2xs text-(--color-ink-muted)">{{ __('finance::state.draft') }}</span>
    @endcan
@endif
