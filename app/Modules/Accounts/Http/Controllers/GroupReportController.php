<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Modules\Accounts\Services\GroupLedgerService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\View\View;

/**
 * গ্রুপের হিসাব — এক মালিকের সব কোম্পানি এক পাতায়।
 *
 * ── ⛔ কেন নিজের একটা অনুমতি, `accounts.report.final` নয় ────────────
 * ⚠️ বাকি সব রিপোর্ট **একটা** কোম্পানির ভিতরে থাকে; এই পাতাটা সীমানা
 * পেরোয়। ⓘ তাই যিনি ADI-র চূড়ান্ত হিসাব দেখতে পারেন, তিনি
 * স্বয়ংক্রিয়ভাবে TCL ও DEM-এর যোগফলও দেখবেন — এটা ধরে নেওয়া যায় না।
 * ⭐ আলাদা অনুমতি মানে মালিক ঠিক করেন কে গ্রুপের ছবিটা দেখবে।
 *
 * ── ⓘ ফাঁস কেন সম্ভব নয় ─────────────────────────────────────────────
 * [[GroupLedgerService]] কোম্পানিগুলো নেয় **এই ব্যবহারকারীর**
 * `company_user` পিভট থেকে। ⚠️ অনুমতিটা "সব কোম্পানি" খোলে না —
 * কেবল এই পাতাটা খোলে, আর পাতাটা তাঁর নিজের কোম্পানিগুলোই দেখায়।
 */
class GroupReportController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly GroupLedgerService $ledger,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:accounts.report.group')];
    }

    public function show(Request $request): View
    {
        $from = $request->query('from');
        $to = $request->query('to');

        return view('accounts::report.group', [
            'menu' => $this->menu->forUser($request->user()),
            'group' => $this->ledger->build(
                $request->user(),
                is_string($from) && $from !== '' ? $from : null,
                is_string($to) && $to !== '' ? $to : null,
            ),
        ]);
    }
}
