<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Dashboard;

use App\Core\Contracts\DashboardWidgets;
use App\Core\Dashboard\Widget;
use App\Core\Services\DataScope;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * কোম্পানির সাথে হিসাবটা কোথায় দাঁড়িয়ে — হোম পর্দায়।
 *
 ── কেন এই সংখ্যাটা রোজ দরকার ───────────────────────────────────
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
        // ⭐ দেখার শাখার সারি (২৯ সেপ্টেম্বর ২০২৬)
        $owed = (string) (self::inView(DB::table('ledger_entries'), 'ledger_entries.branch_id')
            ->where('company_id', CompanyContext::id())
            ->where('party_type', Supplier::drillSourceType())
            // ⭐ কেবল মূল কোম্পানি (পণ্যের সরবরাহকারী) — প্রদেয় তালিকার একই নিয়মে, সেবাদাতা বাদ (মালিক, ২ অক্টোবর ২০২৬)
            ->whereIn('party_id', Supplier::onlySuppliersIds())
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
            icon: 'handover',
        );
    }

    /**
     * ⭐ দেখার শাখা — হেডারে যা বাছা (২৯ সেপ্টেম্বর ২০২৬)।
     *
     * একটা শাখা বাছা থাকলে কেবল সেটা, শাখাহীন সারি ছাড়া; "সব শাখা"-তে নাগাল,
     * শাখাহীনসহ ([[DataScope::viewBranchIds()]])। ⛔ কেবল দেখানোর সংখ্যায়।
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    private static function inView(Builder $query, string $column): Builder
    {
        return app(DataScope::class)->inView($query, $column);
    }
}
