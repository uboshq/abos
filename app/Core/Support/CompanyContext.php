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
