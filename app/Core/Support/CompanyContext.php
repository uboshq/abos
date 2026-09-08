<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * এই রিকোয়েস্টটা কোন কোম্পানির — একটাই জায়গায় জানা।
 *
 * সেশনে না রেখে এখানে রাখার কারণ: কনসোল কমান্ড, কিউ-জব ও টেস্টেও একই প্রশ্নের
 * উত্তর লাগে, আর ওখানে সেশন নেই। মিডলওয়্যার ওয়েব রিকোয়েস্টে এটা বসায়,
 * টেস্ট নিজে বসায়, আর কনসোলে সাধারণত কিছুই বসে না — তখন null থাকে এবং
 * BelongsToCompany সেটা আলাদা করে সামলায়।
 *
 * ব্যবহারকারীর *পছন্দ* কোন কোম্পানি — সেটা users.current_company_id-তে,
 * ডাটাবেজে। এটা সেই পছন্দের এই-রিকোয়েস্টের প্রতিফলন মাত্র।
 */
final class CompanyContext
{
    private static ?int $companyId = null;

    private static ?int $branchId = null;

    private static ?int $financialYearId = null;

    public static function set(?int $companyId, ?int $branchId = null, ?int $financialYearId = null): void
    {
        self::$companyId = $companyId;
        self::$branchId = $branchId;
        self::$financialYearId = $financialYearId;

        /*
         * ── ⛔ পারমিশনের টিমটাও এখানেই, ৭ সেপ্টেম্বর ২০২৬ ────────────────
         *
         * spatie-র teams চালু হওয়ার পর **প্রতিটা পথে** টিম-প্রসঙ্গ বসাতে
         * হয়। ⚠️ একটা পথে ভুলে গেলে পারমিশন **নীরবে ভুল উত্তর দেয়** — হয়
         * সবাই তালাবন্ধ, নয় সবাই খোলা — আর কোনো ত্রুটিবার্তা আসে না।
         *
         * ── যেসব পথ সহজেই বাদ পড়ে ───────────────────────────────────────
         *     HTTP মিডলওয়্যার      ← সবাই এটা মনে রাখে
         *     কনসোল কমান্ড         ⚠️ books-check · sync-permissions · ব্যাকআপ
         *     কিউ/জব               ⚠️ রিকোয়েস্ট নেই
         *     টেস্টের setUp         ⚠️ এখানে ভুল হলে সুইট মিথ্যা সবুজ দেখায়
         *     কোম্পানি সুইচার       ⚠️ সুইচের **পরে**, আগে নয়
         *
         * ⭐ তাই তালিকাটা মনে রাখার বদলে **ভুলে যাওয়ার পথটাই বন্ধ**: যে
         * কোনো কোড কোম্পানি বদলাতে হলে এই একটা পদ্ধতিতেই আসে
         * (`forCompany()`-ও এখান দিয়েই যায়), তাই টিমটা এখানে বসালে
         * কোনো পথ বাদ পড়তে পারে না।
         *
         * ⓘ এটাই `core/common/`-এর গার্ডগুলোর যুক্তি: **নিয়ম কোডে, মনে
         * রাখার উপর নয়**।
         *
         * ⚠️ `function_exists` — spatie-র হেল্পারটা কেবল প্যাকেজ থাকলে
         * থাকে, আর এই ক্লাসটা এতই নিচে যে প্যাকেজ ছাড়াও লোড হতে পারে
         * (মাইগ্রেশন, কনসোল বুট)। ⛔ শর্ত ছাড়া লিখলে সেখানে fatal হত।
         */
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($companyId);

            /*
             * ── ⛔ ক্যাশটাও ছাড়তে হয়, ৭ সেপ্টেম্বর ২০২৬ ──────────────────
             *
             * spatie পারমিশনের তালিকা ক্যাশ করে, আর ওই ক্যাশ **টিম চেনে
             * না**। ⚠️ কোম্পানি বদলানোর পরেও সে আগের কোম্পানির উত্তরই
             * ফেরত দিত।
             *
             * ⓘ ধরা পড়েছে [[ScheduledReportRunner]]-এ: ক্রন পরপর দুই
             * কোম্পানির রিপোর্ট বানায়, আর দ্বিতীয়টা প্রথমটার অনুমতি নিয়ে
             * চলত। ⛔ ফল — কলাম বাদ পড়া বা বাড়তি কলাম ফাইলে চলে যাওয়া,
             * **আর কোথাও কিছু ভাঙত না**।
             *
             * ⚠️ এটাই আমার নিজের পরিকল্পনায় লেখা বিপদটা, হুবহু: *"কিউ/জব —
             * রিকোয়েস্ট নেই, তাই প্রসঙ্গ খালি"*। প্রসঙ্গটা বসানো হয়েছিল,
             * ক্যাশটা ছাড়া হয়নি।
             *
             * ⓘ খরচ নগণ্য: প্রতি অনুরোধে একবার-দুইবার, আর কোম্পানি
             * বদলানো এমনিতেই বিরল।
             */
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public static function id(): ?int
    {
        return self::$companyId;
    }

    public static function branchId(): ?int
    {
        return self::$branchId;
    }

    public static function financialYearId(): ?int
    {
        return self::$financialYearId;
    }

    public static function has(): bool
    {
        return self::$companyId !== null;
    }

    public static function clear(): void
    {
        self::$companyId = null;
        self::$branchId = null;
        self::$financialYearId = null;

        /*
         * ⚠️ পারমিশনের টিমটাও ছাড়তে হয়, নাহলে প্রসঙ্গ মুছে ফেলার পরেও
         * spatie আগের কোম্পানির রোলগুলো দেখাত।
         *
         * ⛔ [[ScheduledReportRunner]] প্রতিটা সময়সূচির পর `clear()` করে
         * তারপর পরেরটার প্রসঙ্গ বসায়। ⓘ টিমটা না ছাড়লে দুইটার মাঝখানে
         * একটা মুহূর্ত থাকত যেখানে কোম্পানি নেই অথচ রোল আছে — আর সেটাই
         * ঠিক সেই ফাঁক যা বন্ধ করার জন্য পুরো কাজটা।
         */
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId(null);
        }
    }

    /**
     * সাময়িকভাবে অন্য কোম্পানির প্রসঙ্গে কাজ — শেষে আগেরটা ফিরে আসে।
     *
     * finally ছাড়া লিখলে ভেতরে একটা এক্সসেপশন হলে প্রসঙ্গ ভুল কোম্পানিতে
     * আটকে থাকত, আর পরের কোয়েরিগুলো নীরবে ভুল ডাটা দিত — ঠিক যে জিনিসটা
     * এই পুরো ব্যবস্থাটা ঠেকানোর জন্য।
     */
    public static function forCompany(int $companyId, callable $callback): mixed
    {
        $previous = [self::$companyId, self::$branchId, self::$financialYearId];

        self::set($companyId);

        try {
            return $callback();
        } finally {
            self::set(...$previous);
        }
    }
}
