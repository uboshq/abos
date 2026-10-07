<?php

declare(strict_types=1);

namespace App\Core\Licence;

use Illuminate\Support\Carbon;

/**
 * একটা লাইসেন্স — কে কিনেছেন, কতগুলো কোম্পানি, আর কতদিন।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ২২ সেপ্টেম্বর ২০২৬ ────────────────────────
 * *"সৎ তালা — অফলাইন চাবি"*, আর *"মেয়াদ শেষে সব বন্ধ, কেবল লগইন খোলা"*।
 *
 * ── ⓘ কেন অফলাইন ───────────────────────────────────────────────────
 * ABOS দুইভাবে বিক্রি হয় — আমাদের সার্ভারে, আর **ক্রেতার নিজের
 * সার্ভারে** ([[Destination]]-এর টীকা)। ⚠️ ক্রেতার ঘরে ইন্টারনেট
 * থাকবেই এমন নয়, আর বাংলাদেশে নেট যাওয়া স্বাভাবিক ঘটনা।
 *
 * ⛔ প্রতিদিনের অনলাইন যাচাই বসালে নেট গেলে **দোকান বন্ধ** — আর তাতে
 * শাস্তি পেতেন সেই ক্রেতা যিনি টাকা দিয়েছেন, আর যিনি চুরি করবেন তিনি
 * কোড বদলে নিতেন। ⓘ তাই তালাটা সৎ: নকল করা যায় না, কিন্তু অস্বীকারও
 * করা হয় না যে কোড হাতে থাকলে কেউ ওটা খুলতে পারেন।
 *
 * ── ⚠️ এটা একটা দাবি, প্রমাণ নয় ─────────────────────────────────────
 * এই ক্লাসটা কেবল **কাগজটা পড়ে**। সইটা সত্যি কি না সেটা বলে
 * [[LicenceReader]], আর সে ছাড়া এই বস্তুটা কোথাও তৈরি হয় না।
 */
final class Licence
{
    /**
     * @param  string  $buyer  ক্রেতার নাম, যেমন কাগজে লেখা থাকবে
     * @param  int  $companies  সর্বোচ্চ কয়টা কোম্পানি (0 = সীমা নেই)
     * @param  Carbon|null  $expiresOn  শেষ দিন; null মানে চিরকালীন
     */
    private function __construct(
        public readonly string $buyer,
        public readonly int $companies,
        public readonly ?Carbon $expiresOn,
        public readonly string $issuedTo,
    ) {}

    /**
     * ⓘ কেবল [[LicenceReader]] ডাকে — সই যাচাই করার **পরে**।
     *
     * ⚠️ `private` নয়, কারণ তাহলে পরীক্ষাতেও একটা লাইসেন্স বানানো যেত
     * না। ⛔ কিন্তু নামটা মনে করিয়ে দেয় যে এটা একটা কাঁচা দাবি: যে
     * এটা ডাকছে, তার দায়িত্ব আগে সই মেলানো।
     *
     * @param  array<string, mixed>  $claims
     */
    public static function fromVerifiedClaims(array $claims): self
    {
        return new self(
            buyer: (string) ($claims['buyer'] ?? ''),
            companies: (int) ($claims['companies'] ?? 0),

            /*
             * ⓘ দিনের **শেষ** পর্যন্ত বৈধ, শুরু নয়।
             *
             * ⛔ `parse()` দিলে ৩১ ডিসেম্বর লেখা লাইসেন্স ৩১ তারিখ
             * সকাল থেকেই মেয়াদোত্তীর্ণ দেখাত — আর ক্রেতা ঠিকই বলতেন
             * "কাগজে তো ৩১ পর্যন্ত লেখা"।
             */
            expiresOn: isset($claims['expires_on'])
                ? Carbon::parse((string) $claims['expires_on'])->endOfDay()
                : null,

            issuedTo: (string) ($claims['issued_to'] ?? ''),
        );
    }

    /**
     * মেয়াদ কি ফুরিয়েছে?
     *
     * ⓘ চিরকালীন লাইসেন্সে (`expires_on` নেই) কোনোদিন না।
     */
    public function hasExpired(): bool
    {
        return $this->expiresOn !== null && $this->expiresOn->isPast();
    }

    /**
     * আর কত দিন বাকি — ঋণাত্মক মানে কত দিন পেরিয়েছে।
     *
     * ⚠️ চিরকালীন হলে `null`, `0` নয়: শূন্য মানে "আজই শেষ", আর
     * সেটা সম্পূর্ণ ভিন্ন কথা।
     */
    public function daysLeft(): ?int
    {
        return $this->expiresOn === null
            ? null
            : (int) Carbon::now()->startOfDay()->diffInDays($this->expiresOn->copy()->startOfDay(), false);
    }

    /**
     * এতগুলো কোম্পানি কি এই কাগজে ধরে?
     *
     * ⓘ `0` মানে সীমা নেই — আর সেটাই ডিফল্ট, কারণ ঘরটা না লিখলে
     * ⛔ সীমা **শূন্য** ধরা হলে প্রতিটা পুরনো লাইসেন্স প্রথম দিনেই
     * ভেঙে পড়ত।
     */
    public function allows(int $companies): bool
    {
        return $this->companies === 0 || $companies <= $this->companies;
    }
}
