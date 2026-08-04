<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Posting\PostingException;
use App\Core\Services\NumberSeriesProvisioner;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\NumberSeries;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * অর্থবছর বন্ধ করা ও পরের বছর খোলা।
 *
 * বছরে একবার ঘটে — আর সেটাই এর সবচেয়ে বড় ঝুঁকি। যে কাজ বছরে একবার হয়
 * তার ভুলগুলো কেউ চেনে না, আর ভুল হলে পুরো খাতা এলোমেলো হয়ে যায়।
 *
 * তিনটা কাজ, একটা ট্রানজেকশনে:
 *
 *   ১. আয় ও ব্যয়ের খাতগুলো শূন্য করা, আর নিট ফল সঞ্চিত মুনাফায় বসানো।
 *      নাহলে পরের বছরের লাভ-লোকসানে আগের বছরের বিক্রি যোগ হয়ে যেত।
 *
 *   ২. নতুন বছর খোলা, আর তার নম্বর সিরিজ বসানো — গত বছর ব্যবহারকারী
 *      যে উপসর্গ ও ছক ঠিক করেছিলেন সেগুলো সহ।
 *
 *   ৩. পুরনো বছরে তালা।
 *
 * যা **করা হয় না**: সম্পদ-দায়-মূলধন টেনে নেওয়ার আলাদা দাখিলা। এই খাতায়
 * লেজার একটানা, আর প্রতিটা ব্যালেন্স শুরু থেকে আজ পর্যন্ত যোগ করে গোনা
 * হয় — বছর ধরে নয়। তাই ওরকম একটা দাখিলা বসালে প্রতিটা সংখ্যা দ্বিগুণ
 * হত। বিস্তারিত close()-এর ভেতরে।
 */
final class YearEndService
{
    public function __construct(
        private readonly PostingEngine $posting,
        private readonly NumberSeriesProvisioner $series,
    ) {}

    /** বছর বন্ধের দাখিলার উৎস — ড্রিল-ডাউনে চেনা যায়। */
    public const CLOSE_SOURCE = 'year_close';

    /**
     * কী কী ঘটবে — কিছু না বদলে।
     *
     * বছর বন্ধ করা যায় না ফেরানো, তাই আগে দেখে নেওয়ার একটা পথ থাকতেই
     * হবে। "সেভ করে দেখি কী হয়" এখানে চলে না।
     *
     * @return array{profit: string, closing: int, drafts: int, next: array{name: string, starts_on: string, ends_on: string}}
     */
    public function preview(FinancialYear $year): array
    {
        return [
            'profit' => $this->netResult($year),
            // কয়টা খাত শূন্য হবে — বন্ধের দাখিলায় শেষ লাইনটা সঞ্চিত
            // মুনাফার, তাই সেটা বাদ
            'closing' => max(0, count($this->closingLines($year)) - 1),
            'drafts' => $this->draftCount($year),
            'next' => $this->nextYearFor($year),
        ];
    }

    /**
     * পরের বছরের প্রস্তাব — নাম ও তারিখ।
     *
     * চলতি বছরের দৈর্ঘ্য ধরেই পরেরটা বানানো হয়, ১২ মাস ধরে নেওয়া হয় না:
     * প্রথম বছরটা প্রায়ই অসম্পূর্ণ হয় (মাঝপথে ব্যবসা শুরু), আর তখন
     * ১২ মাস ধরে নিলে পরের বছরের শেষ তারিখ ভুল হত।
     *
     * @return array{name: string, starts_on: string, ends_on: string}
     */
    public function nextYearFor(FinancialYear $year): array
    {
        $starts = Carbon::parse($year->ends_on)->addDay();
        $ends = $starts->copy()->addYear()->subDay();

        return [
            // বাংলাদেশে অর্থবছর জুলাই–জুন, তাই "2027-2028" রূপটাই চেনা
            'name' => $starts->year.'-'.$ends->year,
            'starts_on' => $starts->toDateString(),
            'ends_on' => $ends->toDateString(),
        ];
    }

