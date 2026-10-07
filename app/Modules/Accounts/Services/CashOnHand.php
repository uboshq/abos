<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;

/**
 * হাতের টাকা শূন্যের নিচে নামে না — এক নিয়ম, সব টাকা-বের-হওয়ার পথে।
 *
 * ── ⛔ কী ঘটেছিল, লাইভ QA (hp2, TCL), ২৭ সেপ্টেম্বর ২০২৬ ──────────────
 * খালি নগদ টিল থেকে সরবরাহকারীকে ১,০০০ টাকা পরিশোধ হয়ে গেল (PMT-0001)।
 * খাতা মিলল, ডেবিট = ক্রেডিট — অথচ টিলের জের −১,০০০। ⓘ বাক্সে ঋণাত্মক টাকা
 * থাকে না, বিকাশের ওয়ালেটেও না; খাতায় ঋণাত্মক মানে হয় টাকাটা আসলে যায়নি,
 * নয় খাতায় ওঠেনি এমন টাকা থেকে গেছে। দুইটাই মানে গোনা টাকা আর খাতা আর
 * মিলবে না।
 *
 * ── কোন খাত ─────────────────────────────────────────────────────────────
 * খাতের ধরন থেকে, হাতে লেখা তালিকা থেকে নয় (সমন্বয়কারীর সিদ্ধান্ত):
 * `money_kind` নগদ বা মোবাইল ব্যাংকিং → শূন্যের নিচে নয়। ব্যাংক ইচ্ছাকৃতভাবে
 * বাইরে — চলতি ঋণের (CC/OD) খাত আইনত ঋণাত্মক হয়। `money_kind` নিজেই বাবার
 * খাত থেকে বসে ([[Account::CASH]]), তাই নতুন টিল বা ওয়ালেট আপনা থেকেই ঢাকা।
 *
 * ── ⭐ কেন এক জায়গায় ──────────────────────────────────────────────────
 * টাকা বেরোয় কয়েকটা দরজা দিয়ে — ক্রয়ের পরিশোধ, পরিশোধ ভাউচার (তার ভিতর
 * দিয়ে সরাসরি ক্রয়ের "এখনই দেওয়া")। প্রতিটায় আলাদা নিয়ম লিখলে একদিন একটা
 * দরজা পুরনো নিয়মে থেকে যেত। তাই মাপা আর তালা দুইটাই এখানে; ভুলবার্তা
 * ডাকার মডিউলের নিজের ভাষায়, এই ক্লাস কেবল অঙ্ক বলে।
 */
final class CashOnHand
{
    /** এই খাত শূন্যের নিচে নামতে পারে না কি না। */
    public function guards(Account $account): bool
    {
        return in_array($account->money_kind, [Account::CASH, Account::MFS], true);
    }

    /**
     * খাতের সারিতে তালা — লেনদেনের ভিতরে, জের মাপার আগে ডাকতে হয়।
     *
     * ⛔ তালা ছাড়া দুইটা পরিশোধ একই মুহূর্তে একই জের দেখত, দুইটাই "আছে"
     * বলে পাশ করত, আর মিলে খাতা ঋণাত্মক হত — ঠিক যা ঠেকানোর কথা।
     */
    public function lock(Account $account): void
    {
        Account::query()->whereKey($account->getKey())->lockForUpdate()->first();
    }

    /**
     * দিতে চাওয়া অঙ্কের তুলনায় কত কম — যথেষ্ট থাকলে বা খাতটা পাহারার বাইরে হলে null।
     *
     * জের দুইবার মাপা হয়: সব মিলিয়ে, আর টাকা বেরোনোর তারিখ পর্যন্ত — যেটা কম।
     * ⚠️ কেবল আজকেরটা দেখলে পিছনের তারিখের একটা পরিশোধ সেদিনের টিল ঋণাত্মক
     * করে দিত, আজ টাকা আছে বলেই।
     *
     * @return string|null ঘাটতি, চার দশমিকে
     */
    public function shortfall(Account $account, string $amount, ?string $date = null): ?string
    {
        if (! $this->guards($account)) {
            return null;
        }

        $held = $this->balance($account);

        if ($date !== null) {
            $then = $this->balance($account, $date);
            $held = bccomp($then, $held, 4) < 0 ? $then : $held;
        }

        $short = bcsub($amount, $held, 4);

        return bccomp($short, '0', 4) > 0 ? $short : null;
    }

    /** খাতে কত — ডেবিট − ক্রেডিট, সব বা দেওয়া দিন পর্যন্ত। */
    public function balance(Account $account, ?string $upTo = null): string
    {
        $row = LedgerEntry::query()
            ->where('account_id', $account->getKey())
            ->when($upTo !== null, fn ($q) => $q->where('trx_date', '<=', $upTo))
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        return bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4);
    }
}
