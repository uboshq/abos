<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Integrity;

use App\Core\Contracts\ChecksItsOwnBooks;
use App\Core\Engines\Drill\DrillResolver;
use App\Core\Integrity\IntegrityCheck;
use App\Core\Integrity\IntegrityFinding;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * খাতা নিজেই মেলে কি না — হিসাবের দিক থেকে।
 *
 * ── কেন পরীক্ষাগুলো যথেষ্ট নয় ───────────────────────────────────────
 * পরীক্ষা বলে কোডটা ঠিক। কিন্তু চালু খাতায় গরমিল ঢোকার পথ আরও আছে:
 * হাতে চালানো SQL, অসম্পূর্ণ মাইগ্রেশন, আধেক লেখা একটা ট্রানজেকশন, বা
 * এমন একটা বাগ যেটা সারানোর আগেই কিছু সারি লিখে ফেলেছে। কোড সারালে
 * পুরনো সারিগুলো নিজে থেকে ঠিক হয় না।
 */
final class AccountsChecks implements ChecksItsOwnBooks
{
    /** @return list<IntegrityCheck> */
    public static function checks(): array
    {
        return [
            self::trialBalance(),
            self::everyDocumentBalances(),
            self::everyEntryHasAnAccount(),
            // ⭐ কাগজ মুছে গেছে অথচ দাখিলা খাতায় — ২ অক্টোবর ২০২৬
            self::everyEntryHasItsPaper(),
            // ⭐ পক্ষের খাতার যোগ = নিয়ন্ত্রণ খাতের জের — পুনঃঅডিট, ৯ অক্টোবর ২০২৬
            self::partiesAddUpToTheirControlAccount(),
        ];
    }

    /**
     * গোটা খাতার ডেবিট ও ক্রেডিট সমান।
     *
     * দুই-তরফা হিসাবের একমাত্র শর্ত। না মিললে রেওয়ামিল, ব্যালেন্স শিট,
     * লাভ-ক্ষতি — তিনটাই ভুল, আর কোনোটাই নিজে থেকে বলে না।
     */
    public static function trialBalance(): IntegrityCheck
    {
        return new IntegrityCheck(
            key: 'accounts.trial_balance',
            label: __('accounts::integrity.trial_balance'),
            question: __('accounts::integrity.trial_balance_q'),
            whenBroken: __('accounts::integrity.trial_balance_broken'),
            permission: 'accounts.report',
            run: function (): array {
                $row = DB::table('ledger_entries')
                    ->where('company_id', CompanyContext::id())
                    ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
                    ->first();

                $debit = Money::of($row->d ?? '0');
                $credit = Money::of($row->c ?? '0');

                if (bccomp($debit, $credit, 4) === 0) {
                    return [];
                }

                return [new IntegrityFinding(
                    what: __('accounts::integrity.the_whole_ledger'),
                    detail: __('accounts::integrity.dr_cr_detail', [
                        'debit' => Money::format($debit, 2),
                        'credit' => Money::format($credit, 2),
                        'diff' => Money::format(bcsub($debit, $credit, 4), 2),
                    ]),
                )];
            },
        );
    }