    /**
     * বছর বন্ধ করে পরেরটা খোলা।
     *
     * পুরোটা একটা ট্রানজেকশনে। মাঝপথে থামলে আয়ের খাত শূন্য হয়ে যেত
     * অথচ সম্পদ টানা হত না — আর তখন খাতা এমন এক অবস্থায় থাকত যেটা
     * হাতে ঠিক করা ছাড়া উপায় নেই।
     *
     * @param  array{name?: string, starts_on?: string, ends_on?: string}  $next
     */
    public function close(FinancialYear $year, array $next = []): FinancialYear
    {
        $this->assertCanClose($year);

        return DB::transaction(function () use ($year, $next) {
            $proposed = [...$this->nextYearFor($year), ...array_filter($next)];

            $this->assertNoOverlap($year, $proposed);

            $newYear = FinancialYear::create([
                'name' => $proposed['name'],
                'starts_on' => $proposed['starts_on'],
                'ends_on' => $proposed['ends_on'],
                'is_closed' => false,
                'is_current' => false,
            ]);

            /*
             * ১. আয়-ব্যয় বন্ধ — বছরের শেষ দিনে।
             *
             * তারিখটা শেষ দিন, পরের বছরের প্রথম দিন নয়: লাভটা এই বছরের
             * অর্জন, আর পরের বছরে বসালে লাভ-লোকসান দুই বছরেই ভুল হত।
             */
            $closing = $this->closingLines($year);

            if ($closing !== []) {
                $this->posting->post(
                    sourceType: self::CLOSE_SOURCE,
                    sourceId: $year->id,
                    trxDate: $year->ends_on,
                    lines: $closing,
                    documentNo: $year->name,
                );
            }

            /*
             * ২. সম্পদ-দায়-মূলধন টেনে নেওয়ার কোনো দাখিলা নেই — ইচ্ছাকৃত।
             *
             * প্রথমে ওটা লেখা হয়েছিল, আর সেটা ছিল ভুল। এই খাতায় লেজার
             * একটানা: payable(), outstanding(), ট্রায়াল ব্যালেন্স, খাতের
             * ব্যালেন্স — সবাই শুরু থেকে আজ পর্যন্ত যোগ করে, বছর ধরে নয়।
             * তাই ১ জুলাই আরেকটা "খোলা ব্যালেন্স" বসালে প্রতিটা সংখ্যা
             * দ্বিগুণ হত।
             *
             * ধরা পড়েছিল টেস্টে: বছর বন্ধের পর প্রাণ আরএফএল-এর প্রদেয়
             * ১,২৫,০০০ থেকে ২,৫০,০০০ হয়ে গিয়েছিল। ট্রায়াল ব্যালেন্স
             * তবু মিলত, কারণ দুই দিকই দ্বিগুণ হয়েছিল — অর্থাৎ সবচেয়ে
             * স্বাভাবিক পরীক্ষাটা ভুলটা ঢেকে দিত।
             *
             * নতুন বছরের শুরুর অবস্থা এমনিতেই জানা: ওটা ৩০ জুন পর্যন্ত
             * সবকিছুর যোগফল। আলাদা করে লিখে রাখার কিছু নেই।
             */

            // ৩. নতুন বছরের নম্বর সিরিজ — মডিউলের ঘোষণা থেকে
            $this->series->provision($newYear);

            $this->carryNextNumbers($year, $newYear);

            $year->forceFill([
                'is_closed' => true,
                'is_current' => false,
                'closed_at' => now(),
                'closed_by' => auth()->id(),
            ])->save();

            $newYear->forceFill(['is_current' => true])->save();

            return $newYear->fresh();
        });
    }

    /**
     * বছর বন্ধ করা যায় কি না।
     *
     * খসড়া ভাউচার থাকলে না। ওগুলো কখনো পোস্ট হয়নি, তাই বছর বন্ধ হলে
     * আর কখনো পোস্ট হতেও পারবে না — কাজটা চুপচাপ হারিয়ে যেত। ব্যবহারকারী
     * হয় পোস্ট করবেন, নয় বাতিল করবেন; দুইটার কোনোটাই সিস্টেম নিজে
     * সিদ্ধান্ত নিতে পারে না।
     */
    public function assertCanClose(FinancialYear $year): void
    {
        if ($year->is_closed) {
            throw ValidationException::withMessages([
                'year' => __('accounts::validation.year_already_closed'),
            ]);
        }

        $drafts = $this->draftCount($year);

        if ($drafts > 0) {
            throw ValidationException::withMessages([
                'year' => __('accounts::validation.year_has_drafts', ['count' => $drafts]),
            ]);
        }
    }

