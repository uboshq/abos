{{--
    ডিলারের নিজের খতিয়ান।

    ── কেন উপরে দুইটা সংখ্যা, তালিকা তার পরে ────────────────────────────
    ডিলার এই পাতায় আসেন একটা প্রশ্ন নিয়ে — "আমার কত বাকি"। উত্তরটা
    উপরে, বড় করে। তালিকাটা তার **ব্যাখ্যা**, আর ব্যাখ্যা লাগে কেবল যখন
    সংখ্যাটা মেলে না।

    ── মজুদের কিছু এখানে নেই, আর সেটা ইচ্ছাকৃত ──────────────────────────
    মালিকের সিদ্ধান্ত (৩ সেপ্টেম্বর ২০২৬): ডিলার মজুদ দেখবেন না। খতিয়ানে
    পণ্যের নাম আসে, কিন্তু **কত আছে** কোথাও নয়।
--}}
<x-sales::portal.layout :dealer="$dealer">

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

        ⚠️ বন্ধ থাকলে "০" দেখানো যাবে না। ডিলার পড়তেন **তাঁর সীমা শেষ**,
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
                @if (bccomp((string) $dealer->credit_limit, '0', 4) > 0)
                    {{ \App\Core\Support\Money::format($dealer->credit_limit) }}
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
            <input type="date" name="from" value="{{ $from }}"
                   class="h-(--spacing-field) w-full rounded-(--radius-field) border
                          border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
        </label>

        <label class="flex-1">
            <span class="mb-1 block text-2xs text-(--color-ink-muted)">{{ __('sales::portal.to') }}</span>
            <input type="date" name="to" value="{{ $to }}"
                   class="h-(--spacing-field) w-full rounded-(--radius-field) border
                          border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
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
            <table class="w-full text-sm">
                <thead class="bg-(--color-section-head) text-2xs uppercase text-(--color-ink-muted)">
                    <tr>
                        <th class="px-3 py-2 text-start">{{ __('sales::portal.date') }}</th>
                        <th class="px-3 py-2 text-start">{{ __('sales::portal.particulars') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('sales::portal.debit') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('sales::portal.credit') }}</th>
                        <th class="px-3 py-2 text-end">{{ __('sales::portal.balance') }}</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-(--color-border)">
                    {{--
                        খোলার সারি — ছাঁকনির আগের সবকিছুর নিট।

                        ⚠️ এটা না দেখালে ব্যালান্সের কলাম শূন্য থেকে শুরু
                        হত আর ডিলার পড়তেন "আমার কোনো বকেয়া ছিল না",
                        যেটা প্রায় সবসময়ই মিথ্যা।
                    --}}
                    <tr class="bg-(--color-surface-muted) text-(--color-ink-muted)">
                        <td class="px-3 py-2">—</td>
                        <td class="px-3 py-2">{{ __('sales::portal.opening') }}</td>
                        <td class="px-3 py-2"></td>
                        <td class="px-3 py-2"></td>
                        <td class="num px-3 py-2 text-end">
                            {{ \App\Core\Support\Money::format($opening) }}
                        </td>
                    </tr>

                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-3 py-2 whitespace-nowrap">
                                {{ \App\Core\Support\DateFormat::format($row->trx_date) }}
                            </td>

                            {{--
                                কাগজের নম্বর — আর ওটাই "কেন এত" প্রশ্নের উত্তর।
                                ডিলার নম্বরটা নিজের কাগজের সাথে মিলিয়ে নেন।

                                ⓘ লিংক নয়, কেবল নম্বর: ওই ডকুমেন্টের পর্দাগুলো
                                কর্মীর, আর সেখানে দর ও মজুদ দুইটাই থাকে।
                                ডিলারকে ওখানে পাঠানো মানে ঠিক যা লুকানোর কথা
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
                            <td class="px-3 py-2">
                                {{ $row->document_no ?: $row->narration }}
                            </td>

                            <td class="num px-3 py-2 text-end">
                                @if (bccomp((string) $row->debit, '0', 4) > 0)
                                    {{ \App\Core\Support\Money::format($row->debit) }}
                                @endif
                            </td>

                            <td class="num px-3 py-2 text-end">
                                @if (bccomp((string) $row->credit, '0', 4) > 0)
                                    {{ \App\Core\Support\Money::format($row->credit) }}
                                @endif
                            </td>

                            <td class="num px-3 py-2 text-end font-medium">
                                {{ \App\Core\Support\Money::format($row->running_balance) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-sales::portal.layout>
