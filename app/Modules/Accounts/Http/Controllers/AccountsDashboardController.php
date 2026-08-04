<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * হিসাবের ড্যাশবোর্ড।
 *
 * প্রতিটা সংখ্যা ক্লিকযোগ্য (নিয়ম ১) — "হাতে নগদ ১৫,৫০০" দেখে ক্লিক
 * করলে কোন কাউন্টারে কত তা দেখা যায়। যে সংখ্যায় ক্লিক করা যায় না
 * সেটা ড্যাশবোর্ডে রাখার মানে নেই: ব্যবহারকারী তখন সংখ্যাটা বিশ্বাস
 * করতে বাধ্য, যাচাই করতে পারে না।
 *
 * খসড়া ও অপেক্ষমাণ কাজগুলোও এখানে, কারণ ওগুলোই আসলে "আজ কী করতে
 * হবে" — আর সেটাই ড্যাশবোর্ডের একমাত্র কাজ।
 */
class AccountsDashboardController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.view')];
    }

    public function show(Request $request): View
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();

        $tills = CashTill::query()->active()->with('account')->get();

        return view('accounts::dashboard.show', [
            'menu' => $this->menu->forUser($request->user()),

            // হাতে নগদ — সব কাউন্টার মিলে
            'cashInHand' => $this->sumOf($tills->pluck('account_id')->all()),

            'bankBalance' => $this->sumOf(
                Account::query()->where('is_bank', true)->postable()->pluck('id')->all()
            ),

            'receivable' => $this->balanceOfCode(StandardChart::RECEIVABLE),
            'payable' => $this->balanceOfCode(StandardChart::PAYABLE),

            // এই মাসের আয় ও খরচ — লাভ-লোকসানের সারাংশ
            'incomeThisMonth' => $this->netOfType(Account::INCOME, $monthStart, $today),
            'expenseThisMonth' => $this->netOfType(Account::EXPENSE, $monthStart, $today),

            /*
             * যা করতে বাকি।
             *
             * খসড়া ভাউচার কোনো হিসাবে নেই, আর অপেক্ষমাণ হস্তান্তরের টাকা
             * এখনো দাতার হাতে — দুইটাই এমন অবস্থা যা কেউ ইচ্ছাকৃতভাবে
             * রেখে দেয় না, শুধু ভুলে যায়।
             */
            'draftVouchers' => Voucher::query()->draft()->count(),
            'pendingTransfers' => MoneyTransfer::query()->pending()->count(),

            'tills' => $tills,
            'tillBalances' => $this->tillBalances($tills),
        ]);
    }

    /**
     * কয়েকটা খাতের মোট ব্যালেন্স — একটা কোয়েরিতে।
     *
     * @param  list<int>  $accountIds
     */
    private function sumOf(array $accountIds): string
    {
        if ($accountIds === []) {
            return '0';
        }

        $row = LedgerEntry::query()
            ->whereIn('account_id', $accountIds)
            ->selectRaw('COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->first();

        $opening = Account::query()->whereIn('id', $accountIds)->sum('opening_balance');

        return bcadd((string) $opening, bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4), 4);
    }

    private function balanceOfCode(string $code): string
    {
        return StandardChart::find($code)?->balanceOn() ?? '0';
    }

    /** এক ধরনের সব খাতের নিট — স্বাভাবিক দিকে ধনাত্মক। */
    private function netOfType(string $type, Carbon $from, Carbon $to): string
    {
        $row = LedgerEntry::query()
            ->join('accounts', 'accounts.id', '=', 'ledger_entries.account_id')
            ->where('accounts.type', $type)
            ->whereBetween('ledger_entries.trx_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('COALESCE(SUM(ledger_entries.debit), 0) as d, COALESCE(SUM(ledger_entries.credit), 0) as c')
            ->first();

        $net = bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4);

        // আয় ক্রেডিট প্রকৃতির, তাই চিহ্ন উল্টে দিলে সংখ্যাটা ধনাত্মক হয় —
        // "এই মাসের আয় −২০,০০০" দেখানোর কোনো মানে নেই
        return $type === Account::INCOME ? bcmul($net, '-1', 4) : $net;
    }

    /**
     * @param  Collection<int, CashTill>  $tills
     * @return array<int, string>
     */
    private function tillBalances(Collection $tills): array
    {
        if ($tills->isEmpty()) {
            return [];
        }

        $sums = LedgerEntry::query()
            ->whereIn('account_id', $tills->pluck('account_id'))
            ->groupBy('account_id')
            ->selectRaw('account_id, COALESCE(SUM(debit), 0) as d, COALESCE(SUM(credit), 0) as c')
            ->get()
            ->keyBy('account_id');

        $out = [];

        foreach ($tills as $till) {
            $row = $sums[$till->account_id] ?? null;

            $out[$till->id] = bcadd(
                (string) $till->account->opening_balance,
                bcsub((string) ($row->d ?? 0), (string) ($row->c ?? 0), 4),
                4,
            );
        }

        return $out;
    }
}
