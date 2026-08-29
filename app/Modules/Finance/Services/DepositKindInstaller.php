<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Finance\Models\DepositKind;

/**
 * বাংলাদেশে যে জমার ধরনগুলো সত্যিই বিক্রি হয় — একটা শুরু, শেষ কথা নয়।
 *
 * ── কোথা থেকে এই তালিকা, ২৯ আগস্ট ২০২৬ ───────────────────────────────
 * মালিক বললেন **দেখে নাও** — অনুমান নয়। জাতীয় সঞ্চয় অধিদপ্তরের পাতা
 * (nationalsavings.gov.bd) আর ব্যাংকগুলোর নিজেদের পাতা থেকে নেওয়া।
 *
 * ── কেন এগুলো কোডে বসানো নেই, সারি হিসেবে বসে ────────────────────────
 * প্রতিটা ব্যাংকের নিজের নামে নিজের স্কিম, আর প্রতি বছর নতুন আসে।
 * enum করলে প্রথম নতুন স্কিমেই কোড বদলাতে হত। এগুলো কেবল প্রথম দিনের
 * সারি — কোম্পানি সেটিংস থেকে নিজেরটা যোগ করে।
 *
 * ── হার এখানে নেই, ইচ্ছাকৃতভাবে ─────────────────────────────────────
 * সঞ্চয়পত্রের হার বাজেটে বদলায়, ব্যাংকেরটা মাসে মাসে। কোডে বসালে
 * সেটা প্রথম বাজেটেই মিথ্যা হয়ে যেত, আর কেউ মিলিয়ে দেখত না। হার বসে
 * প্রতিটা জমার নিজের সারিতে — কারণ ওটাই সেদিনের সত্যি।
 */
