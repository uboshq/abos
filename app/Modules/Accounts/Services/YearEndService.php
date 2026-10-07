<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\Approval\DocumentApproval;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Engines\Posting\PostingException;
use App\Core\Services\NumberSeriesProvisioner;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\LedgerEntry;
use App\Models\NumberSeries;
use App\Models\User;
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
    /** কোম্পানির ভাষা — narration() দেখুন, কেন একবারই দেখা হয়। */
    private ?string $companyLocale = null;

    public function __construct(
        private readonly PostingEngine $posting,
        private readonly NumberSeriesProvisioner $series,
        private readonly DocumentApproval $approvals,
    ) {}

    /** বছর বন্ধের দাখিলার উৎস — ড্রিল-ডাউনে চেনা যায়। */
    public const CLOSE_SOURCE = 'year_close';

    /**
     * বছর আবার খুললে বন্ধের দাখিলাটা যে নামে উলটায়
     * ([[PostingEngine::reverse]] নামের শেষে `:reversal` বসায়)।
     */
    public const CLOSE_REVERSAL = self::CLOSE_SOURCE.':reversal';

    /**
     * বছর বন্ধ করার দাখিলার দুইটা নাম — বসানো আর উলটানো।
     *
     * ── ⛔ কেন এক জায়গায়, ২০ সেপ্টেম্বর ২০২৬ ───────────────
     * চার জায়গায় চার রকম লেখা ছিল: কেউ কেবল `year_close` বাদ দিত,
     * কেউ কিছুই বাদ দিত না। ⚠️ ফলে একই বছরের লাভ তিন পর্দায় তিন
     * রকম দেখাত — স্থিতিপত্রে দ্বিগুণ, রিপোর্টে শূন্য। ⓘ এখন নাম
     * দুইটা এখানেই লেখা, আর সবাই এখান থেকেই পড়ে।
     *
     * @return list<string>
     */
    public static function closingSources(): array
    {
        return [self::CLOSE_SOURCE, self::CLOSE_REVERSAL];
    }

    /**
     * ⭐ সমাপনী ভাউচারের নম্বর — "YC-<বছর>", আবার খুলে আবার বন্ধ করলে "YC-<বছর>/২" (ভাউচারের পরিকল্পনা ৩ঙ, ৭ অক্টোবর ২০২৬)।
     *
     * ⓘ বছরে একটাই সমাপনী, তাই নম্বরটা বছরের নামেই — চলমান সিরিজ নয়। ⛔ আগে নম্বর ছিল কেবল বছরের নাম ("2026-2027"),
     * খাতায় অন্য কোনো কাগজের মতো চেনা যেত না।
     */
    public static function closingNumber(FinancialYear $year, int $round = 1): string
    {
        return 'YC-'.$year->name.($round > 1 ? '/'.$round : '');
    }

    /**
     * প্রতিটা বছরের শেষ সমাপনীর নম্বর — বছরশেষের তালিকার লিঙ্কের জন্য ([[YearEndController::index()]])।
     *
     * @return array<int, string>  বছরের id => নম্বর
     */
    public function closingNumbers(): array
    {
        return LedgerEntry::query()
            ->where('source_type', self::CLOSE_SOURCE)
            ->selectRaw('source_id, MAX(id) as last_id')
            ->groupBy('source_id')
            ->pluck('last_id', 'source_id')
            ->map(fn ($id) => (string) LedgerEntry::query()->whereKey($id)->value('document_no'))
            ->all();
    }

    /** এই বছরের সবচেয়ে শেষে বসা সমাপনীর নম্বর — না থাকলে `null` (আগের বছরে বছরের নামই নম্বর ছিল, সেটাই ফেরে)। */
    public function closingNumberOf(FinancialYear $year): ?string
    {
        $no = LedgerEntry::query()
            ->where('source_type', self::CLOSE_SOURCE)
            ->where('source_id', $year->id)
            ->orderByDesc('id')
            ->value('document_no');

        return $no === null ? null : (string) $no;
    }

    /**
     * ⭐ সমাপনী ভাউচারের কাগজ — পাতা আর ছাপা একই তথ্যে ([[YearEndController::closing()]])।
     *
     * ⓘ প্রতিটা দাখিলা আলাদা: বন্ধের সমাপনী, আর বছর আবার খুললে তার উল্টো (একই নম্বর বহন করে, [[reopen()]])। আবার বন্ধ করলে
     * নতুন নম্বরে নতুন সমাপনী। সারিগুলো সব শাখার — সমাপনী গোটা কোম্পানির কাগজ।
     *
     * @return list<array{kind: string, document_no: string, date: string, narration: string, debit: string, credit: string,
     *     lines: list<array{account: string, branch: string, debit: string, credit: string}>}>
     */
    public function closingPaper(FinancialYear $year): array
    {
        $rows = LedgerEntry::query()
            ->whereIn('source_type', self::closingSources())
            ->where('source_id', $year->id)
            ->orderBy('id')
            ->get(['id', 'source_type', 'document_no', 'trx_date', 'account_id', 'branch_id', 'debit', 'credit', 'narration']);

        if ($rows->isEmpty()) {
            return [];
        }

        $accounts = Account::query()->whereKey($rows->pluck('account_id')->unique())->get()->keyBy('id');
        $branches = \App\Models\Branch::query()->whereKey($rows->pluck('branch_id')->filter()->unique())->get()->keyBy('id');

        $papers = [];

        foreach ($rows as $row) {
            $key = $row->source_type.'|'.$row->document_no;

            $papers[$key] ??= [
                'kind' => $row->source_type === self::CLOSE_SOURCE ? 'close' : 'reversal',
                'document_no' => (string) $row->document_no,
                'date' => DateFormat::format($row->trx_date),
                'narration' => (string) $row->narration,
                'debit' => '0',
                'credit' => '0',
                'lines' => [],
            ];

            $account = $accounts->get($row->account_id);
            $papers[$key]['lines'][] = [
                'account' => $account === null ? '#'.$row->account_id : $account->code.' — '.$account->name(),
                'branch' => $branches->get($row->branch_id)?->name() ?? '',
                'debit' => (string) $row->debit,
                'credit' => (string) $row->credit,
            ];
            $papers[$key]['debit'] = bcadd($papers[$key]['debit'], (string) $row->debit, 4);
            $papers[$key]['credit'] = bcadd($papers[$key]['credit'], (string) $row->credit, 4);
        }

        return array_values($papers);
    }

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
            // ⓘ সঞ্চিত মুনাফার সারি বাদে — শাখা ধরে সেগুলো একাধিক (অডিট গ১০)
            'closing' => count(array_filter($this->closingLines($year),
                fn (array $l) => (int) $l['account_id'] !== (int) StandardChart::find(StandardChart::RETAINED_EARNINGS)?->id)),
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

        /*
         * ⭐ অনুমোদন — মালিকের সিদ্ধান্ত, ১৮ সেপ্টেম্বর ২০২৬।
         *
         * মালিকের কথা: *"এখন সব জায়গায় এপ্রুভাল দিয়ে টেস্ট কর, পরে যে
         * যে জায়গায় লাগবে না তাও উঠিয়ে দিব"*।
         *
         * ⚠️ সারিটা কারো আজকের কাজ থামায় না: ছক না বসানো পর্যন্ত
         * `assertClear()` চুপচাপ ফিরে যায়, আর কাজ আগের মতোই চলে।
         */
        $this->approvals->assertClear(
            document: $year,
            module: 'accounts',
            action: 'year_end',
            field: 'is_closed',
            reason: $year->name,
        );

        return DB::transaction(function () use ($year, $next) {
            $proposed = [...$this->nextYearFor($year), ...array_filter($next)];

            $this->assertNoOverlap($year, $proposed);

            /*
             * ⓘ বছরটা আগেই থাকতে পারে — আগেরবার বন্ধ করার দিন তৈরি
             * হয়েছিল, আর [[reopen()]] সেটা মোছে না। ⭐ তখন নতুন একটা
             * বানানো হয় না — ওর ভিতরে ইতিমধ্যে কাগজ বসে গেছে, আর দুইটা
             * হলে একই তারিখ দুই বছরে পড়ত।
             */
            $newYear = FinancialYear::query()
                ->where('starts_on', Carbon::parse($proposed['starts_on'])->toDateString())
                ->where('ends_on', Carbon::parse($proposed['ends_on'])->toDateString())
                ->first()
                ?? FinancialYear::create([
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
                /*
                 * ⭐ নিজের নম্বর — YC-<বছর> (৩ঙ)। ⓘ আগে বন্ধ হয়ে খোলা বছরে আগের সমাপনী(গুলো)র নম্বর খাতায় থেকে যায়, তাই
                 * এবারেরটা পরের পালা: আগের যত নম্বর, তার পরেরটা।
                 */
                $round = 1 + LedgerEntry::query()
                    ->where('source_type', self::CLOSE_SOURCE)
                    ->where('source_id', $year->id)
                    ->distinct()
                    ->count('document_no');

                $this->posting->post(
                    sourceType: self::CLOSE_SOURCE,
                    sourceId: $year->id,
                    trxDate: $year->ends_on,
                    lines: $closing,
                    documentNo: self::closingNumber($year, $round),
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

    /** এই মানুষটা বন্ধ বছর খুলতে পারেন কি না — পর্দায় বোতাম দেখানোর জন্য। */
    public function canReopen(?User $user): bool
    {
        return $user !== null && $this->isSuperAdmin($user);
    }

    /**
     * কোন বছরটা এখন খোলা যায় — সবচেয়ে পরে বন্ধ হওয়াটা, নাহলে কিছুই না।
     *
     * ⓘ পর্দা আর সেবা একই প্রশ্ন দুইভাবে জিজ্ঞেস করে না: বোতামটা এই
     * উত্তরেই বসে, আর [[self::reopen()]] একই উত্তর ধরেই আটকায়।
     */
    public function reopenableYear(): ?FinancialYear
    {
        return FinancialYear::query()
            ->where('is_closed', true)
            ->orderByDesc('closed_at')
            ->orderByDesc('ends_on')
            ->first();
    }

    /**
     * বন্ধ বছর আবার খোলা — কেবল সুপার অ্যাডমিন।
     *
     * ── কেন দরজাটা লাগে, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────
     * মালিক লাইভে ২০২৬-২০২৭ বন্ধ করে দেখলেন খোলার কোনো উপায় নেই:
     * *"অর্থবছরগুলো বন্ধ korechi calur option nai keno. super admin er
     * kache seta thakte hobe"*। ⓘ বন্ধ করা এক-মুখী বলেই ভুলটা সহজে হয় —
     * আর হয়ে গেলে গোটা বছরের কাজ আটকে থাকে।
     *
     * ── কেন কেবল শেষ বন্ধ বছরটা ─────────────────────────────────────
     * ⛔ পুরনো কোনো বছর খুললে তার পরের বন্ধগুলো অর্থহীন হত: ২০২৪ খুলে
     * বসলে ২০২৫ আর ২০২৬-এর সমাপনী দাখিলাগুলো ঐ বছরের লাভ ধরে বসে আছে,
     * অথচ ভিত্তিটাই আর স্থির নয়। তাই কেবল সবচেয়ে পরে বন্ধ হওয়াটা।
     *
     * ── কী ফেরানো হয় ───────────────────────────────────────────────
     * সমাপনীর দাখিলাটা উল্টানো হয় ([[PostingEngine::reverse()]]) — মোছা
     * হয় না। ⚠️ মুছে ফেলা মানে খাতায় একটা গর্ত, আর তখন "কী হয়েছিল"
     * প্রশ্নের উত্তর কোথাও থাকত না। উল্টো সারিগুলো থাকে, তাই ইতিহাসটা
     * পুরোটাই পড়া যায়।
     *
     * ⓘ বন্ধ করার সময় যে পরের বছরটা খোলা হয়েছিল সেটা মুছে ফেলা হয় না —
     * ওতে ইতিমধ্যে কাজ হয়ে থাকতে পারে। কেবল "চলতি" চিহ্নটা ফিরে আসে।
     *
     * @throws ValidationException
     */
    public function reopen(FinancialYear $year, User $user): FinancialYear
    {
        if (! $this->isSuperAdmin($user)) {
            throw ValidationException::withMessages([
                'reopen' => __('accounts::validation.year_reopen_super_admin'),
            ]);
        }

        if (! $year->is_closed) {
            throw ValidationException::withMessages([
                'reopen' => __('accounts::validation.year_not_closed'),
            ]);
        }

        $latestClosed = $this->reopenableYear();

        if ($latestClosed === null || $latestClosed->id !== $year->id) {
            throw ValidationException::withMessages([
                'reopen' => __('accounts::validation.year_reopen_latest_only', [
                    'name' => $latestClosed?->name ?? $year->name,
                ]),
            ]);
        }

        return DB::transaction(function () use ($year, $user) {
            /*
             * ⚠️ সমাপনীতে কোনো দাখিলা না-ও বসে থাকতে পারে — আয় ও ব্যয়
             * দুইটাই শূন্য হলে [[self::close()]] কিছুই পোস্ট করে না।
             *
             * ⓘ মালিকের লাইভ পাতাতেই অবস্থাটা এমন ছিল ("বছরের নিট ফল
             * ০.০০, যত খাত শূন্য হবে ০"), আর না দেখে উল্টাতে গেলে ইঞ্জিন
             * ঠিকই ছুঁড়ত — অর্থাৎ যে বছরে কিছু হয়নি, ঠিক সেটাই খোলা যেত না।
             */
            $hasClosingRows = LedgerEntry::query()
                ->where('source_type', self::CLOSE_SOURCE)
                ->where('source_id', $year->id)
                ->exists();

            FinancialYear::query()->where('is_current', true)->update(['is_current' => false]);

            /*
             * ⚠️ তালাটা আগে খোলা, তারপর উল্টো দাখিলা — ক্রমটা উল্টালে কাজই হয় না।
             *
             * ⛔ [[PostingEngine]] বন্ধ বছরে কোনো সারি বসাতে দেয় না ("Reopening
             * it is an approved action, not a side effect of posting") — আর
             * উল্টো দাখিলাটার তারিখ ঐ বছরেরই শেষ দিন। ⓘ তাই আগে উল্টাতে গেলে
             * ইঞ্জিন ছুঁড়ত, বছরটা বন্ধই থেকে যেত, আর পর্দায় কিছুই হত না —
             * মালিক লাইভে ঠিক সেটাই পেয়েছেন (২০ সেপ্টেম্বর ২০২৬)।
             *
             * ⓘ পুরোটা এক ট্রানজেকশনে, তাই উল্টাতে গিয়ে কিছু ভাঙলে তালাটাও
             * আগের জায়গায় ফিরে যায়।
             */
            $year->forceFill([
                'is_closed' => false,
                'is_current' => true,
                'closed_at' => null,
                'closed_by' => null,
            ])->save();

            if ($hasClosingRows) {
                // ⭐ উল্টো দাখিলা যে সমাপনী উল্টায় তার নম্বরই বহন করে — সমাপনীর পাতায় দুটো পাশাপাশি (৩ঙ)
                $closingNo = $this->closingNumberOf($year);

                $this->posting->reverse(
                    sourceType: self::CLOSE_SOURCE,
                    sourceId: $year->id,
                    reversalDate: $year->ends_on,
                    reason: __('accounts::message.year_reopened', ['name' => $year->name]).($closingNo !== null ? ' — '.$closingNo : ''),
                    userId: $user->id,
                    documentNo: $closingNo,
                );
            }

            return $year->fresh();
        });
    }

    /** সুপার অ্যাডমিন কি না — রোলের নাম ধরে, অনুমতি ধরে নয়। */
    private function isSuperAdmin(User $user): bool
    {
        return $user->roles->contains('name', PermissionSyncer::SUPER_ADMIN_ROLE);
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

    /**
     * বছরের তারিখে পড়ে থাকা খসড়া ভাউচার।
     *
     * ⛔ সব শাখা জুড়ে — পুরো-ERP অডিট, ৬ অক্টোবর ২০২৬ (হিসাব ⚠️৮; [[AMonthOrYearCloseSeesEveryBranchTest]])। ⓘ মাস আর বছর বন্ধ গোটা
     * কোম্পানির, অথচ খোঁজ ছিল দেখার শাখার দেয়ালে: এক শাখা বাছা থাকলে অন্য শাখার খসড়া, মিলকরণ, গোনা বা সম্পদ অদৃশ্য — তালিকা সবুজ,
     * বছর বন্ধ, আর ওই খসড়া আর কখনো পোস্ট হতে পারত না।
     */
    private function draftCount(FinancialYear $year): int
    {
        return Voucher::acrossBranches()
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
        /*
         * ⭐ যে বছরটা হুবহু এই প্রস্তাবটাই, সে সংঘর্ষ নয় — ২০ সেপ্টেম্বর ২০২৬।
         *
         * ── ⛔ কী ভাঙা ছিল ────────────────────────────────────────────
         * [[reopen()]] বছরটা খুলে দেয়, কিন্তু বন্ধ করার দিন তৈরি হওয়া
         * **পরের বছরটা রেখে দেয়** — আর সেটাই ঠিক: ওই বছরে ততদিনে
         * কাগজ বসে গেছে। ⚠️ কিন্তু তারপর আবার বন্ধ করতে গেলে এই পাহারা
         * নিজের তৈরি করা বছরটাকেই সংঘর্ষ বলত — অর্থাৎ **একবার খোলা
         * বছর আর কোনোদিন বন্ধ করা যেত না**।
         *
         * ⓘ তাই হুবহু একই সীমার বছরটা বাদ যায়। ⚠️ আংশিক ছাপাপড়া
         * অন্য কোনো বছর তবু সংঘর্ষই — দুই বছর একই তারিখ ঢাকলে
         * [[FinancialYear::forDate]] যেকোনো একটা ফেরত দিত।
         */
        $clash = FinancialYear::query()
            ->where(function ($q) use ($next) {
                $q->where('starts_on', '<=', Carbon::parse($next['ends_on'])->toDateString())
                    ->where('ends_on', '>=', Carbon::parse($next['starts_on'])->toDateString());
            })
            ->where(function ($q) use ($next) {
                $q->where('starts_on', '<>', Carbon::parse($next['starts_on'])->toDateString())
                    ->orWhere('ends_on', '<>', Carbon::parse($next['ends_on'])->toDateString());
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

        /*
         * ⭐ শাখা ধরে — অডিট গ১০, ৪ অক্টোবর ২০২৬।
         * ⛔ আগে সব শাখার আয়-ব্যয় এক যোগফলে বন্ধ হত, আর দাখিলা বসত যিনি বন্ধ করছেন তাঁর শাখায়: ঢাকার আয়-খাত
         * ঢাকায় শূন্য হত না, সঞ্চিত মুনাফা পুরোটা এক শাখায় উঠত — পরের বছর প্রতিটা শাখার স্থিতিপত্র ভুল।
         * ⭐ এখন প্রতিটা খাত·শাখা নিজের শাখাতেই শূন্য হয়, আর প্রতিটা শাখার লাভ সেই শাখার সঞ্চিত মুনাফায়।
         *
         * @var array<string, string> $net শাখা => ডেবিট − ক্রেডিট
         */
        $net = [];

        foreach ([Account::INCOME, Account::EXPENSE] as $type) {
            foreach ($this->balancesByAccount($year, $type) as $row) {
                $signed = $row['signed'];

                if (bccomp($signed, '0', 4) === 0) {
                    continue;
                }

                // signed = ডেবিট − ক্রেডিট। শূন্য করতে উল্টো দিকে বসাতে হয়।
                $lines[] = (bccomp($signed, '0', 4) > 0
                    ? ['account_id' => $row['account_id'], 'credit' => $signed]
                    : ['account_id' => $row['account_id'], 'debit' => bcmul($signed, '-1', 4)])
                    + ['branch_id' => $row['branch_id'], 'narration' => $this->narration()];

                $key = (string) ($row['branch_id'] ?? '');
                $net[$key] = bcadd($net[$key] ?? '0', $signed, 4);
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
        foreach ($net as $branch => $sum) {
            if (bccomp($sum, '0', 4) === 0) {
                continue;
            }

            $lines[] = (bccomp($sum, '0', 4) > 0
                ? ['account_id' => $equity->id, 'debit' => $sum]
                : ['account_id' => $equity->id, 'credit' => bcmul($sum, '-1', 4)])
                + ['branch_id' => $branch === '' ? null : (int) $branch, 'narration' => $this->narration()];
        }

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

            /*
             * ⛔ পতাকাটা একা যথেষ্ট নয় — ছকেও বছর থাকতে হবে।
             *
             * ⚠️ ২০ সেপ্টেম্বর ২০২৬: লাইভের প্রতিটা সারিতে `reset_yearly`
             * চালু অথচ ছক `{PREFIX}-{SEQ}`। এখানে কেবল পতাকাটা দেখা হত,
             * তাই বছর বন্ধ হলে গুনতি ১-এ ফিরত আর নতুন বছরের প্রথম
             * কাগজটা পুরনো নম্বরেই ধাক্কা খেয়ে **কখনো কাটা যেত না**।
             *
             * ⓘ সারিটা নিজেই এখন মানটা শোধরায় ([[NumberSeries::booted()]]),
             * কিন্তু পুরনো সারিগুলো ডাটাবেজে এখনো ভুল — তাই এখানে
             * ছকটা দেখেই সিদ্ধান্ত, বসানো মানটা নয়।
             */
            $resets = $before->reset_yearly && NumberSeriesProvisioner::resetsWith((string) $before->format);

            /*
             * ⛔ গুনতি কখনো পিছায় না — অডিট §১.৮, ২৭ সেপ্টেম্বর ২০২৬।
             *
             * ⚠️ বছর খুলে আবার বন্ধ করলে নতুন বছরটা আগে থেকেই থাকে, আর
             * তাতে ইতিমধ্যে নম্বর কাটা হয়ে গেছে (ধরা যাক ০১০১..০১৫০)।
             * হুবহু বসালে গুনতি ১০১-এ ফিরত, আর প্রতিটা নতুন কাগজ "নম্বর
             * আগেই আছে" বলে আটকে যেত। ⓘ তাই দুইটার বড়টা থাকে।
             *
             * ⓘ বছর-রিসেট সিরিজেও একই: প্রথম বন্ধে নতুন সারিটা সদ্য বসানো,
             * তাই `start_number`-এই শুরু; আবার বন্ধে ওতে কাটা নম্বর আছে,
             * তাই যেখানে আছে সেখান থেকেই চলে — একই নম্বর দুইবার নয়।
             */
            $carried = $resets
                ? max((int) $before->start_number, (int) $series->next_number)
                : max((int) $series->next_number, (int) $before->next_number);

            // ছক ও উপসর্গ সবসময় বহন করা হয় — ব্যবহারকারী গত বছর যা
            // ঠিক করেছিলেন সেটা নতুন বছরে হারানোর কোনো কারণ নেই
            $series->forceFill([
                'prefix' => $before->prefix,
                'suffix' => $before->suffix,
                'format' => $before->format,
                'padding' => $before->padding,
                'reset_yearly' => $resets,
                'next_number' => $carried,
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

            /*
             * ⛔ বন্ধের দাখিলাটা বাদ — ২০ সেপ্টেম্বর ২০২৬।
             *
             * বন্ধ করা মানে আয়-ব্যয়ের খাতগুলো শূন্য করা। ⚠️ ওই সারিগুলো
             * গুনলে **যেকোনো বন্ধ বছরের ফল শূন্য** দেখাত — অর্থাৎ বছর
             * বন্ধ করার সাথে সাথেই ওই বছরে কত লাভ হয়েছিল সেটা মুছে যেত।
             * ⓘ উলটানো সারিও বাদ, নাহলে বছর আবার খুললে লাভ দ্বিগুণ দেখাত।
             */
            ->whereNotIn('ledger_entries.source_type', self::closingSources())
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
        // ⓘ খাত·শাখা ধরে — বন্ধের দাখিলা প্রতিটা শাখায় আলাদা বসে (অডিট গ১০)
        return LedgerEntry::query()
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('accounts.type', $type)
            ->whereBetween('ledger_entries.trx_date', [$year->starts_on, $year->ends_on])
            ->groupBy('ledger_entries.account_id', 'ledger_entries.branch_id')
            ->selectRaw('ledger_entries.account_id, ledger_entries.branch_id, SUM(ledger_entries.debit) - SUM(ledger_entries.credit) as signed')
            ->get()
            ->map(fn ($r) => [
                'account_id' => (int) $r->account_id,
                'branch_id' => $r->branch_id === null ? null : (int) $r->branch_id,
                'signed' => (string) $r->signed,
            ])
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
        /*
         * ভাষাটা একবার দেখা — বছর বন্ধের পাতায় এই মেথডটা কয়েকবার ডাকা
         * হয়, আর প্রতিবার একই কোম্পানির একই ঘরটা জিজ্ঞেস করা হত।
         * মনে রাখাটা কেবল এই রিকোয়েস্টের জন্য; সার্ভিসের বস্তুটা
         * রিকোয়েস্ট শেষে মরে যায়, তাই কেউ ভাষা বদলালে পরের পাতাতেই
         * নতুনটা দেখা যাবে।
         */
        $this->companyLocale ??= Company::query()
            ->whereKey(CompanyContext::id())
            ->value('locale') ?? config('app.locale');

        $locale = $this->companyLocale;

        return __(
            $open ? 'accounts::message.year_opening' : 'accounts::message.year_closing',
            [],
            $locale ?? config('app.locale'),
        );
    }
}
