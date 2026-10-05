<?php

declare(strict_types=1);

namespace App\Core\Dashboard;

use App\Models\User;

/**
 * হোমের সাজ — প্রত্যেক ব্যবহারকারীর নিজের (মালিক, ৪ অক্টোবর ২০২৬: "লেআউট সাজান" — লুকানো আর ক্রম বদলানো)।
 *
 * ⓘ ক্রম চারটা ভাগের: সময়ের কার্ড, পুরো ব্যবসা, ব্যবসার চিত্র, আর "কাজ" (যা করা বাকি + এইমাত্র হলো — একই সারিতে
 * পাশাপাশি বসে, তাই একসাথে সরে)। লুকানো যায় পাঁচটা আলাদা অংশ — কাজের সারির দুইটা আলাদা করেও।
 * ⓘ ঘরটা `users.home_layout` (json); না থাকলে আগের মতোই সব দেখা, আগের ক্রমে।
 * ⛔ মাথার সারি (কমান্ড সেন্টার, টাকার অবস্থা) সরে না — ওটা পাতার পরিচয়।
 */
final class HomeLayout
{
    /** সরানো যায় এমন ভাগ, আগের ক্রমে */
    // ⭐ নতুন হোম (পরিকল্পনা ২, ৫ অক্টোবর ২০২৬): চার্ট আগে, তারপর মূল সূচক, তারপর কাজ — পুরনো নাম ফেলে দেওয়া হয় ([[from()]])
    public const UNITS = ['pictures', 'kpis', 'work'];

    /** লুকানো যায় এমন অংশ */
    public const PARTS = ['pictures', 'kpis', 'exceptions', 'happenings'];

    /**
     * @param  list<string>  $order
     * @param  list<string>  $hidden
     */
    private function __construct(public readonly array $order, public readonly array $hidden) {}

    public static function for(?User $user): self
    {
        $saved = is_array($user?->home_layout) ? $user->home_layout : [];

        return self::from((array) ($saved['order'] ?? []), (array) ($saved['hidden'] ?? []));
    }

    /**
     * অচেনা নাম ফেলে দেওয়া, বাদ পড়া ভাগ শেষে জুড়ে দেওয়া — পুরনো বা ভাঙা সাজেও পাতা পুরো থাকে।
     *
     * @param  array<mixed>  $order
     * @param  array<mixed>  $hidden
     */
    public static function from(array $order, array $hidden): self
    {
        $order = array_values(array_unique(array_filter(array_map('strval', $order), fn (string $u) => in_array($u, self::UNITS, true))));

        foreach (self::UNITS as $unit) {
            if (! in_array($unit, $order, true)) {
                $order[] = $unit;
            }
        }

        $hidden = array_values(array_unique(array_filter(array_map('strval', $hidden), fn (string $p) => in_array($p, self::PARTS, true))));

        return new self($order, $hidden);
    }

    /** CSS `order` — ১ থেকে */
    public function position(string $unit): int
    {
        $at = array_search($unit, $this->order, true);

        return $at === false ? count(self::UNITS) : $at + 1;
    }

    public function shows(string $part): bool
    {
        return ! in_array($part, $this->hidden, true);
    }

    /** @return array{order: list<string>, hidden: list<string>} */
    public function toArray(): array
    {
        return ['order' => $this->order, 'hidden' => $this->hidden];
    }
}