final class DepositKindInstaller
{
    /**
     * @var list<array{code: string, en: string, bn: string, shape: string, issuer: string, personal: bool}>
     */
    private const KINDS = [
        // ── ব্যাংক ────────────────────────────────────────────────────
        ['code' => 'FDR', 'en' => 'Fixed Deposit (FDR)', 'bn' => 'স্থায়ী আমানত (FDR)',
            'shape' => DepositKind::AT_MATURITY, 'issuer' => DepositKind::BANK, 'personal' => false],

        ['code' => 'DPS', 'en' => 'Monthly Savings (DPS)', 'bn' => 'মাসিক সঞ্চয়ী (DPS)',
            'shape' => DepositKind::INSTALMENT, 'issuer' => DepositKind::BANK, 'personal' => false],

        /* একবারে জমা, প্রতি মাসে মুনাফা তোলা — অবসরের টাকার চেনা পথ */
        ['code' => 'MIS', 'en' => 'Monthly Benefit Scheme', 'bn' => 'মাসিক মুনাফা প্রকল্প',
            'shape' => DepositKind::PERIODIC_PAYOUT, 'issuer' => DepositKind::BANK, 'personal' => false],

        ['code' => 'DBS', 'en' => 'Double Benefit Scheme', 'bn' => 'ডাবল বেনিফিট',
            'shape' => DepositKind::AT_MATURITY, 'issuer' => DepositKind::BANK, 'personal' => false],

        ['code' => 'TBS', 'en' => 'Triple Benefit Scheme', 'bn' => 'ট্রিপল বেনিফিট',
            'shape' => DepositKind::AT_MATURITY, 'issuer' => DepositKind::BANK, 'personal' => false],

        ['code' => 'MDS', 'en' => 'Millionaire Scheme', 'bn' => 'মিলিয়নিয়ার স্কিম',
            'shape' => DepositKind::INSTALMENT, 'issuer' => DepositKind::BANK, 'personal' => false],

        ['code' => 'EDU', 'en' => 'Education Deposit', 'bn' => 'শিক্ষা সঞ্চয়',
            'shape' => DepositKind::INSTALMENT, 'issuer' => DepositKind::BANK, 'personal' => false],

        ['code' => 'MRG', 'en' => 'Marriage Deposit', 'bn' => 'বিবাহ সঞ্চয়',
            'shape' => DepositKind::INSTALMENT, 'issuer' => DepositKind::BANK, 'personal' => false],

        /* ইসলামি — সুদ নয়, মুনাফা ভাগাভাগি; শব্দটা কাগজে আলাদা বসে */
        ['code' => 'MTDR', 'en' => 'Mudaraba Term Deposit', 'bn' => 'মুদারাবা মেয়াদি আমানত',
            'shape' => DepositKind::AT_MATURITY, 'issuer' => DepositKind::BANK, 'personal' => false],

        ['code' => 'MSS', 'en' => 'Mudaraba Savings Scheme', 'bn' => 'মুদারাবা সঞ্চয়ী স্কিম',
            'shape' => DepositKind::INSTALMENT, 'issuer' => DepositKind::BANK, 'personal' => false],

        ['code' => 'MMPS', 'en' => 'Mudaraba Monthly Profit', 'bn' => 'মুদারাবা মাসিক মুনাফা',
            'shape' => DepositKind::PERIODIC_PAYOUT, 'issuer' => DepositKind::BANK, 'personal' => false],

        // ── সঞ্চয়পত্র ─────────────────────────────────────────────────
        //
        // পাঁচটাই **ব্যক্তির জিনিস** — ফার্ম বা কোম্পানি কিনতে পারে না।
        // তাই `personal` সত্যি, আর পর্দা তখন "কার নামে" জিজ্ঞেস করে।

        ['code' => 'BSP5', 'en' => '5-Year Bangladesh Savings Certificate',
            'bn' => '৫ বছর মেয়াদি বাংলাদেশ সঞ্চয়পত্র',
            'shape' => DepositKind::AT_MATURITY, 'issuer' => DepositKind::NATIONAL_SAVINGS, 'personal' => true],

        ['code' => 'TMSP', 'en' => '3-Monthly Profit Savings Certificate',
            'bn' => '৩-মাস অন্তর মুনাফাভিত্তিক সঞ্চয়পত্র',
            'shape' => DepositKind::PERIODIC_PAYOUT, 'issuer' => DepositKind::NATIONAL_SAVINGS, 'personal' => true],

        /* নারী ১৮+, পুরুষ ৬৫+ — মুনাফা প্রতি মাসে */
        ['code' => 'PSP', 'en' => 'Family Savings Certificate', 'bn' => 'পরিবার সঞ্চয়পত্র',
            'shape' => DepositKind::PERIODIC_PAYOUT, 'issuer' => DepositKind::NATIONAL_SAVINGS, 'personal' => true],

        /* অবসরপ্রাপ্ত সরকারি — মুনাফা প্রতি ৩ মাসে */
        ['code' => 'PNSP', 'en' => 'Pensioner Savings Certificate', 'bn' => 'পেনশনার সঞ্চয়পত্র',
            'shape' => DepositKind::PERIODIC_PAYOUT, 'issuer' => DepositKind::NATIONAL_SAVINGS, 'personal' => true],

        // ── বন্ড ──────────────────────────────────────────────────────
        //
        // মেনুতে নিজের সারি, কারণ বন্ড সঞ্চয়পত্রও নয় ব্যাংক আমানতও নয়:
        // প্রবাসীর টাকা, ডলারে, আর নিয়মও আলাদা।

        ['code' => 'WEDB', 'en' => 'Wage Earner Development Bond', 'bn' => 'ওয়েজ আর্নার ডেভেলপমেন্ট বন্ড',
            'shape' => DepositKind::AT_MATURITY, 'issuer' => DepositKind::BOND, 'personal' => true],

        ['code' => 'USDPB', 'en' => 'US Dollar Premium Bond', 'bn' => 'ইউএস ডলার প্রিমিয়াম বন্ড',
            'shape' => DepositKind::PERIODIC_PAYOUT, 'issuer' => DepositKind::BOND, 'personal' => true],

        ['code' => 'USDIB', 'en' => 'US Dollar Investment Bond', 'bn' => 'ইউএস ডলার ইনভেস্টমেন্ট বন্ড',
            'shape' => DepositKind::AT_MATURITY, 'issuer' => DepositKind::BOND, 'personal' => true],

        /* প্রাইজ বন্ড — মুনাফা নেই, ড্র হয়। মূলধন ফেরতযোগ্য, তাই
           জমাই — কিন্তু হার নেই, আর মেয়াদও নেই। */
        ['code' => 'PRIZE', 'en' => 'Prize Bond', 'bn' => 'প্রাইজ বন্ড',
            'shape' => DepositKind::AT_MATURITY, 'issuer' => DepositKind::BOND, 'personal' => false],

        /*
         * ডাকঘর সঞ্চয় — ইস্যুয়ার সঞ্চয় অধিদপ্তরই।
         *
         * ── কেন `post_office` নামে চতুর্থ কোনো ইস্যুয়ার নেই ──────────
         * ডাকঘর কাগজটা **বিক্রি করে**, ছাপে না — স্কিমটা জাতীয় সঞ্চয়ের।
         * চতুর্থ একটা ইস্যুয়ার বসালে মেনুতে চতুর্থ একটা সারি লাগত,
         * যেখানে বছরে একটা কাগজও উঠত না — আর মালিক তাঁর ডাকঘরের
         * কাগজটা "সঞ্চয়পত্র"-এর তালিকায় খুঁজতেন, পেতেন না।
         *
         * কোথা থেকে কেনা সেটা `institution` ঘরেই লেখা থাকে — ওটাই
         * ওই ঘরের কাজ।
         */
        ['code' => 'POSB', 'en' => 'Post Office Savings — fixed', 'bn' => 'ডাকঘর সঞ্চয় — মেয়াদি',
            'shape' => DepositKind::AT_MATURITY, 'issuer' => DepositKind::NATIONAL_SAVINGS, 'personal' => true],
    ];

    /**
     * যেগুলো নেই সেগুলো বসায়; যেগুলো আছে সেগুলো ছোঁয় না।
     *
     * ── কেন ছোঁয় না ─────────────────────────────────────────────────
     * কোম্পানি একটা ধরনের নাম বদলে থাকতে পারে ("FDR" → "স্থায়ী আমানত
     * — অগ্রণী"), বা নিষ্ক্রিয় করে থাকতে পারে। আবার চালালে ওই বদলটা
     * মুছে যাওয়া মানে তাঁর কাজ নষ্ট করা।
     */
    public function install(): int
    {
        $companyId = CompanyContext::id();
        $added = 0;

        foreach (self::KINDS as $i => $kind) {
            $exists = DepositKind::query()->where('code', $kind['code'])->exists();

            if ($exists) {
                continue;
            }

            DepositKind::query()->create([
                'company_id' => $companyId,
                'code' => $kind['code'],
                'name_en' => $kind['en'],
                'name_bn' => $kind['bn'],
                'shape' => $kind['shape'],
                'issuer' => $kind['issuer'],
                'personal_only' => $kind['personal'],
                'is_active' => true,
                'sort' => $i,
            ]);

            $added++;
        }

        return $added;
    }
}
