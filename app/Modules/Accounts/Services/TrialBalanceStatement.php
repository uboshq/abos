<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\PeriodLock;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use Illuminate\Support\Carbon;

/**
 * ⭐ রেওয়ামিল — বিবরণী আকারে: হিসাবের ধরন ধরে দল, প্রতিটার উপমোট, উপরে অবস্থার সারি (মালিক, ১৭ নভেম্বর ২০২৬ "ok":
 * NEXUS-এর নমুনার মানে, আমাদের নিয়মে)।
 *
 * ⓘ অঙ্ক এখানে কষা হয় না — রিপোর্ট-ইঞ্জিনের রেওয়ামিলের একই সারি ([[CoreReports::trialBalance()]]), একই ছাঁকনি আর শাখার
 *   দেয়াল। তাই পর্দা, CSV, ফোন আর কাগজ একই অঙ্ক বলে; এখানে কেবল সাজানো আর যাচাই:
 *   - দল: সম্পদ, দায়, মালিকানা স্বত্ব, আয়, খরচ — খাতের ধরন থেকে; প্রতিটার ছয় ঘরের উপমোট;
 *   - তিন জোড়া (শুরু, লেনদেন, শেষ) ডেবিট = ক্রেডিট কি না, আর না মিললে কত;
 *   - উল্টো জের: খাতের প্রকৃতির উল্টো দিকে শেষ জের (নগদ বা ব্যাংক ক্রেডিটে, দেনা ডেবিটে) — ⚠;
 *   - বাদ পড়া ভাউচার: এই সময়ের খসড়া আর অনুমোদন-বাকি — গোনা হয়, যোগে নয় (মোটে কেবল খাতায় ওঠা কাগজ);
 *   - তালা দেওয়া মাস: এই সময়ের যে মাসগুলো বন্ধ।
 */
final class TrialBalanceStatement
{
    /** দলের ক্রম — IAS 1-এর মতো, আগে স্থিতিপত্রের তিন, তারপর লাভ-ক্ষতির দুই */
    public const CLASSES = [Account::ASSET, Account::LIABILITY, Account::EQUITY, Account::INCOME, Account::EXPENSE];

    /**
     * ⓘ যে খাতের জের দুই দিকেই স্বাভাবিক, বা প্রকৃতিই উল্টো অথচ ছকে ধরনের ডিফল্ট প্রকৃতি বসে — ⚠ নয়।
     *   বিক্রয় ফেরত আর আদায়ে ছাড় বিক্রয়ের বিপরীত (ডেবিট); ক্রয়মূল্যের পার্থক্য, মজুদ ঘাটতি-উদ্বৃত্ত আর রাউন্ডিং দুই দিকেই
     *   হয়; ভাই-কোম্পানির চলতি হিসাব কখনো পাওনা, কখনো দেনা।
     */
    public const TWO_WAY = [
        StandardChart::SALES_RETURN, StandardChart::COLLECTION_DISCOUNT,
        StandardChart::PURCHASE_PRICE_VARIANCE, StandardChart::INVENTORY_SHORTAGE_SURPLUS,
        StandardChart::ROUNDING_DIFFERENCE, StandardChart::INTER_COMPANY,
    ];

    /** ছয় ঘর — ইঞ্জিনের সারির চাবি, এই ক্রমে */
    public const CELLS = ['opening_debit', 'opening_credit', 'period_debit', 'period_credit', 'debit', 'credit'];

    public function __construct(private readonly ReportEngine $reports) {}

