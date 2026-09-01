<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Core\Services\SettingsService;

/**
 * মান দাম থেকে কতটা সরা যাবে, আর সরলে কী।
 *
 * ── আজকের নিয়মটা ভোঁতা ছিল ───────────────────────────────────────────
 * ABOS-এ **যেকোনো** ছাড়েই অনুমোদন লাগত — দশ টাকার ছাড়েও, দশ হাজারেরও
 * ([[SalesInvoiceService::assertDiscountApproved()]])।
 *
 * ফল দুইদিকেই খারাপ। কাউন্টারে পাঁচ টাকার ছাড় দিতে গিয়ে বিল আটকে থাকে,
 * তাই লোকে ছাড় দেওয়াই বন্ধ করে — বা আরও খারাপ, **দর কমিয়ে লেখে** যাতে
 * ছাড়ের ঘরটা ছুঁতে না হয়। তখন খাতায় ছাড়টা আর দেখাই যায় না, আর "আমরা
 * কত ছাড় দিলাম" প্রশ্নের উত্তর মিথ্যা হয়ে যায়।
 *
 * এই নিয়মটা তাই মাপে **সারির দর**, ছাড়ের ঘর নয় — দর কমিয়ে লেখার পথটাই
 * বন্ধ করে।
 *
 * ── কেন নিজের টেবিল নয় ───────────────────────────────────────────────
 * প্রথমে একটা `sal_pricing_rules` টেবিল বানানো হয়েছিল, DMS-এর গড়ন দেখে
 * (ওদের কন্ট্রোল প্যানেল টেবিল-নির্ভর)। কিন্তু ABOS-এ ব্যবস্থাটা আগে
 * থেকেই আছে: মডিউল নিজের সেটিং ঘোষণা করে, আর সেগুলো কন্ট্রোল প্যানেলে
 * নিজে থেকেই আসে।
 *
 * টেবিল রাখলে একই জিনিসের দুইটা জায়গা হত — একটা কন্ট্রোল প্যানেলে,
 * একটা নিজের পর্দায় — আর কোনটা মানা হবে তা বলা যেত না। টেবিলটা তাই
 * ফেলে দেওয়া হয়েছে, ডিপ্লয়ের আগেই।
 *
 * ── কেন সীমা না বসানোও একটা উত্তর ─────────────────────────────────────
 * যে কোম্পানি কোনোদিন সীমা বসায়নি, সে কাউকে থামাতে বলেনি। ডিফল্ট তাই
 * "সব চলবে" — অনুপস্থিতিকে সবচেয়ে কড়া নিয়ম ধরে নিলে আপগ্রেডের দিন
 * সকালে প্রতিটা কাউন্টার থেমে যেত।
 */
final class PricingRule
{
    /** কিছুই না — শুধু খাতায় লেখা থাকে। */
    public const ALLOW = 'allow';

    /** পর্দায় সতর্কতা, কিন্তু বিল আটকায় না। */
    public const WARN = 'warn';

    /** সারিটাই নেওয়া হয় না। */
    public const BLOCK = 'block';

    public const TOLERANCE = 'sales.price_tolerance_percent';

    public const POLICY = 'sales.price_policy';

    public const BELOW = 'sales.price_policy_below';

    public const ABOVE = 'sales.price_policy_above';

    private function __construct(
        public readonly string $tolerance,
        public readonly string $policy,
        public readonly bool $appliesBelow,
        public readonly bool $appliesAbove,
    ) {}

    /**
     * এই কোম্পানির নীতি — সেটিংস থেকে, একবার।
     *
     * সারি ধরে ধরে পড়লে পঞ্চাশ সারির বিলে পঞ্চাশবার একই প্রশ্ন যেত,
     * আর উত্তরটা প্রতিবার একই।
     */
    public static function current(?SettingsService $settings = null): self
    {
        $settings ??= app(SettingsService::class);

        return new self(
            tolerance: (string) $settings->get(self::TOLERANCE, 0),
            policy: (string) $settings->get(self::POLICY, self::ALLOW),
            appliesBelow: (bool) $settings->get(self::BELOW, true),
            appliesAbove: (bool) $settings->get(self::ABOVE, true),
        );
    }

    /**
     * এই দরটা মান দাম থেকে কতটা সরে আছে — শতাংশে, চিহ্নসহ।
     *
     * ঋণাত্মক মানে মান দামের নিচে। চিহ্নটা রাখা হয় কারণ নিচে আর উপরে
     * দুইটা আলাদা সুইচ, আর চিহ্ন ছাড়া কোনদিকে সরেছে তা বলা যেত না।
     */
    public function driftOf(string $rate, string $standard): ?string
    {
        if (bccomp($standard, '0', 4) <= 0) {
            /*
             * মান দাম না জানা থাকলে সরে যাওয়া মাপা যায় না।
             *
             * শূন্যকে মান ধরে নিলে প্রতিটা দরই "অসীম শতাংশ উপরে" হত,
             * আর আটকানোর নীতিতে কোনো বিলই কাটা যেত না -- অর্থাৎ যে
             * পণ্যের দাম বসানো হয়নি সেটা আর কোনোদিন বেচা যেত না।
             */
            return null;
        }

        return bcdiv(bcmul(bcsub($rate, $standard, 6), '100', 6), $standard, 4);
    }

    /**
     * এই দরে কী করা হবে — মানা, সতর্কতা, নাকি আটকানো।
     *
     * ── কেন সহনসীমার ভেতরে থাকলে নীতিটাই দেখা হয় না ─────────────────
     * সীমাটার পুরো কাজই হলো "এতটুকু সরা স্বাভাবিক" বলা। ভেতরে থেকেও
     * সতর্কতা এলে সতর্কতাটা রোজ আসত, আর রোজ আসা সতর্কতা কেউ পড়ে না।
     *
     * ── কেন নিচে আর উপরে আলাদা ──────────────────────────────────────
     * মান দামের **নিচে** বেচলে টাকা যায়; **উপরে** বেচলে গ্রাহক যায়।
     * কিছু ডিপো কেবল প্রথমটা পাহারা দেয় -- দ্বিতীয়টা তাদের কাছে
     * বিক্রয়কর্মীর কৃতিত্ব। এক সুইচ রাখলে যাঁরা কেবল নিচেরটা আটকাতে
     * চান তাঁদের উপরেরটাও সইতে হত, আর তখন তাঁরা পুরো নিয়মটাই বন্ধ
     * করে দিতেন।
     */
    public function verdictOn(string $rate, string $standard): string
    {
        if ($this->policy === self::ALLOW) {
            return self::ALLOW;
        }

        $drift = $this->driftOf($rate, $standard);

        if ($drift === null) {
            return self::ALLOW;
        }

        $below = bccomp($drift, '0', 4) < 0;

        if (($below && ! $this->appliesBelow) || (! $below && ! $this->appliesAbove)) {
            return self::ALLOW;
        }

        return bccomp(ltrim($drift, '-'), $this->tolerance, 4) > 0
            ? $this->policy
            : self::ALLOW;
    }
}
