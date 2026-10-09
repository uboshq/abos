<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Core\Contracts\CustomerTrade;
use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\ViewedBranch;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Modules\Customer\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * গ্রাহকের এক নজরের সারাংশ — তালিকার 👁।
 *
 * ── ⭐ মালিকের নির্দেশ, ২৭ সেপ্টেম্বর ২০২৬ ─────────────────────────────
 * 👁 চাপলে বিস্তারিত খাতা খুলত, আর কাউন্টারে দাঁড়িয়ে "এই দোকানের অবস্থা
 * কী" জানতে পঞ্চাশ সারি পড়তে হত। এখন এক পাতায়: বিল (এই মাস ও সারা
 * জীবন), বকেয়া, বকেয়া বিল ও তাতে পণ্য, শেষ কেনা, শেষ জমা, অবশিষ্ট সীমা।
 * ⓘ খাতা কেবল বকেয়ার অঙ্কের লিংকে — অঙ্কটাই তার উৎসে নিয়ে যায় (নিয়ম ১)।
 *
 * ── ⛔ কোনো অঙ্ক নতুন করে গোনা হয় না ─────────────────────────────────
 * বকেয়া [[Customer::outstanding()]] (তালিকা ও খাতার পাতার একই উৎস),
 * অবশিষ্ট সীমা [[Customer::availableLimit()]] (সীমা − বকেয়া − আটকে থাকা,
 * কাউন্টারের হুবহু), আর বিলের অংশ বিক্রয়ের [[CustomerTrade]]।
 *
 * ⓘ অনুমতি গ্রাহকের পাতারই — `can:view,customer`। অন্য কোম্পানির গ্রাহক
 * রুট-মডেল বাইন্ডিংয়েই ৪০৪ ([[BelongsToCompany]]-এর গ্লোবাল স্কোপ)।
 */
final class CustomerSummaryController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly SettingsService $settings,
        private readonly CustomerTrade $trade,
    ) {}

    public static function middleware(): array
    {
        return [new Middleware('can:view,customer')];
    }

    public function __invoke(Request $request, Customer $customer): View
    {
        $today = Carbon::today();

        return view('customer::summary', [
            'menu' => $this->menu->forUser($request->user()),
            'customer' => $customer,
            /*
             * ⛔ হেডারে বাছা শাখায় — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (গ্রাহক ১৩; [[TheSummaryFollowsTheBranchInTheHeaderTest]])।
             * ⓘ কার্ডের বিলের অঙ্ক বিলের শাখার দেয়ালে ছাঁকা, অথচ বকেয়া গোটা কোম্পানির — "ময়মনসিংহ" বেছে নেত্রকোনার বকেয়াও দেখাত, আর
             * অঙ্কটা চাপলে যে খাতা খোলে তার শেষ জেরের সাথে মিলত না। এখন গ্রাহকের পাতার একই ছাঁকনি ([[CustomerController::show()]])।
             */
            'outstanding' => bcadd((string) (ViewedBranch::narrow(LedgerEntry::query(), 'ledger_entries.branch_id')
                ->forParty(Customer::drillSourceType(), $customer->id)
                ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) as net')
                ->value('net') ?? 0), '0', 4),
            'creditLimitOn' => $this->settings->enabled('customer.credit_limit_enabled'),
            'trade' => $this->trade->glanceFor(
                (int) $customer->id,
                $today->copy()->startOfMonth()->toDateString(),
                $today->toDateString(),
            ),
        ]);
    }
}
