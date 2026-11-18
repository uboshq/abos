<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Services\PartyRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\ViewedBranch;
use App\Models\FinancialYear;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ দৈনিক খতিয়ান (Day Book) — একটা দিনের প্রতিটা খাতা-এন্ট্রি, জার্নাল ধরে ভাগ করে, প্রতিটা এন্ট্রির সব লাইন একসাথে।
 *
 * মালিকের অনুমোদিত নমুনা (১৬ নভেম্বর ২০২৬, "ok": Day Closing v7 + Day Book): সময় · এন্ট্রি নং · রেফারেন্স · পার্টি/বিবরণ ·
 * কোড · হিসাব · ডেবিট · ক্রেডিট · কে; জার্নাল ধরে উপমোট, পাতা পেরোলে জের টানা।
 *
 * ── জার্নাল কীভাবে ঠিক হয় ───────────────────────────────────────────
 * কাগজের ধরন (`source_type`, ":reversal" বাদে) আর এন্ট্রির লাইনগুলো দেখে:
 *   · বিক্রয় — বিক্রির বিল, চালান, বিক্রি ফেরত;
 *   · ক্রয় — ক্রয়ের বিল, ক্রয় ফেরত;
 *   · নগদ — বাকি সব কাগজ, যার কোনো লাইন নগদ হিসাবে (money_kind = cash);
 *   · ব্যাংক — নগদ ছোঁয়নি, কিন্তু ব্যাংক বা মোবাইল ব্যাংকিং ছুঁয়েছে;
 *   · সাধারণ — বাকি সব (জাবেদা, নোট, শুরুর জের, মজুদ সমন্বয়)।
 *
 * ── এন্ট্রি নম্বর ────────────────────────────────────────────────────
 * "SAL/2026/00413" — জার্নালের ছোট নাম / অর্থবছরের শুরুর বছর / ঐ অর্থবছরে ঐ জার্নালের কততম এন্ট্রি। ক্রম পোস্ট হওয়ার ক্রমে
 * (প্রথম লাইনের id), তারিখের ক্রমে নয় — তাই পরে পেছনের তারিখে কিছু বসলে আগের নম্বর বদলায় না। গোনা হয় গোটা কোম্পানিতে,
 * শাখা বাছলেও নম্বর একই থাকে।
 *
 * ⓘ খতিয়ানে কেবল পোস্ট হওয়া কাগজের লাইন থাকে — খসড়া বা অনুমোদনের অপেক্ষায় থাকা কাগজ এখানে আসেই না।
 */
final class DayBookService
{
    public const SALES = 'sales';

    public const PURCHASE = 'purchase';

    public const CASH = 'cash';

    public const BANK = 'bank';

    public const JOURNAL = 'journal';

    /** @var list<string> ছাপার ক্রম */
    public const ORDER = [self::SALES, self::PURCHASE, self::CASH, self::BANK, self::JOURNAL];

    private const PREFIX = [self::SALES => 'SAL', self::PURCHASE => 'PUR', self::CASH => 'CSH', self::BANK => 'BNK', self::JOURNAL => 'JRN'];

    private const SALES_TYPES = ['sales_invoice', 'delivery_challan', 'sales_return', 'pos_sale', 'pos_return'];

    private const PURCHASE_TYPES = ['purchase_bill', 'purchase_return', 'goods_receipt'];

    /**
     * @return array{date: string, journals: list<array{key: string, entries: list<array<string, mixed>>, debit: string, credit: string}>,
     *               debit: string, credit: string, entries: int, branch_id: ?int}
     */
    public function build(string $date, ?int $branchId = null): array
    {
        $company = (int) CompanyContext::id();
        $filters = ['company_id' => $company, 'branch_id' => $branchId];

        $lines = DB::table('ledger_entries as le')
            ->join('accounts as a', fn ($j) => $j->on('a.id', '=', 'le.account_id')->on('a.company_id', '=', 'le.company_id'))
            ->where('le.company_id', $company)
            ->where('le.trx_date', $date)
            ->tap(ReportEngine::branchWall($filters, 'le.branch_id'))
            // ⭐ শাখা না বললে হেডারে বাছা শাখা আর নাগাল — মালিকের নিয়ম "হেডারের শাখা সব ছাঁকে"; সীমাহীন নাগালে কোনো ছাঁকনিই নয়, আগের মতোই
            ->when($branchId === null, fn ($q) => ViewedBranch::narrow($q, 'le.branch_id'))
            ->orderBy('le.id')
            ->get([
                'le.id', 'le.source_type', 'le.source_id', 'le.document_no', 'le.narration', 'le.party_type', 'le.party_id',
                'le.debit', 'le.credit', 'le.created_by', 'le.created_at', 'le.account_id',
                'a.code', 'a.name_en', 'a.name_bn', 'a.money_kind',
            ]);

        $numbers = $this->numbers($company, $date);
        $parties = $this->parties($lines);
        $users = User::query()->whereIn('id', $lines->pluck('created_by')->filter()->unique()->all())->pluck('name', 'id');

        $entries = [];

        foreach ($lines->groupBy(fn ($l) => $l->source_type.'#'.$l->source_id) as $key => $group) {
            $first = $group->first();
            $journal = self::journalOf((string) $first->source_type, $group->pluck('money_kind')->all());
            $party = $group->first(fn ($l) => $l->party_type !== null && $l->party_id !== null);
            $narration = $group->first(fn ($l) => trim((string) $l->narration) !== '')?->narration;

            // ⓘ ডেবিটগুলো আগে, তারপর ক্রেডিট — খাতা লেখার প্রচলিত ক্রম; একই পাশে পোস্ট হওয়ার ক্রমেই
            $sorted = $group->sortBy(fn ($l) => [bccomp((string) $l->debit, '0', 4) > 0 ? 0 : 1, (int) $l->id])->values();

            $entries[] = [
                'journal' => $journal,
                'first_id' => (int) $first->id,
                'time' => $first->created_at ? Carbon::parse($first->created_at)->format('H:i') : '',
                'number' => $numbers[$key] ?? null,
                'document_no' => (string) $first->document_no,
                'source_type' => (string) $first->source_type,
                'source_id' => (int) $first->source_id,
                'reversal' => str_ends_with((string) $first->source_type, ':reversal'),
                'party' => $party ? ($parties[$party->party_type.':'.$party->party_id] ?? null) : null,
                'narration' => $narration !== null ? (string) $narration : null,
                'who' => $first->created_by !== null ? (string) ($users[$first->created_by] ?? '') : '',
                'lines' => $sorted->map(fn ($l) => [
                    'code' => (string) $l->code,
                    'account' => (string) ((app()->getLocale() === 'bn' ? $l->name_bn : null) ?: $l->name_en),
                    'debit' => (string) $l->debit,
                    'credit' => (string) $l->credit,
                ])->all(),
                'debit' => (string) $group->reduce(fn ($s, $l) => bcadd($s, (string) $l->debit, 4), '0'),
                'credit' => (string) $group->reduce(fn ($s, $l) => bcadd($s, (string) $l->credit, 4), '0'),
            ];
        }

        $journals = [];
        $debit = '0';
        $credit = '0';

        foreach (self::ORDER as $journal) {
            $in = array_values(array_filter($entries, fn ($e) => $e['journal'] === $journal));

            if ($in === []) {
                continue;
            }

            usort($in, fn ($a, $b) => $a['first_id'] <=> $b['first_id']);
            $d = array_reduce($in, fn ($s, $e) => bcadd($s, $e['debit'], 4), '0');
            $c = array_reduce($in, fn ($s, $e) => bcadd($s, $e['credit'], 4), '0');
            $journals[] = ['key' => $journal, 'entries' => $in, 'debit' => $d, 'credit' => $c];
            $debit = bcadd($debit, $d, 4);
            $credit = bcadd($credit, $c, 4);
        }

        return [
            'date' => $date,
            'journals' => $journals,
            'debit' => $debit,
            'credit' => $credit,
            'entries' => count($entries),
            'branch_id' => $branchId,
        ];
    }

