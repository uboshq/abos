<?php

declare(strict_types=1);

namespace App\Core\Licence;

/**
 * কাগজটা দেখে কী বোঝা গেল — একটাই উত্তর, আর সেটা স্পষ্ট।
 *
 * ── ⓘ কেন একটা বস্তু, একটা `bool` নয় ────────────────────────────────
 * *"লাইসেন্স ঠিক আছে কি না"* প্রশ্নের উত্তর সত্যি-মিথ্যা নয়: কাগজ নেই,
 * কাগজ পড়া যায় না, সই মেলেনি, মেয়াদ ফুরিয়েছে, বা সব ঠিক — পাঁচটা
 * আলাদা অবস্থা, আর প্রতিটায় মানুষকে **আলাদা কথা** বলতে হয়।
 *
 * ⛔ `bool` দিলে পর্দায় লিখতে হত *"লাইসেন্স অবৈধ"*, আর যিনি কেবল
 * নবায়ন করতে ভুলে গেছেন তিনি ভাবতেন তাঁকে চোর বলা হচ্ছে।
 */
final class LicenceVerdict
{
    private function __construct(
        public readonly string $state,
        public readonly ?Licence $licence,
    ) {}

    /** কাগজটাই নেই — নতুন ইনস্টল, বা কেউ ফাইলটা বসাননি। */
    public static function missing(): self
    {
        return new self('missing', null);
    }

    /** কাগজ আছে, কিন্তু পড়া গেল না — ভাঙা JSON, বা ঘর অনুপস্থিত। */
    public static function unreadable(): self
    {
        return new self('unreadable', null);
    }

    /**
     * ⛔ সই মেলেনি — কাগজটা আমাদের নয়, বা বদলানো হয়েছে।
     *
     * ⚠️ এটাই একমাত্র অবস্থা যেটা **ইচ্ছাকৃত** হতে পারে, আর তবু
     * বার্তাটা অভিযোগ নয়: হতে পারে কেউ মেয়াদের তারিখটা হাতে বদলাতে
     * গিয়ে ফাইলটা নষ্ট করেছেন।
     */
    public static function forged(): self
    {
        return new self('forged', null);
    }

    public static function valid(Licence $licence): self
    {
        return new self($licence->hasExpired() ? 'expired' : 'valid', $licence);
    }

    public function isValid(): bool
    {
        return $this->state === 'valid';
    }

    /**
     * ⓘ পর্দায় কোন কথাটা যাবে — চাবিটা অবস্থার নামেই।
     *
     * ⚠️ বার্তাগুলো কোডে নয়, ভাষার ফাইলে: ক্রেতা বাংলায় পড়েন, আর
     * একটা তালার বার্তা ইংরেজিতে এলে সেটা দ্বিগুণ ভীতিকর।
     */
    public function message(): string
    {
        return __('core.licence.'.$this->state);
    }
}