    /**
     * প্রতিটা কাগজ আলাদা করেও মেলে।
     *
     * ── কেন গোটা খাতার যোগফল যথেষ্ট নয় ──────────────────────────────
     * দুইটা ভাঙা দাখিলা একে অন্যকে ঢেকে দিতে পারে: একটায় ১০০ বেশি
     * ডেবিট, আরেকটায় ১০০ বেশি ক্রেডিট। মোট যোগফল নিখুঁত মেলে, রেওয়ামিল
     * সবুজ দেখায়, অথচ দুইটা কাগজেরই অঙ্ক ভুল — আর ওই দুইটা খুঁজে বের
     * করার কোনো উপায় থাকে না।
     */
    public static function everyDocumentBalances(): IntegrityCheck
    {
        return new IntegrityCheck(
            key: 'accounts.every_document_balances',
            label: __('accounts::integrity.each_document'),
            question: __('accounts::integrity.each_document_q'),
            whenBroken: __('accounts::integrity.each_document_broken'),
            permission: 'accounts.report',
            run: function (): array {
                $query = DB::table('ledger_entries')
                    ->where('company_id', CompanyContext::id())
                    ->groupBy('source_type', 'source_id')
                    ->havingRaw('ABS(COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0)) > 0.0001')
                    ->selectRaw('source_type, source_id,
                                 MIN(document_no) as document_no,
                                 COALESCE(SUM(debit), 0) as d,
                                 COALESCE(SUM(credit), 0) as c');
                /*
                 * প্রথম একশোটা।
                 *
                 * সব ভাঙা থাকলে (যেমন একটা মাইগ্রেশন সব ভেঙে দিলে)
                 * তালিকাটা হাজার সারির হত, আর পাতাটা খুলতই না।
                 * একশোটা দেখেই বোঝা যায় ধরনটা কী, আর সেটাই কাজ
                 * শুরু করার জন্য যথেষ্ট।
                 */

                [$rows, $more] = self::firstHundred($query);

                $out = [];

                foreach ($rows as $row) {
                    $debit = Money::of($row->d);
                    $credit = Money::of($row->c);

                    $out[] = new IntegrityFinding(
                        what: $row->document_no ?: ($row->source_type.'#'.$row->source_id),
                        detail: __('accounts::integrity.dr_cr_detail', [
                            'debit' => Money::format($debit, 2),
                            'credit' => Money::format($credit, 2),
                            'diff' => Money::format(bcsub($debit, $credit, 4), 2),
                        ]),
                        sourceType: $row->source_type,
                        sourceId: (int) $row->source_id,
                    );
                }

                return [...$out, ...$more];
            },
        );
    }

    /**
     * প্রতিটা সারির একটা খাত আছে, আর খাতটা সত্যিই আছে।
     *
     * ── কেন এটা আলাদা করে দেখা হয় ───────────────────────────────────
     * খাতহীন একটা সারি কোনো ব্যালেন্সে গোনা হয় না, অথচ রেওয়ামিলের
     * যোগফলে থাকে — অর্থাৎ Dr=Cr মিলেও যায়, শুধু টাকাটা কোথাও দেখা
     * যায় না। ঠিক এই ধরনের অদৃশ্যতা এই রিপোতেই একবার ঘটেছিল, যখন
     * কাউন্টারের নগদ একটা গ্রুপ-খাতে বসছিল।
     */
    public static function everyEntryHasAnAccount(): IntegrityCheck
    {
        return new IntegrityCheck(
            key: 'accounts.every_entry_has_an_account',
            label: __('accounts::integrity.orphan_entries'),
            question: __('accounts::integrity.orphan_entries_q'),
            whenBroken: __('accounts::integrity.orphan_entries_broken'),
            permission: 'accounts.report',
            run: function (): array {
                /*
                 * ⛔ মুছে ফেলা খাত আর অন্য কোম্পানির খাতও অনাথ — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি),
                 * ২ অক্টোবর ২০২৬ ([[TheBooksCheckMissedThreeKindsOfLostMoneyTest]])। ⓘ আগে কেবল না-থাকা খাত ধরা হত;
                 * সফট-ডিলিট খাতের সারি কোনো পর্দায় আসে না (মডেলের স্কোপ বাদ দেয়), তাই টাকাটা একই রকম অদৃশ্য।
                 */
                $query = DB::table('ledger_entries as le')
                    ->leftJoin('accounts as a', fn ($join) => $join
                        ->on('a.id', '=', 'le.account_id')
                        ->on('a.company_id', '=', 'le.company_id'))
                    ->where('le.company_id', CompanyContext::id())
                    ->where(fn ($q) => $q->whereNull('a.id')->orWhereNotNull('a.deleted_at'))
                    ->select(['le.id', 'le.document_no', 'le.source_type', 'le.source_id', 'le.account_id']);

                [$rows, $more] = self::firstHundred($query);

                $out = [];

                foreach ($rows as $row) {
                    $out[] = new IntegrityFinding(
                        what: $row->document_no ?: ('#'.$row->id),
                        detail: __('accounts::integrity.orphan_detail', ['account' => (string) $row->account_id]),
                        sourceType: $row->source_type,
                        sourceId: $row->source_id === null ? null : (int) $row->source_id,
                    );
                }

                return [...$out, ...$more];
            },
        );
    }

    /**
     * ⭐ প্রতিটা দাখিলার কাগজ এখনো আছে — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬
     * ([[TheBooksCheckMissedThreeKindsOfLostMoneyTest]])।
     *
     * ⛔ কাগজটা মুছে গেলে (বা সফট-ডিলিট হলে) অথচ তার দাখিলা উল্টানো না হলে টাকা খাতায় থেকে যায়, কিন্তু কোন কাগজের
     * তা আর খোলা যায় না — ড্রিল-ডাউনে "উৎস পাওয়া যাচ্ছে না"। ⓘ উল্টানো কাগজ (`…:reversal` সারি আছে) বাদ — বাতিল
     * করে মোছা কাগজের দাখিলা মিলে শূন্য। কাগজের ধরন চেনা হয় ড্রিলের তালিকা থেকে ([[DrillResolver::map()]]) — নতুন
     * মডিউলের কাগজ নিজে থেকেই আসে।
     */
    public static function everyEntryHasItsPaper(): IntegrityCheck
    {
        return new IntegrityCheck(
            key: 'accounts.every_entry_has_its_paper',
            label: __('accounts::integrity.paper_gone'),
            question: __('accounts::integrity.paper_gone_q'),
            whenBroken: __('accounts::integrity.paper_gone_broken'),
            permission: 'accounts.report',
            run: function (): array {
                $out = [];
                $total = 0;

                foreach (app(DrillResolver::class)->map() as $sourceType => $modelClass) {
                    /** @var Model $model */
                    $model = new $modelClass;
                    $table = $model->getTable();
                    $key = $model->getKeyName();
                    $soft = method_exists($model, 'getDeletedAtColumn') ? $model->getDeletedAtColumn() : null;

                    $query = DB::table('ledger_entries as le')
                        ->leftJoin($table.' as d', 'd.'.$key, '=', 'le.source_id')
                        ->where('le.company_id', CompanyContext::id())
                        ->where(fn ($q) => $q->where('le.source_type', $sourceType)->orWhere('le.source_type', $sourceType.':reversal'))
                        ->groupBy('le.source_id')
                        ->havingRaw('SUM(CASE WHEN le.source_type = ? THEN 1 ELSE 0 END) = 0', [$sourceType.':reversal'])
                        ->havingRaw($soft === null ? '(MAX(d.'.$key.') IS NULL)' : '(MAX(d.'.$key.') IS NULL OR MAX(d.'.$soft.') IS NOT NULL)')
                        ->selectRaw('le.source_id, MIN(le.document_no) as document_no');

                    $total += DB::query()->fromSub(clone $query, 'x')->count();

                    if (count($out) >= 100) {
                        continue;
                    }

                    foreach ($query->limit(100 - count($out))->get() as $row) {
                        $out[] = new IntegrityFinding(
                            what: $row->document_no ?: ($sourceType.'#'.$row->source_id),
                            detail: __('accounts::integrity.paper_gone_detail', ['type' => $sourceType, 'id' => (string) $row->source_id]),
                            sourceType: $sourceType,
                            sourceId: (int) $row->source_id,
                        );
                    }
                }

                return $total > count($out) ? [...$out, self::andMore($total, count($out))] : $out;
            },
        );
    }

    /**
     * ⭐ প্রতিটা নিয়ন্ত্রণ খাতের জের = তার পক্ষগুলোর জেরের যোগ — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (হিসাব, ঠিক ৫)।
     *
     * ⓘ নতুন সারিতে পক্ষ কেবল পক্ষ-রাখা খাতে বসে ([[PostingEngine]]), কিন্তু উল্টো দিকটা কেউ মাপত না: পাওনার খাতে পক্ষ
     * ছাড়া (বা অন্য ধরনের পক্ষসহ) পুরনো সারি থাকলে খাতের জের আর গ্রাহকদের বকেয়ার যোগ আলাদা — স্থিতিপত্র এক অঙ্ক বলে,
     * পক্ষের খাতা আরেক, আর কোনটা ঠিক কেউ জানে না।
     * ⭐ কেবল পড়ে, কিছু বদলায় না: প্রতিটা পক্ষ-রাখা খাতে মোট জের, খাতের নিজের ধরনের পক্ষের নামে বসা জের, আর ফারাক।
     * ⓘ জের ডেবিট−ক্রেডিট, সব শাখা জুড়ে — এটা যাচাই, দেখার শাখা নয়।
     */
    public static function partiesAddUpToTheirControlAccount(): IntegrityCheck
    {
        return new IntegrityCheck(
            key: 'accounts.parties_add_up_to_their_control_account',
            label: __('accounts::integrity.party_control'),
            question: __('accounts::integrity.party_control_q'),
            whenBroken: __('accounts::integrity.party_control_broken'),
            permission: 'accounts.report',
            run: function (): array {
                // ⓘ ONLY_FULL_GROUP_BY: কেবল গোষ্ঠীর কলাম আর যোগফল; নাম পরে আলাদা করে
                $rows = DB::table('ledger_entries as le')
                    ->join('accounts as a', fn ($join) => $join
                        ->on('a.id', '=', 'le.account_id')
                        ->on('a.company_id', '=', 'le.company_id'))
                    ->where('le.company_id', CompanyContext::id())
                    ->whereNotNull('a.party_types')
                    ->where('a.party_types', '<>', '[]')
                    ->groupBy('le.account_id')
                    ->selectRaw('le.account_id,
                        COALESCE(SUM(le.debit), 0) - COALESCE(SUM(le.credit), 0) AS control,
                        COALESCE(SUM(CASE WHEN le.party_id IS NOT NULL AND JSON_CONTAINS(a.party_types, JSON_QUOTE(le.party_type))
                            THEN le.debit - le.credit ELSE 0 END), 0) AS parties')
                    ->orderBy('le.account_id')
                    ->get()
                    ->filter(fn ($row) => bccomp(Money::of($row->control), Money::of($row->parties), 2) !== 0);

                if ($rows->isEmpty()) {
                    return [];
                }

                $accounts = DB::table('accounts')->whereIn('id', $rows->pluck('account_id'))->get(['id', 'code', 'name_en', 'name_bn'])->keyBy('id');

                return $rows->map(function ($row) use ($accounts): IntegrityFinding {
                    $account = $accounts->get($row->account_id);
                    $name = $account === null ? '#'.$row->account_id
                        : $account->code.' — '.((app()->getLocale() === 'bn' ? $account->name_bn : null) ?: $account->name_en);

                    return new IntegrityFinding(
                        what: $name,
                        detail: __('accounts::integrity.party_control_detail', [
                            'control' => Money::format(Money::of($row->control), 2),
                            'parties' => Money::format(Money::of($row->parties), 2),
                            'diff' => Money::format(bcsub(Money::of($row->control), Money::of($row->parties), 4), 2),
                        ]),
                    );
                })->values()->all();
            },
        );
    }

    /**
     * প্রথম একশোটা — আর বাকিগুলো কয়টা, একটা সারিতে।
     *
     * ⛔ আগে একশোয় থেমে যেত আর কিছু বলত না — ৫,০০০ ভাঙা কাগজ আর ১০০টা একই রকম দেখাত, অথচ প্রথমটা একটা
     * মাইগ্রেশনের দুর্ঘটনা আর দ্বিতীয়টা কয়েকটা ভুল হাতের কাজ (abos-bb-র নিরীক্ষা, ২ অক্টোবর ২০২৬)।
     *
     * @return array{0: Collection<int, object>, 1: list<IntegrityFinding>}
     */
    private static function firstHundred(Builder $query): array
    {
        $total = DB::query()->fromSub(clone $query, 'x')->count();
        $rows = $query->limit(100)->get();

        return [$rows, $total > $rows->count() ? [self::andMore($total, $rows->count())] : []];
    }

    private static function andMore(int $total, int $shown): IntegrityFinding
    {
        return new IntegrityFinding(
            what: __('accounts::integrity.and_more_what'),
            detail: __('accounts::integrity.and_more', ['more' => (string) ($total - $shown), 'total' => (string) $total]),
        );
    }
}
