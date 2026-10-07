<?php

declare(strict_types=1);

namespace App\Core\Engines\Backup;

/**
 * একটা গন্তব্যের স্বাস্থ্য — পৌঁছানো যায় কি না, আর কতটা জায়গা আছে।
 *
 * ── কেন একটা বস্তু, শুধু `true`/`false` নয় ───────────────────────────
 * "পৌঁছানো যায় না" উত্তরটা একা প্রায় অকেজো। মানুষ পরের প্রশ্নটা করেন
 * সাথে সাথেই — **কেন?** — আর সেই উত্তরটা না থাকলে তাঁকে সেটিংসের
 * প্রতিটা ঘর খুলে আন্দাজ করতে হয়।
 *
 * তিনটা আলাদা কারণ, তিনটা আলাদা কাজ:
 *
 *   পথ নেই            পেনড্রাইভ খোলা → লাগান
 *   লেখা যায় না       অনুমতি নেই → অনুমতি দিন
 *   জায়গা নেই         ডিস্ক ভরা → পুরনো মোছার নীতি দেখুন
 *
 * ── `freeBytes` কেন ─────────────────────────────────────────────────
 * ⚠️ ব্যাকআপ শুরু করে মাঝপথে ডিস্ক ভরে যাওয়া সবচেয়ে খারাপ ফল: একটা
 * **অর্ধেক ফাইল** পড়ে থাকে, যেটা দেখতে ব্যাকআপের মতোই আর ফেরে না।
 * আগে জেনে না নেওয়ার চেয়ে আগে বলে দেওয়া ভালো।
 */
final class Health
{
    private function __construct(
        public readonly bool $reachable,
        public readonly ?string $reason,
        public readonly ?int $freeBytes,
        public readonly ?int $totalBytes,
    ) {}

    public static function ok(?int $freeBytes = null, ?int $totalBytes = null): self
    {
        return new self(true, null, $freeBytes, $totalBytes);
    }

    /**
     * পৌঁছানো যায়নি — আর কারণটা **অনুবাদের চাবি**, কাঁচা বাক্য নয়।
     *
     * ⚠️ এই বার্তাগুলো পর্দায় দেখা যায়, আর ABOS দ্বিভাষিক। কাঁচা
     * ইংরেজি বসালে বাংলা পর্দায় হঠাৎ ইংরেজি ফুটে উঠত — আর এই রিপোতে
     * একটা গার্ডই সেটা ধরে।
     */
    public static function unreachable(string $reasonKey): self
    {
        return new self(false, $reasonKey, null, null);
    }

    /**
     * জায়গা কি এই আকারের একটা ফাইলের জন্য যথেষ্ট?
     *
     * ⓘ দ্বিগুণ চাওয়া হয় ইচ্ছাকৃতভাবে: gzip করার সময় আর restore
     * পরীক্ষার সময় সাময়িকভাবে দুইটা কপি একসাথে থাকে। ঠিক ভরা ডিস্কে
     * "কাজ করার কথা ছিল" বলে ব্যর্থ হওয়ার চেয়ে আগে না বলাই ভালো।
     */
    public function hasRoomFor(int $bytes): bool
    {
        return $this->freeBytes === null || $this->freeBytes > $bytes * 2;
    }
}
