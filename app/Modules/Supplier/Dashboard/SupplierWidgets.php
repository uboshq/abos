<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Dashboard;

use App\Core\Contracts\DashboardWidgets;
use App\Core\Dashboard\Widget;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * কোম্পানির সাথে হিসাবটা কোথায় দাঁড়িয়ে — হোম পর্দায়।
 *
 * ── কেন এই দুইটা সংখ্যা রোজ দরকার ───────────────────────────────────
 * পরিবেশক ডিপোর সবচেয়ে বড় ভুলটা রোজকার: **ব্যাংকে টাকা দেখে সেটাকে
 * নিজের ভাবা।** ডিলারের আদায় করা টাকার বড় অংশ আসলে কোম্পানির — কেবল
 * কয়েক দিনের জন্য আপনার হাতে। খরচ করে ফেললে সপ্তাহ শেষে পাঠানোর টাকা
 * থাকে না, আর ঘাটতিটা প্রতি সপ্তাহে বাড়ে।
 *
 * তাই "কোম্পানিকে দিতে হবে" সংখ্যাটা রোজ চোখের সামনে থাকা দরকার, ঠিক
 * নগদের পাশে — নাহলে দুইটা মিলিয়ে দেখার কথা কারও মনে থাকে না।
 *
 * ── কেন মার্জিনটা মাসের দলে ─────────────────────────────────────────
 * ৪% মার্জিনের ব্যবসায় একদিনের মুনাফা কিছুই বলে না; মাসের অঙ্কটাই বলে
 * ডিপোটা চলছে কি না। আর ওটা মাস শেষে কোম্পানির লেজারের সাথে মেলানোর
 * সময় প্রথম যে সংখ্যাটা লাগে, সেটাও এটাই।
 */
final class SupplierWidgets implements DashboardWidgets
{
    /** @return list<Widget> */
    public static function widgets(): array
    {
        return array_values(array_filter([
            self::owedToPrincipals(),
            self::marginThisMonth(),
        ]));
    }

    /**
     * সব কোম্পানি মিলিয়ে এখনো কত দিতে বাকি।
     *
     * খতিয়ান থেকে গোনা, সরবরাহকারীর টেবিলের কোনো কলাম থেকে নয় — তাই
     * সংখ্যাটা প্রদেয়ের তালিকার সাথে সংজ্ঞা অনুযায়ীই মেলে।
     */
    private static function owedToPrincipals(): ?Widget
    {
        $owed = (string) (DB::table('ledger_entries')
            ->where('company_id', CompanyContext::id())
            ->where('party_type', Supplier::drillSourceType())
            ->selectRaw('COALESCE(SUM(credit) - SUM(debit), 0) as owed')
            ->value('owed') ?? '0');

        if (bccomp($owed, '0', 4) <= 0) {
            return null;
        }

        return new Widget(
            group: 'todo',
            label: __('supplier::widget.owed_to_principals'),
            value: Money::format($owed),
            href: route('supplier.report.show', ['slug' => 'payable-list']),
            permission: 'supplier.report',
            tone: 'warn',
            hint: __('supplier::widget.owed_hint'),
            sort: 20,
            icon: 'purchase',
        );
    }

    /**
     * এই মাসে কোম্পানির মাল বেচে কত মার্জিন দাঁড়াল।
     *
     * ── কেন খতিয়ান নয়, বিলের অঙ্ক ───────────────────────────────────
     * মার্জিন = বিক্রয় − বিক্রীত পণ্যের ব্যয়, আর দুইটাই বিলের গায়ে
     * বসানো (`total`, `cost_of_goods`)। খতিয়ান থেকে বের করতে গেলে
     * আয় ও ব্যয়ের খাত ধরে গুনতে হত, আর তাতে বিক্রয় ছাড়া অন্য আয়ও
     * ঢুকে পড়ত — যেমন বাতিল হওয়া বিলের উল্টো এন্ট্রি।
     */
    private static function marginThisMonth(): ?Widget
    {
        $row = DB::table('sal_invoices')
            ->where('company_id', CompanyContext::id())
            ->whereIn('status', ['confirmed', 'closed'])
            ->whereBetween('trx_date', [
                Carbon::today()->startOfMonth()->toDateString(),
                Carbon::today()->endOfMonth()->toDateString(),
            ])
            ->selectRaw('COALESCE(SUM(total), 0) as sold, COALESCE(SUM(cost_of_goods), 0) as cost')
            ->first();

        $sold = (string) ($row->sold ?? '0');
        $cost = (string) ($row->cost ?? '0');

        if (bccomp($sold, '0', 4) === 0) {
            return null;
        }

        $margin = bcsub($sold, $cost, 4);

        /*
         * শতাংশটা ক্রয়মূল্যের উপর, বিক্রয়ের উপর নয়।
         *
         * কোম্পানি "৪%" বলতে ক্রয়মূল্যের উপর ৪% যোগ বোঝায় (১৭২.৫৪ →
         * ১৭৯.৪৪)। বিক্রয়ের উপর গুনলে ওটাই ৩.৮৫% দেখাত, আর মাস শেষে
         * ডিপো ভাবত কোম্পানি কম দিয়েছে। একই অঙ্ক, দুই রকম পড়া — আর
         * তর্কটা ঠিক ওখানেই বাধে।
         */
        $percent = bccomp($cost, '0', 4) > 0
            ? Money::round(bcmul(bcdiv($margin, $cost, 6), '100', 6), 2)
            : null;

        return new Widget(
            group: 'month',
            label: __('supplier::widget.margin_this_month'),
            value: Money::format($margin),
            href: route('supplier.report.show', ['slug' => 'settlement']),
            permission: 'supplier.settlement.view',
            tone: 'money',
            hint: $percent === null ? null : __('supplier::widget.margin_hint', ['percent' => $percent]),
            sort: 40,
            icon: 'purchase',
            parts: [
                __('supplier::field.sold') => Money::format($sold),
                __('supplier::field.cost_of_sold') => Money::format($cost),
            ],
        );
    }
}
