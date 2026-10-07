<?php

declare(strict_types=1);

namespace App\Core\Engines\Report;

/**
 * রিপোর্ট সেন্টারের মানচিত্রে মালিকের তালিকার যে রিপোর্টগুলো এখনো তৈরি হয়নি (রিপোর্ট সেন্টার ধাপ ১, ২ অক্টোবর ২০২৬)।
 *
 * ── ⭐ কোথা থেকে ─────────────────────────────────────────────────────
 * `docs/Checklist — রিপোর্ট সেন্টার.md`, ধাপ ২–৬ — মালিকের দেওয়া তালিকা, ১ অক্টোবর ২০২৬: *"plan onuzayi sob live
 * e rakho"*। ⓘ যা তৈরি তা এখানে লেখা নেই — সেগুলো মডিউলের মেনু থেকেই রিপোর্ট সেন্টারে ওঠে।
 *
 * ── ⭐ ঠিকানাটাই চুক্তি ─────────────────────────────────────────────────
 * প্রতিটা লাইনের ঠিকানা (`sales.report.show:analysis`) সেই নাম, যে নামে রিপোর্টটা বানানো হবে। বানিয়ে মডিউলের মেনুতে
 * সারিটা বসালেই [[MapEngine::exists()]] সেটা দেখে, আর লাইনটা "বাকি" থেকে নিজে লিংক হয়ে যায় — এই ফাইল ছুঁতে হয় না।
 * ⓘ অন্য নামে বানালে লাইনটা "বাকি"-ই থাকে, মিথ্যা লিংক হয় না; তখন এখানে ঠিকানাটা বদলান।
 *
 * ⛔ "বাকি" লাইন কেবল মালিক/অ্যাডমিন দেখেন ([[MapEngine::seesPending()]]) — বাকিদের কাছে "শীঘ্রই আসছে" কিছুই নয়।
 *
 * ⓘ কোর কোনো মডিউলের ক্লাস চেনে না (§১৯.৭) — এখানে কেবল ঠিকানার লেখা আর মডিউলের কোড, যেমন মেনু নিজেই রাখে।
 */
final class ReportCenterPlan
{
    /** শেষ কবে চেকলিস্টের সাথে হাতে মিলিয়ে দেখা হয়েছে — পুরনো তারিখ নিজেই বলে তালিকাটা কতটা বিশ্বাস করা যায় */
    public const RECONCILED_ON = '2026-10-02';

    /**
     * @return list<array{key: string, module: ?string, route: string, step: int}> `module` নাল মানে সব মডিউল জুড়ে
     */
    public static function pending(): array
    {
        return [
            // ── ধাপ ২ — মালিকের পাতা
            // ⓘ দুইটাই "শাখা পাশাপাশি"-তে — বিক্রি, লাভ, টাকা, পাওনা, দেনা, মজুদ শাখা ধরে ([[BranchesSideBySideReport]], d182ca8b)
            ['key' => 'executive', 'module' => null, 'route' => 'accounts.report.show:branches', 'step' => 2],
            ['key' => 'branch_profit_loss', 'module' => 'accounts', 'route' => 'accounts.report.show:branches', 'step' => 2],

            // ── ধাপ ৩ — বিশ্লেষণ
            ['key' => 'sales_analysis', 'module' => 'sales', 'route' => 'sales.report.show:analysis', 'step' => 3],

            // ── ধাপ ৪ — নিয়ন্ত্রণ
            // ⓘ আদায়ের সূচি ([[CollectionDueReport]], 8a2ca208) আর পরিশোধের সূচি ([[PaymentDueReport]], a90983a1)
            ['key' => 'receivable_calendar', 'module' => 'sales', 'route' => 'sales.report.show:collection-due', 'step' => 4],
            ['key' => 'payable_calendar', 'module' => 'purchase', 'route' => 'purchase.report.show:payment-due', 'step' => 4],
            ['key' => 'cash_position', 'module' => 'accounts', 'route' => 'accounts.report.show:cash-position', 'step' => 4],
            ['key' => 'expense_analysis', 'module' => 'accounts', 'route' => 'accounts.report.show:expense-analysis', 'step' => 4],

            // ── ধাপ ৫ — মানুষ ও নিরাপত্তা
            ['key' => 'attendance', 'module' => 'hr', 'route' => 'hr.report.show:attendance', 'step' => 5],
            ['key' => 'payroll', 'module' => 'hr', 'route' => 'hr.report.show:payroll', 'step' => 5],
            ['key' => 'security', 'module' => 'governance', 'route' => 'governance.report.show:security', 'step' => 5],
            ['key' => 'backup_runs', 'module' => 'backup', 'route' => 'backup.report.show:runs', 'step' => 5],
            ['key' => 'customer_activity', 'module' => 'customer', 'route' => 'customer.report.show:activity', 'step' => 5],
            ['key' => 'supplier_activity', 'module' => 'supplier', 'route' => 'supplier.report.show:activity', 'step' => 5],

            // ── ধাপ ৬ — খাতা ও নিরীক্ষা
            ['key' => 'paper_audit', 'module' => 'governance', 'route' => 'governance.report.show:paper-audit', 'step' => 6],
            // ⓘ খাতা মেলানো — গ্রাহক ও সরবরাহকারী মডিউলে বানানো হচ্ছে (`ledger-check`, ২ অক্টোবর ২০২৬); মেনুতে উঠলেই লাইনটা সরে
            ['key' => 'party_vs_ledger', 'module' => 'customer', 'route' => 'customer.report.show:ledger-check', 'step' => 6],
        ];
    }
}