    /**
     * কোন জার্নাল — কাগজের ধরন আগে, তারপর লাইনের টাকার ধরন।
     *
     * @param  list<?string>  $moneyKinds  এন্ট্রির লাইনগুলোর হিসাবের money_kind
     */
    public static function journalOf(string $sourceType, array $moneyKinds): string
    {
        $base = explode(':', $sourceType)[0];

        return match (true) {
            in_array($base, self::SALES_TYPES, true) => self::SALES,
            in_array($base, self::PURCHASE_TYPES, true) => self::PURCHASE,
            in_array('cash', $moneyKinds, true) => self::CASH,
            in_array('bank', $moneyKinds, true) || in_array('mfs', $moneyKinds, true) => self::BANK,
            default => self::JOURNAL,
        };
    }

    /**
     * অর্থবছরের প্রতিটা এন্ট্রির জার্নাল-নম্বর — পোস্টের ক্রমে, গোটা কোম্পানিতে।
     *
     * @return array<string, string> "ধরন#id" => "SAL/2026/00413"
     */
    private function numbers(int $company, string $date): array
    {
        $year = FinancialYear::forDate($date);
        $starts = $year?->starts_on ? Carbon::parse($year->starts_on) : Carbon::parse($date)->startOfYear();

        $groups = DB::table('ledger_entries as le')
            ->join('accounts as a', fn ($j) => $j->on('a.id', '=', 'le.account_id')->on('a.company_id', '=', 'le.company_id'))
            ->where('le.company_id', $company)
            ->when($year !== null,
                fn ($q) => $q->where('le.financial_year_id', $year->id),
                fn ($q) => $q->whereBetween('le.trx_date', [$starts->toDateString(), $starts->copy()->endOfYear()->toDateString()]))
            ->groupBy('le.source_type', 'le.source_id')
            ->selectRaw("le.source_type, le.source_id, MIN(le.id) AS first_id,
                MAX(CASE WHEN a.money_kind = 'cash' THEN 1 ELSE 0 END) AS has_cash,
                MAX(CASE WHEN a.money_kind IN ('bank', 'mfs') THEN 1 ELSE 0 END) AS has_bank")
            ->orderBy('first_id')
            ->get();

        $count = [];
        $out = [];
        $yearLabel = $starts->format('Y');

        foreach ($groups as $g) {
            $journal = self::journalOf((string) $g->source_type, array_values(array_filter([
                (int) $g->has_cash === 1 ? 'cash' : null,
                (int) $g->has_bank === 1 ? 'bank' : null,
            ])));
            $count[$journal] = ($count[$journal] ?? 0) + 1;
            $out[$g->source_type.'#'.$g->source_id] = self::PREFIX[$journal].'/'.$yearLabel.'/'.str_pad((string) $count[$journal], 5, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    /**
     * @param  iterable<object>  $lines
     * @return array<string, string> "ধরন:id" => "নাম · পয়েন্ট · শাখা"
     */
    private function parties(iterable $lines): array
    {
        $pairs = [];

        foreach ($lines as $l) {
            if ($l->party_type !== null && $l->party_id !== null) {
                $pairs[$l->party_type.':'.$l->party_id] = [(string) $l->party_type, (int) $l->party_id];
            }
        }

        return $pairs === [] ? [] : app(PartyRegistry::class)->labelsOf(array_values($pairs));
    }
}