    /**
     * @param  array<string, mixed>  $request  পাতার ছাঁকনি (from, to, branch_id) — ইঞ্জিন নিজে যাচাই করে আর দেয়াল বসায়
     * @return array<string, mixed>
     */
    public function build(array $request): array
    {
        // ⓘ সব সারি একবারে — পাতা ভাগ নয়; রেওয়ামিলের সারি খাতের সংখ্যার বেশি হয় না
        $result = $this->reports->run('accounts.trial_balance', $request, perPage: 100000);
        $f = $result->filters;

        $ids = array_map(fn (array $r) => (int) $r['account_id'], $result->rows);
        $accounts = Account::query()->withTrashed()->with('parent')->whereKey($ids ?: [0])->get()->keyBy('id');

        $groups = [];
        foreach ([...self::CLASSES, 'other'] as $type) {
            $groups[$type] = ['type' => $type, 'rows' => [], 'sum' => array_fill_keys(self::CELLS, '0')];
        }

        $total = array_fill_keys(self::CELLS, '0');
        $wrong = [];

        foreach ($result->rows as $r) {
            $account = $accounts->get((int) $r['account_id']);
            $type = $account !== null && in_array($account->type, self::CLASSES, true) ? $account->type : 'other';

            $values = [];
            foreach (self::CELLS as $cell) {
                $values[$cell] = bcadd((string) ($r[$cell] ?? '0') ?: '0', '0', 4);
                $groups[$type]['sum'][$cell] = bcadd($groups[$type]['sum'][$cell], $values[$cell], 4);
                $total[$cell] = bcadd($total[$cell], $values[$cell], 4);
            }

            $row = [
                'account_id' => (int) $r['account_id'],
                'code' => (string) ($account->code ?? ''),
                'name' => $account?->name() ?? (string) $r['account_name'],
                'group' => $account?->parent?->name() ?? '',
                'values' => $values,
                'wrong_side' => $account !== null && $this->wrongSide($account, $values),
            ];

            if ($row['wrong_side']) {
                $wrong[] = [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    // ⓘ কোন দিকে আছে, আর কত — "নগদ ক্রেডিটে 8,200.00"
                    'side' => bccomp($values['credit'], '0', 4) > 0 ? Account::CREDIT : Account::DEBIT,
                    'amount' => bccomp($values['credit'], '0', 4) > 0 ? $values['credit'] : $values['debit'],
                ];
            }

            $groups[$type]['rows'][] = $row;
        }

        $groups = array_values(array_filter($groups, fn (array $g) => $g['rows'] !== []));

        $gaps = [
            'opening' => bcsub($total['opening_debit'], $total['opening_credit'], 4),
            'period' => bcsub($total['period_debit'], $total['period_credit'], 4),
            'closing' => bcsub($total['debit'], $total['credit'], 4),
        ];

        return [
            'from' => (string) $f['from'],
            'to' => (string) $f['to'],
            'all_time' => (bool) ($f['all_time'] ?? false),
            'branch_id' => $f['branch_id'] ?? null,
            'filters' => $f,
            'groups' => $groups,
            'total' => $total,
            'gaps' => $gaps,
            'agrees' => collect($gaps)->every(fn (string $gap) => bccomp($gap, '0', 2) === 0),
            'wrong_side' => $wrong,
            'held' => $this->heldBack($f),
            'locked' => $this->lockedMonths($f),
        ];
    }

    /**
     * ⚠ উল্টো জের — শেষ জের খাতের প্রকৃতির উল্টো দিকে। ⓘ শূন্য জের কখনো উল্টো নয়; দুই-দিকের খাত ([[TWO_WAY]]) বাদ।
     *
     * @param  array<string, string>  $values
     */
    public function wrongSide(Account $account, array $values): bool
    {
        if (in_array((string) $account->code, self::TWO_WAY, true)) {
            return false;
        }

        $nature = $account->nature ?: Account::defaultNatureFor((string) $account->type);

        return $nature === Account::DEBIT
            ? bccomp($values['credit'], '0', 2) > 0
            : bccomp($values['debit'], '0', 2) > 0;
    }

    /**
     * এই সময়ের যে ভাউচার খাতায় ওঠেনি — খসড়া (তার মধ্যে অনুমোদন-বাকি কয়টা), আর মোট অঙ্ক। ⓘ একই শাখার দেয়াল।
     *
     * @param  array<string, mixed>  $f
     * @return array{count: int, awaiting: int, amount: string}
     */
    private function heldBack(array $f): array
    {
        $drafts = Voucher::query()
            ->where('vouchers.status', DocumentStatus::DRAFT)
            ->where('vouchers.trx_date', '<=', $f['to'])
            ->when(empty($f['all_time']), fn ($q) => $q->where('vouchers.trx_date', '>=', $f['from']))
            ->tap(ReportEngine::branchWall($f, 'vouchers.branch_id'));

        $awaiting = (clone $drafts)->whereExists(fn ($q) => $q->selectRaw('1')->from('approvals')
            ->whereColumn('approvals.approvable_id', 'vouchers.id')
            ->where('approvals.approvable_type', (new Voucher)->getMorphClass())
            ->where('approvals.status', Approval::PENDING))
            ->count();

        return [
            'count' => (clone $drafts)->count(),
            'awaiting' => $awaiting,
            'amount' => bcadd((string) ((clone $drafts)->sum('vouchers.amount') ?: '0'), '0', 4),
        ];
    }

    /**
     * এই সময়ের তালা দেওয়া মাস — "জুলাই 2026"। ⓘ "শুরু থেকে" হলে শেষ তারিখ পর্যন্ত সব।
     *
     * @param  array<string, mixed>  $f
     * @return list<string>
     */
    private function lockedMonths(array $f): array
    {
        $from = Carbon::parse((string) $f['from']);
        $to = Carbon::parse((string) $f['to']);
        $low = empty($f['all_time']) ? $from->year * 100 + $from->month : 0;
        $high = $to->year * 100 + $to->month;

        return PeriodLock::query()
            ->whereRaw('(year * 100 + month) between ? and ?', [$low, $high])
            ->orderBy('year')->orderBy('month')
            ->get()
            ->map(fn (PeriodLock $lock) => Carbon::create($lock->year, $lock->month, 1)->locale(app()->getLocale())->translatedFormat('F').' '.$lock->year)
            ->values()
            ->all();
    }
}
