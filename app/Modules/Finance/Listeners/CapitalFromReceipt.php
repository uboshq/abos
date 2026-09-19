<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Modules\Accounts\Events\VoucherPosted;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\CapitalEntry;
use App\Modules\Finance\Services\CapitalService;

/**
 * রসিদ মূলধনের খাতে গেলে — মূলধনের তালিকাতেও ওঠে, নিজে থেকে।
 *
 * ── ⛔ কেন, ১৯ সেপ্টেম্বর ২০২৬ ──────────────────────────────────────
 * মালিক রসিদ ভাউচারে নিজের দ্বিতীয় মূলধন (৫ লাখ) নিচ্ছিলেন। খাতায় টাকা
 * ঠিকই বসত (ব্যাংক ডেবিট, 3100 ক্রেডিট), কিন্তু "মূলধন ও বিনিয়োগ" পাতা
 * কেবল নিজের পর্দার রেকর্ড দেখায় — তাই কে কত দিলেন, অংশ %, লাভের ভাগ,
 * সবই পুরনো সংখ্যায় থেকে যেত। তিনি বললেন: *"capital theke asle eta auto
 * boslei to valo hoy"*।
 *
 * ── ⭐ কী করে ────────────────────────────────────────────────────────
 * পাকা হওয়া রসিদ, পক্ষ একজন **ব্যক্তি**, আর কোনো ক্রেডিট সারি মালিকের
 * মূলধনে (3100) → সেই অঙ্কে একটা মূলধনের রেকর্ড, সরাসরি "খাতায় বসেছে"
 * অবস্থায়, ঐ রসিদের সাথে জোড়া। ⚠️ খাতায় **নতুন কিছু বসে না** — টাকাটা
 * রসিদ দিয়েই বসে গেছে; এটা কেবল তালিকার সারি।
 *
 * ⛔ দুইবার নয়: ঐ রসিদের সাথে জোড়া রেকর্ড আগেই থাকলে কিছু করে না। "নতুন
 * মূলধন" বোতামের পথেও ঠিক এটাই ঘটে — [[CapitalService::post()]] নিজে রসিদ
 * বানায় আর নিজেই জোড়ে, আর এই শ্রোতা চলে লেনদেন পাকা হওয়ার পরে
 * (`DB::afterCommit`), অর্থাৎ জোড়া ততক্ষণে বসে গেছে।
 *
 * ⓘ রসিদ পরে বাতিল হলে রেকর্ডটা মোছা হয় না — [[CapitalService::positions()]]
 * বাতিল রসিদের সাথে জোড়া রেকর্ড গোনে না।
 */
final class CapitalFromReceipt
{
    public function handle(VoucherPosted $event): void
    {
        if (($event->payload['type'] ?? null) !== Voucher::RECEIPT
            || ($event->payload['party_type'] ?? null) !== 'person'
            || empty($event->payload['party_id'])) {
            return;
        }

        $voucher = Voucher::query()->where('public_id', $event->publicId)->with('lines')->first();

        if ($voucher === null || CapitalEntry::query()->where('voucher_id', $voucher->id)->exists()) {
            return;
        }

        $capital = Account::query()->where('code', StandardChart::OWNER_CAPITAL)->value('id');

        $amount = $voucher->lines
            ->filter(fn ($line) => (int) $line->account_id === (int) $capital && bccomp((string) $line->credit, '0', 4) > 0)
            ->reduce(fn (string $sum, $line) => bcadd($sum, (string) $line->credit, 4), '0');

        if (bccomp($amount, '0', 4) <= 0) {
            return;
        }

        $into = $voucher->lines->first(fn ($line) => bccomp((string) $line->debit, '0', 4) > 0)?->account_id;
        $personId = (int) $event->payload['party_id'];

        // আগে যে ভূমিকায় দিয়েছেন (মালিক / অংশীদার) — নাহলে মালিক
        $role = CapitalEntry::query()->where('person_id', $personId)->latest('id')->value('contributor_type')
            ?? CapitalEntry::OWNER;

        $entry = app(CapitalService::class)->record([
            'person_id' => $personId,
            'contributor_type' => $role,
            'entry_type' => CapitalEntry::CONTRIBUTION,
            'in_kind' => CapitalEntry::CASH,
            'received_into_account_id' => $into,
            'trx_date' => $voucher->trx_date->toDateString(),
            'amount' => $amount,
            'narration' => __('finance::message.capital_from_receipt', ['no' => $voucher->document_no]),
        ]);

        $entry->forceFill([
            'status' => CapitalEntry::POSTED,
            'voucher_id' => $voucher->id,
            'received_into_account_id' => $into,
            'posted_at' => now(),
        ])->save();
    }
}
