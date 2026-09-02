<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\MoneyTransfer;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\AccountsFacts;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
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
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly AccountsFacts $facts,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.view')];
    }

    /*
     * ── সংখ্যাগুলো আর এই ফাইলে গোনা হয় না ──────────────────────────
     * হিসাবগুলো [[AccountsFacts]]-এ সরানো হয়েছে (২ সেপ্টেম্বর ২০২৬),
     * একটা লাইনও না বদলে। কারণ ইঞ্জিনের ছকে দ্বিতীয় একটা ড্যাশবোর্ড
     * এসেছে, আর দুই জায়গায় দুই `SUM` মানে একদিন দুই উত্তর।
     */
    public function show(Request $request): View
    {
        $tills = CashTill::query()->active()->with('account')->get();

        return view('accounts::dashboard.show', [
            'menu' => $this->menu->forUser($request->user()),

            // হাতে নগদ — সব কাউন্টার মিলে
            'cashInHand' => $this->facts->sumOf($tills->pluck('account_id')->all()),

            'bankBalance' => $this->facts->sumOf(
                Account::query()->where('is_bank', true)->postable()->pluck('id')->all()
            ),

            'receivable' => $this->facts->receivable(),
            'payable' => $this->facts->payable(),

            // এই মাসের আয় ও খরচ — লাভ-লোকসানের সারাংশ
            'incomeThisMonth' => $this->facts->incomeThisMonth(),
            'expenseThisMonth' => $this->facts->expenseThisMonth(),

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
            'tillBalances' => $this->facts->tillBalances($tills),
        ]);
    }
}
