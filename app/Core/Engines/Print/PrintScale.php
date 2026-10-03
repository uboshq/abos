<?php

declare(strict_types=1);

namespace App\Core\Engines\Print;

/**
 * ছাপার মাপ % — মালিকের বাছাই (খ), ১ অক্টোবর ২০২৬।
 *
 * ── ⭐ কী ─────────────────────────────────────────────────────────────
 * ২০–২৫ সারির বিল A4-এর দুই পাতায় যেত, আর ব্রাউজারের "Scale 50%" দুইটা PDF পাতা এক করতে পারে না। ⭐ তাই মাপ
 * PDF আঁকার সময়েই: `?scale=50..200` — কাগজের মাপ নয়, লেখার মাপ বদলায় ([[PrintEngine::toPdf()]])। না দিলে
 * স্বয়ংক্রিয়: ১০০%-এ এক পাতার বেশি হলে ৯৫ থেকে ৬০ পর্যন্ত ৫ ধাপে এক পাতায় আঁটে এমন সবচেয়ে বড় মাপ; ৬০-তেও না
 * আঁটলে ১০০%-এ কয়েক পাতা।
 *
 * ── ⛔→⭐ স্পষ্ট মাপেও এক পাতা — মালিক, ৩ অক্টোবর ২০২৬ ─────────────────────────────────
 * *"etar print scale custom korle zate dui pristha ek pataay print hoy seta bolecilam but hoyni"*। ⓘ আগে স্পষ্ট মাপ
 * সবসময় জিতত, কাগজ দুই পাতায় গেলেও। ⭐ এখন ১০০ বা তার কম মাপে কাগজ উপচালে ৫ ধাপে [[MIN]] পর্যন্ত আরও ছোট হয়, যতক্ষণ
 * না এক পাতায় আঁটে — বসানো মাপ [[settle()]]-এ, `auto` = সত্য (চাওয়া মাপ থেকে নামতে হয়েছে)। [[MIN]]-এও না আঁটলে
 * চাওয়া মাপেই কয়েক পাতা। ⓘ ১০০-এর বেশি (বড় করা) আগের মতোই কয়েক পাতায়।
 *
 * ── ⓘ অনুরোধ-প্রতি একটা ─────────────────────────────────────────────
 * কোন মাপ বসল তা ইঞ্জিন এখানে রাখে; উত্তরের `X-Print-Scale` আর ছাপার ইতিহাস ([[PaperTrail::record()]]) এখান
 * থেকে পড়ে — তাই কোনো ছাপার কন্ট্রোলার ছুঁতে হয়নি। ⛔ কাগজে কিছু ছাপা হয় না (মালিক: কাগজ পরিষ্কার)।
 */
final class PrintScale
{
    public const MIN = 50;

    public const MAX = 200;

    public const NORMAL = 100;

    public const AUTO_FROM = 95;

    public const AUTO_FLOOR = 60;

    public const STEP = 5;

    private ?int $used = null;

    private bool $auto = false;

    /**
     * অনুরোধের মাপ — কেবল পূর্ণসংখ্যা, ৫০–২০০-এ আটকানো; ফাঁকা বা অচেনা মান মানে "স্বয়ংক্রিয়" (`null`)।
     *
     * ⚠️ `abc`, `1e2`, `85.5` — সব উপেক্ষা: ভাঙা মান থেকে আন্দাজে একটা মাপ বানালে কেউ বুঝতেন না কাগজ কেন ছোট।
     */
    public static function requested(mixed $raw): ?int
    {
        if (! is_scalar($raw) || preg_match('/^\s*\d{1,4}\s*$/', (string) $raw) !== 1) {
            return null;
        }

        return max(self::MIN, min(self::MAX, (int) $raw));
    }

    /** এই অনুরোধের `?scale=` — না থাকলে `null`। */
    public function fromRequest(): ?int
    {
        return self::requested(request()->query('scale'));
    }

    /** @return list<int> স্বয়ংক্রিয়ের চেষ্টার ক্রম — ৯৫, ৯০ … ৬০ */
    public static function autoSteps(): array
    {
        return range(self::AUTO_FROM, self::AUTO_FLOOR, -self::STEP);
    }

    /** ইঞ্জিন যা বসাল — `$auto`: মাপটা ইঞ্জিনের বাছা (চাওয়া হয়নি, বা চাওয়া মাপে কাগজ উপচেছিল)। */
    public function settle(int $scale, bool $auto): void
    {
        $this->used = $scale;
        $this->auto = $auto;
    }

    /** এই অনুরোধে বসানো মাপ — কোনো কাগজ আঁকা না হলে (বা থার্মালে) `null`। */
    public function used(): ?int
    {
        return $this->used;
    }

    public function wasAuto(): bool
    {
        return $this->auto;
    }
}