    private function draftCount(FinancialYear $year): int
    {
        return Voucher::query()
            ->whereBetween('trx_date', [$year->starts_on, $year->ends_on])
            ->where('status', DocumentStatus::DRAFT)
            ->count();
    }

    /**
     * @param  array{name: string, starts_on: string, ends_on: string}  $next
     */
    private function assertNoOverlap(FinancialYear $year, array $next): void
    {
        /*
         * চলতি বছরটাও গোনা হয়।
         *
         * প্রথমে সেটা বাদ দেওয়া হয়েছিল — ভুল। নতুন বছর যদি বন্ধ হতে
         * যাওয়া বছরের উপরেই পড়ে, তাহলে একই তারিখ দুই বছরে থাকত, আর
         * FinancialYear::forDate() যেকোনো একটা ফেরত দিত। বাদ দেওয়ার
         * কোনো কারণই ছিল না: বন্ধ হলেও বছরটা থেকে যায়।
         */
        $clash = FinancialYear::query()
            ->where(function ($q) use ($next) {
                $q->whereDate('starts_on', '<=', $next['ends_on'])
                    ->whereDate('ends_on', '>=', $next['starts_on']);
            })
            ->exists();

        if ($clash) {
            // দুইটা বছর একই তারিখ ঢাকলে FinancialYear::forDate() যেকোনো
            // একটা ফেরত দিত, আর একই তারিখের দুইটা এন্ট্রি দুই বছরে বসত
            throw ValidationException::withMessages([
                'starts_on' => __('accounts::validation.year_overlaps'),
            ]);
        }
    }

    /**
     * বছরের নিট ফল — লাভ ধনাত্মক।
     */
    public function netResult(FinancialYear $year): string
    {
        /*
         * দুই ধরনের প্রকৃতি দুই দিকে, তাই দুইটাকে একই চিহ্নে আনতে হয়।
         *
         * আয় ক্রেডিট প্রকৃতির: ৫০,০০০ বিক্রি মানে ক্রেডিট − ডেবিট = ৫০,০০০।
         * ব্যয় ডেবিট প্রকৃতির: ৩০,০০০ খরচ মানে ক্রেডিট − ডেবিট = −৩০,০০০।
         *
         * দুইটাতেই একই সূত্র লাগালে লাভ দাঁড়াত ৫০,০০০ − (−৩০,০০০) =
         * ৮০,০০০ — অর্থাৎ খরচটা লাভ হিসেবে যোগ হয়ে যেত। প্রথমবার ঠিক
         * সেটাই হয়েছিল।
         */
        $income = $this->totalFor($year, Account::INCOME);
        $expense = bcmul($this->totalFor($year, Account::EXPENSE), '-1', 4);

        return bcsub($income, $expense, 4);
    }

    /**
     * আয়-ব্যয় শূন্য করার লাইনগুলো।
     *
     * প্রতিটা খাতের ব্যালেন্স তার উল্টো দিকে বসিয়ে শূন্য করা হয়, আর
     * পুরো পার্থক্যটা সঞ্চিত মুনাফায়। খাতভিত্তিক করা হয়, মোট নিয়ে নয়:
     * নাহলে "কোন খরচ কত ছিল" প্রশ্নের উত্তর বন্ধের দাখিলায় থাকত না।
     *
     * @return list<array<string, mixed>>
     */
    private function closingLines(FinancialYear $year): array
    {
        $lines = [];
        $net = '0';

        foreach ([Account::INCOME, Account::EXPENSE] as $type) {
            foreach ($this->balancesByAccount($year, $type) as $accountId => $signed) {
                if (bccomp($signed, '0', 4) === 0) {
                    continue;
                }

                // signed = ডেবিট − ক্রেডিট। শূন্য করতে উল্টো দিকে বসাতে হয়।
                $lines[] = bccomp($signed, '0', 4) > 0
                    ? ['account_id' => $accountId, 'credit' => $signed, 'narration' => $this->narration()]
                    : ['account_id' => $accountId, 'debit' => bcmul($signed, '-1', 4), 'narration' => $this->narration()];

                $net = bcadd($net, $signed, 4);
            }
        }

        if ($lines === []) {
            return [];
        }

        $equity = StandardChart::find(StandardChart::RETAINED_EARNINGS);

        if ($equity === null) {
            throw new PostingException(
                'Closing a year needs the standard chart — retained earnings is missing.'
            );
        }

        /*
         * ভারসাম্যের লাইনটা।
         *
         * net = ডেবিট − ক্রেডিট মোট। ধনাত্মক মানে ব্যয় বেশি, অর্থাৎ
         * লোকসান — তখন সঞ্চিত মুনাফা কমে (ডেবিট)। ঋণাত্মক মানে লাভ,
         * তখন সঞ্চিত মুনাফা বাড়ে (ক্রেডিট)।
         */
        $lines[] = bccomp($net, '0', 4) > 0
            ? ['account_id' => $equity->id, 'debit' => $net, 'narration' => $this->narration()]
            : ['account_id' => $equity->id, 'credit' => bcmul($net, '-1', 4), 'narration' => $this->narration()];

        return $lines;
    }

