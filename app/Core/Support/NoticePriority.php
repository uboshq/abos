<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * নোটিশটা কতটা জরুরি — আর সেটা কেবল রং নয়, আচরণ।
 *
 * ── ⭐ মালিকের স্পেক, ২৩ সেপ্টেম্বর ২০২৬, ধারা ৮ ও ১৩ ────────────────
 * ছয়টা স্তর, আর প্রতিটার নিজের আচরণ: কোথায় দেখাবে, মেনে নেওয়া লাগবে
 * কি না, আর একসাথে অনেকগুলো থাকলে কে আগে।
 *
 * ── ⚠️ কেন অগ্রাধিকার ছাড়া বারটা অকেজো ──────────────────────────────
 * ⓘ একটা অফিসে যেকোনো দিন পাঁচ-ছয়টা নোটিশ সক্রিয় থাকে — ছুটির খবর,
 * নতুন নিয়ম, আজ মাল আসবে না। ⛔ সবগুলো একসাথে বারে দিলে জরুরি কথাটা
 * ভিড়ে হারায়, আর তখন বারটা মানুষ পড়াই বন্ধ করে দেয়।
 *
 * ⚠️ আর ঠিক সেদিনই আগুন লাগার নোটিশটা ওখানে থাকে।
 */
enum NoticePriority: string
{
    case LOW = 'low';
    case NORMAL = 'normal';
    case IMPORTANT = 'important';
    case HIGH = 'high';
    case CRITICAL = 'critical';
    case EMERGENCY = 'emergency';

    /**
     * কে আগে — বড় সংখ্যা মানে আগে।
     *
     * ⚠️ সংখ্যাটা `enum`-এর ক্রম থেকে **নেওয়া হয় না**, হাতে বসানো। ⓘ
     * ক্রম বদলানো সহজ আর নিরীহ দেখায়; ⛔ কিন্তু ঐ বদলে জরুরি নোটিশ
     * নিচে নেমে যেত, আর কোনো পরীক্ষা ছাড়া কেউ টের পেত না।
     */
    public function rank(): int
    {
        return match ($this) {
            self::EMERGENCY => 60,
            self::CRITICAL => 50,
            self::HIGH => 40,
            self::IMPORTANT => 30,
            self::NORMAL => 20,
            self::LOW => 10,
        };
    }

    /**
     * ⭐ নিচের বারে এটা যাবে কি না।
     *
     * ── ⓘ কেন `IMPORTANT` থেকে ───────────────────────────────────────
     * স্পেকের ধারা ৮ বলছে `LOW`/`NORMAL` সাধারণ নোটিফিকেশন, আর
     * `IMPORTANT` থেকে ড্যাশবোর্ড ও নোটিফিকেশন সেন্টার। ⚠️ ছুটির খবর
     * বারে পাঠালে বারটা রোজই ভরা থাকত, আর ভরা বার মানে না-পড়া বার।
     */
    public function goesToTheBar(): bool
    {
        return $this->rank() >= self::IMPORTANT->rank();
    }

    /**
     * ⛔ এটা সরিয়ে দেওয়া যায় কি না।
     *
     * ⚠️ `CRITICAL` আর তার উপরে সরানো যায় না — স্পেকের ধারা ১২। ⓘ
     * সরানো গেলে মানুষ সবার আগে ঐটাই সরাতেন, কারণ ওটাই সবচেয়ে বড়
     * করে দেখা যায়।
     *
     * ⓘ নিয়ন্ত্রণ প্যানেলের সুইচ এর **উপরে** বসে না, নিচে: প্রশাসক
     * চাইলে আরও কড়া করতে পারেন, ঢিলা নয়।
     */
    public function canBeDismissed(): bool
    {
        return $this->rank() < self::CRITICAL->rank();
    }

    /**
     * ⭐ মেনে নেওয়ার সই লাগে কি না — ডিফল্টে।
     *
     * ⓘ নোটিশে নিজের সুইচ আছে; এটা কেবল নতুন নোটিশের শুরুর অবস্থা।
     * ⚠️ `CRITICAL`-এ ডিফল্টে হ্যাঁ, কারণ ঐ নোটিশের পুরো মানে হলো
     * *"তুমি এটা দেখেছ তার প্রমাণ চাই"*।
     */
    public function needsAcknowledgementByDefault(): bool
    {
        return $this->rank() >= self::CRITICAL->rank();
    }

    public function label(): string
    {
        return __('core.notice.priority.'.$this->value);
    }

    /**
     * ব্যাজের রং — চোখের জন্য, সিদ্ধান্তের জন্য নয়।
     *
     * ⚠️ রঙের নামগুলো টোকেন, হেক্স নয় ([[tokens.css]])। ⓘ থিম বদলালে
     * হেক্স মিথ্যা হয়ে যেত, আর জরুরি নোটিশ সবুজ দেখাত।
     */
    public function tone(): string
    {
        return match ($this) {
            self::EMERGENCY, self::CRITICAL => 'danger',
            self::HIGH, self::IMPORTANT => 'warning',
            self::NORMAL, self::LOW => 'muted',
        };
    }
}
