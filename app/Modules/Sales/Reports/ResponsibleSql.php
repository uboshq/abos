<?php

declare(strict_types=1);

namespace App\Modules\Sales\Reports;

use App\Core\Services\SettingsService;
use App\Modules\Sales\Services\DealerOwnership;
use App\Modules\Sales\Services\SalesResponsibility;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ দায়িত্বপ্রাপ্ত বিক্রয়কর্মী SQL-এ — [[SalesResponsibility]]-এর একই ক্রম, এক সারিতে বসানো (মালিক, ২৩ নভেম্বর ২০২৬: কৃতিত্ব
 * দায়িত্বপ্রাপ্ত জনের, কাগজ যিনি লিখেছেন তাঁর নয়)।
 *
 * ── কেন SQL-এ ───────────────────────────────────────────────────────────────
 * বিলে আর ফেরতে দায়িত্বপ্রাপ্ত জন লেখা থাকে (`sales_person_id`) — সেখানে ওই ঘরই পড়তে হয়। কিন্তু **আসা টাকা**র কাগজে (রসিদ,
 * আদায়) সে ঘর নেই, আর রিপোর্টের সারি একটা SQL থেকেই আসে ([[ReportDefinition]]) — তাই নিয়মটা এখানে SQL হয়ে বসে, কাগজের তারিখে।
 *
 * ── ক্রম (হুবহু [[SalesResponsibility::decide()]]) ──────────────────────────────
 *   (ক) ডিলারের বাঁধন সেদিন ([[DealerOwnership::boundOn()]]) — জন এই কোম্পানির সদস্য হলে
 *   (খ) গ্রাহকের জায়গা থেকে উপরে, প্রথম যে ধাপে সেদিন কোনো সদস্যের এলাকার বাঁধন আছে ([[SalesPlace]]-এর সিঁড়ি `l1`…`l5`)
 *   (গ) কোম্পানির ডিফল্ট জন (`sales.default_sales_person`)
 *   (ঘ) কেউ না — `NULL`
 *
 * ⚠️ দুই জায়গায় সীমা, জেনেশুনে:
 *   · সিঁড়ি পাঁচ ধাপ ([[SalesPlace::DEPTH]]); মূল নিয়ম বারো ধাপ পর্যন্ত ওঠে। দেশের নিচে আজ সর্বোচ্চ ছয় স্তর, গ্রাহক বসে পয়েন্ট বা রুটে।
 *   · একই জায়গায় উপরওয়ালা আর নিচের জন দুজনই বাঁধা থাকলে উপরওয়ালা বাদ — এখানে দুই ধাপ উপর পর্যন্ত দেখা হয় (SR → ASM → DSM);
 *     মূল নিয়ম গোটা সারি দেখে। ⓘ দুই পথ একই উত্তর দেয় কি না [[TheMoneyInCreditFollowsTheResponsiblePersonTest]] মেলায়।
 */
final class ResponsibleSql
{
    /**
     * দায়িত্বপ্রাপ্ত জনের `users.id` — একটা বন্ধনীবদ্ধ SQL প্রকাশ। ⛔ ডাকার আগে কোয়েরিতে [[SalesPlace::join()]] থাকতে হয়।
     *
     * @param  string  $customer  গ্রাহকের কলাম, যেমন `cu.id`
     * @param  string  $date  কাগজের তারিখের কলাম, যেমন `ledger_entries.trx_date`
     */
    public static function person(int $company, string $customer, string $date): string
    {
        $member = fn (string $user): string => 'EXISTS (SELECT 1 FROM company_user AS rm JOIN users AS ru ON ru.id = rm.user_id'
            ." WHERE rm.company_id = {$company} AND rm.user_id = {$user} AND ru.deleted_at IS NULL)";

        // (ক) ডিলারের বাঁধন — মূল নিয়মের একই সাবকোয়েরি; সদস্য না হলে পরের ধাপে
        $dealer = '('.DealerOwnership::boundOn(DB::raw($customer), DB::raw($date), DB::raw((string) $company))->toSql().')';
        $steps = ["CASE WHEN {$member($dealer)} THEN {$dealer} END"];

        // (খ) জায়গার সিঁড়ি — প্রতি ধাপে সেদিনের বাঁধন; উপরওয়ালা পরে, তারপর আগে শুরু, তারপর ছোট সারি
        for ($i = 1; $i <= SalesPlace::DEPTH; $i++) {
            $live = fn (string $t): string => "{$t}.company_id = {$company} AND {$t}.location_id = l{$i}.id AND {$t}.starts_on <= {$date}"
                ." AND ({$t}.ends_on IS NULL OR {$t}.ends_on >= {$date})";
            $boss = 'EXISTS (SELECT 1 FROM area_bindings AS ab2'
                ." JOIN staff_supervisors AS s1 ON s1.company_id = {$company} AND s1.user_id = ab2.user_id"
                ." LEFT JOIN staff_supervisors AS s2 ON s2.company_id = {$company} AND s2.user_id = s1.supervisor_id"
                ." WHERE {$live('ab2')} AND ab2.user_id <> ab.user_id AND {$member('ab2.user_id')}"
                .' AND (s1.supervisor_id = ab.user_id OR s2.supervisor_id = ab.user_id))';

            $steps[] = "(SELECT ab.user_id FROM area_bindings AS ab WHERE {$live('ab')} AND {$member('ab.user_id')}"
                ." ORDER BY {$boss}, ab.starts_on, ab.id LIMIT 1)";
        }

        // (গ) কোম্পানির ডিফল্ট জন — সদস্য হলে তবেই
        $default = (int) app(SettingsService::class)->get(SalesResponsibility::SETTING, 0);

        if ($default > 0) {
            $steps[] = "CASE WHEN {$member((string) $default)} THEN {$default} END";
        }

        return 'COALESCE('.implode(', ', $steps).')';
    }
}