    /**
     * নম্বর সিরিজ নতুন বছরে — রিসেট বা ধারাবাহিক।
     *
     * reset_yearly কলামটা এতদিন সংরক্ষিত হত কিন্তু কেউ পড়ত না, কারণ
     * বছর বদলানোর কোনো ব্যবস্থাই ছিল না। এখানেই সেটার একমাত্র অর্থ।
     */
    private function carryNextNumbers(FinancialYear $old, FinancialYear $new): void
    {
        $previous = NumberSeries::query()
            ->where('financial_year_id', $old->id)
            ->get()
            ->keyBy(fn ($s) => $s->doc_type.'|'.($s->branch_id ?? ''));

        foreach (NumberSeries::query()->where('financial_year_id', $new->id)->get() as $series) {
            $before = $previous->get($series->doc_type.'|'.($series->branch_id ?? ''));

            if ($before === null) {
                continue;
            }

            // ছক ও উপসর্গ সবসময় বহন করা হয় — ব্যবহারকারী গত বছর যা
            // ঠিক করেছিলেন সেটা নতুন বছরে হারানোর কোনো কারণ নেই
            $series->forceFill([
                'prefix' => $before->prefix,
                'suffix' => $before->suffix,
                'format' => $before->format,
                'padding' => $before->padding,
                'reset_yearly' => $before->reset_yearly,
                'next_number' => $before->reset_yearly ? $before->start_number : $before->next_number,
            ])->save();
        }
    }

    /**
     * একটা ধরনের সব খাতের মোট।
     */
    private function totalFor(FinancialYear $year, string $type): string
    {
        $row = LedgerEntry::query()
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('accounts.type', $type)
            ->whereBetween('ledger_entries.trx_date', [$year->starts_on, $year->ends_on])
            ->selectRaw('COALESCE(SUM(ledger_entries.credit) - SUM(ledger_entries.debit), 0) as net')
            ->first();

        return (string) ($row->net ?? '0');
    }

    /**
     * খাত ধরে ব্যালেন্স — ডেবিট বিয়োগ ক্রেডিট।
     *
     * @return array<int, string>
     */
    private function balancesByAccount(FinancialYear $year, string $type): array
    {
        return LedgerEntry::query()
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('accounts.type', $type)
            ->whereBetween('ledger_entries.trx_date', [$year->starts_on, $year->ends_on])
            ->groupBy('ledger_entries.account_id')
            ->selectRaw('ledger_entries.account_id, SUM(ledger_entries.debit) - SUM(ledger_entries.credit) as signed')
            ->pluck('signed', 'account_id')
            ->map(fn ($v) => (string) $v)
            ->all();
    }

    /**
     * দাখিলার বিবরণ — কোম্পানির ভাষায়, ব্যবহারকারীর নয়।
     *
     * OpeningBalanceService-এ একই সিদ্ধান্ত, একই কারণে: এক খাতায় দুই
     * ভাষা মিশে যাওয়া ঠেকাতে।
     */
    private function narration(bool $open = false): string
    {
        $locale = Company::query()->whereKey(CompanyContext::id())->value('locale');

        return __(
            $open ? 'accounts::message.year_opening' : 'accounts::message.year_closing',
            [],
            $locale ?? config('app.locale'),
        );
    }
}
