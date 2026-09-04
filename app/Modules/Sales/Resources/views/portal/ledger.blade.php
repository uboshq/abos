{{--
    গ্রাহকের নিজের খতিয়ান।

    ── কেন উপরে দুইটা সংখ্যা, তালিকা তার পরে ────────────────────────────
    গ্রাহক এই পাতায় আসেন একটা প্রশ্ন নিয়ে — "আমার কত বাকি"। উত্তরটা
    উপরে, বড় করে। তালিকাটা তার **ব্যাখ্যা**, আর ব্যাখ্যা লাগে কেবল যখন
    সংখ্যাটা মেলে না।

    ── মজুদের কিছু এখানে নেই, আর সেটা ইচ্ছাকৃত ──────────────────────────
    মালিকের সিদ্ধান্ত (৩ সেপ্টেম্বর ২০২৬): গ্রাহক মজুদ দেখবেন না। খতিয়ানে
    পণ্যের নাম আসে, কিন্তু **কত আছে** কোথাও নয়।
--}}
<x-sales::portal.layout :customer="$customer">

    {{-- বকেয়া — পাতার আসল উত্তর --}}
    <div data-boxed class="mb-3 rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) px-4 py-4">
        <p class="text-2xs uppercase tracking-wide text-(--color-ink-muted)">
            {{ __('sales::portal.due') }}
        </p>
        <p class="num text-3xl font-semibold">{{ \App\Core\Support\Money::format($closing) }}</p>
    </div>

    {{--
        ক্রেডিট-সীমা — কেবল কোম্পানি সুইচটা চালু রাখলে।

        ⚠️ বন্ধ থাকলে "০" দেখানো যাবে না। গ্রাহক পড়তেন **তাঁর সীমা শেষ**,
        তারপর ফোন করতেন — অর্থাৎ এই পোর্টালের গোটা উদ্দেশ্যের উল্টো।

        আর সীমা ০ মানেও "শেষ" নয়: মালিকের নিয়মে ওটা **"বাকিতে নয়"**,
        "মাল নয়" নয়। তাই লেখাটা "নগদ/অগ্রিম", একটা শূন্য নয় — সরাসরি
        বিক্রয়ের পর্দাতেও একই ভাষা।
    --}}
    @if ($creditLimitOn)
        <div data-boxed class="mb-5 flex items-center justify-between gap-3 rounded-(--radius-card)
                    border border-(--color-border) bg-(--color-surface-muted) px-4 py-3">
            <span class="text-sm text-(--color-ink-muted)">{{ __('sales::portal.credit_limit') }}</span>

            <span class="num text-sm font-medium">
                @if (bccomp((string) $customer->credit_limit, '0', 4) > 0)
                    {{ \App\Core\Support\Money::format($customer->credit_limit) }}
                @else
                    {{ __('sales::portal.cash_only') }}
                @endif
            </span>
        </div>
    @endif

    {{-- ছাঁকনি — GET, কারণ এটা দেখা, কোনো কাজ নয় --}}
    <form method="GET" action="{{ route('sales.portal.ledger') }}"
          class="mb-4 flex flex-wrap items-end gap-2">
        <label class="flex-1">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::portal.from') }}</span>
            {{--
                ⚠️ কাঁচা `<input type="date">` নয়।

                ব্রাউজার ওটার ভেতরের লেখা **নিজের লোকেল ধরে** আঁকে, আর
                CSS দিয়ে বদলানো যায় না — ফলে ইংরেজি (US) মেশিনে
                ১৯ আগস্ট দেখাত `08/19/2026`, অথচ অ্যাপের বাকি সব
                জায়গায় `19-08-2026`। ⓘ গ্রাহক তাঁর নিজের ফোনে এই পাতাটা
                খোলেন, তাই লোকেল আমাদের হাতে নেই — এখানে ঝুঁকিটা
                কর্মীর পর্দার চেয়ে বেশি।
            --}}
            <x-ui.date name="from" :value="$from" />
        </label>

        <label class="flex-1">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::portal.to') }}</span>
            <x-ui.date name="to" :value="$to" />
        </label>

        <button type="submit"
                class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500)
                       px-4 text-sm font-medium text-white">
            {{ __('sales::portal.show') }}
        </button>
    </form>

    @if ($rows->isEmpty())
        <p class="text-sm text-(--color-ink-muted)">{{ __('sales::portal.no_entries') }}</p>
    @else
        {{-- সরু পর্দায় টেবিল নিজের ভিতরে গড়ায়, পাতা নয় --}}
        <div class="overflow-x-auto rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
            {{--
                ⚠️ `ui-list` ক্লাসটা অলংকার নয় — ওটাই সারির উচ্চতা ও
                ঘরের ফাঁক ঠিক করে, আর থিম বদলালে (ঘন/স্বাভাবিক) সেটার
                সাথে বদলায়। ক্লাসটা না দিলে এই একটা টেবিল থিমের বাইরে
                একা দাঁড়িয়ে থাকত।

                ⓘ `x-ui.table` কম্পোনেন্টটা ব্যবহার করা হয়নি ইচ্ছাকৃতভাবে:
                এখানে একটা **খোলার সারি** লাগে যেটা কোনো ডেটা-সারি নয়,
                আর চলমান জেরের কলামটা আগের সারির উপর নির্ভর করে। ওটা
                কলামের তালিকায় প্রকাশ করা যায় না।
            --}}
            <table class="ui-list w-full text-sm">
                <thead class="bg-(--color-section-head) text-2xs uppercase text-(--color-ink-muted)">
                    <tr>
                        <th class="text-start">{{ __('sales::portal.date') }}</th>
                        <th class="text-start">{{ __('sales::portal.particulars') }}</th>
                        <th class="text-end">{{ __('sales::portal.debit') }}</th>
                        <th class="text-end">{{ __('sales::portal.credit') }}</th>
                        <th class="text-end">{{ __('sales::portal.balance') }}</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-(--color-border)">
                    {{--
                        খোলার সারি — ছাঁকনির আগের সবকিছুর নিট।

                        ⚠️ এটা না দেখালে ব্যালান্সের কলাম শূন্য থেকে শুরু
                        হত আর গ্রাহক পড়তেন "আমার কোনো বকেয়া ছিল না",
                        যেটা প্রায় সবসময়ই মিথ্যা।
                    --}}
                    <tr class="bg-(--color-surface-muted) text-(--color-ink-muted)">
                        <td >—</td>
                        <td >{{ __('sales::portal.opening') }}</td>
                        <td ></td>
                        <td ></td>
                        <td class="num text-end">
                            {{ \App\Core\Support\Money::format($opening) }}
                        </td>
                    </tr>

                    @foreach ($rows as $row)
                        <tr>
                            <td class="whitespace-nowrap">
                                {{ \App\Core\Support\DateFormat::format($row->trx_date) }}
                            </td>

                            {{--
                                কাগজের নম্বর — আর ওটাই "কেন এত" প্রশ্নের উত্তর।
                                গ্রাহক নম্বরটা নিজের কাগজের সাথে মিলিয়ে নেন।

                                ⓘ লিংক নয়, কেবল নম্বর: ওই ডকুমেন্টের পর্দাগুলো
                                কর্মীর, আর সেখানে দর ও মজুদ দুইটাই থাকে।
                                গ্রাহককে ওখানে পাঠানো মানে ঠিক যা লুকানোর কথা
                                তাই দেখানো।

                                ⚠️ ── কেন `$row->drill()` ডাকা হয় না ──────────
                                ওটা উৎস-ডকুমেন্টের **মডেল লোড করে**, আর
                                Sales-এর মডেলগুলোয় `ScopedToUserBranch`
                                গ্লোবাল স্কোপ আছে। ওই স্কোপ `auth()->user()`
                                নেয় আর `DataScope::idsFor(User …)`-কে দেয় —
                                কিন্তু পোর্টালে লগইন করেন একজন **Customer**,
                                `User` নয়। ফল: `TypeError`, আর গোটা পাতা ৫০০।

                                টেস্টে ধরা পড়েছে (৩ সেপ্টেম্বর ২০২৬)। এখানে
                                নম্বরটা `ledger_entries`-এর নিজের কলামেই আছে,
                                তাই কোনো মডেল লোড করার দরকারই নেই — আর তাতে
                                পঞ্চাশ সারিতে পঞ্চাশটা কোয়েরিও বাঁচে।
                            --}}
                            <td >
                                {{ $row->document_no ?: $row->narration }}
                            </td>

                            <td class="num text-end">
                                @if (bccomp((string) $row->debit, '0', 4) > 0)
                                    {{ \App\Core\Support\Money::format($row->debit) }}
                                @endif
                            </td>

                            <td class="num text-end">
                                @if (bccomp((string) $row->credit, '0', 4) > 0)
                                    {{ \App\Core\Support\Money::format($row->credit) }}
                                @endif
                            </td>

                            <td class="num text-end font-medium">
                                {{ \App\Core\Support\Money::format($row->running_balance) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-sales::portal.layout>
