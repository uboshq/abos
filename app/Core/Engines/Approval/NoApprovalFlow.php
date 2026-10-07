<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use Illuminate\Validation\ValidationException;

/**
 * ⛔ টাকার কাজে অনুমোদনের ছক নেই — তাই কাগজটা পোস্ট হলো না।
 *
 * ── ⭐ কেন, অডিট §১.৩ আর মালিকের সিদ্ধান্ত ৪, ২৭ সেপ্টেম্বর ২০২৬ ─────
 * আগে ছক না থাকলে [[ApprovalEngine::request()]] `null` ফেরাত — মানে
 * *"এগিয়ে যাও"*। ⚠️ ফলে যে কোম্পানি ছক বসায়নি, সেখানে প্রতিটা টাকার
 * কাগজ **কারো সই ছাড়াই** খাতায় বসত, আর কেউ টের পেত না।
 *
 * ⭐ মালিকের কথা: POS-এর নগদ বিক্রি ছাড়া **প্রতিটা** টাকার পোস্টিংয়ে
 * সই লাগবে। ⓘ তাই ছক না থাকা এখন একটা বাধা, আর বাধাটা বলে কী করতে
 * হবে।
 *
 * ── ⓘ কেন `ValidationException`-এর উপ-ধরন ───────────────────────────
 * প্রতিটা পোস্টের দরজা আগে থেকেই `ValidationException` ধরে ফর্মে বার্তা
 * দেখায় — ⚠️ নতুন একটা ধরন হলে প্রতিটা কন্ট্রোলারে নতুন `catch` লিখতে
 * হত, আর একটা বাদ পড়লে পর্দায় ৫০০। ⭐ উপ-ধরন হলে পুরনো `catch`
 * নিজে থেকেই ধরে, আর টেস্ট চাইলে নির্দিষ্ট ধরনটা আলাদা করে চিনতে পারে।
 *
 * ── ⚠️ বার্তার ভাষা ─────────────────────────────────────────────────
 * চাবি `core.approval.no_flow` (কোরের নিজের ভাষা-ফাইল — ⛔ কোর কোনো
 * মডিউলের নাম জানে না, §১৯.৭)। ⓘ চাবিটা বসার আগে পর্যন্ত নিচের
 * বাংলা লেখাটা পড়ে যায় — কাঁচা চাবি পর্দায় দেখানোর চেয়ে ভালো।
 */
final class NoApprovalFlow extends ValidationException
{
    /** ⓘ ভাষা-ফাইলের চাবি — প্রথম ধাপে লাইনটা বসেনি, নকশার সম্পাদনা-তালিকায় আছে। */
    public const KEY = 'core.approval.no_flow';

    /** ⚠️ ফর্মের কোন ঘরের নিচে বার্তা বসবে — কাগজটার গোটা, তাই একটা সাধারণ নাম। */
    public const FIELD = 'approval';

    private string $module = '';

    private string $action = '';

    /**
     * ⭐ এই মডিউলের এই কাজে ছক নেই।
     *
     * ⓘ বার্তায় মডিউল ও কাজের **অনুবাদ করা** নাম যায় ("হিসাব · খরচ"),
     * কাঁচা `accounts.expense` নয় — ইঞ্জিনের `documentLabel()`-এর একই নিয়ম।
     */
    public static function for(string $module, string $action): self
    {
        $exception = self::withMessages([
            self::FIELD => self::sentence(self::labelOf($module, $action)),
        ]);

        $exception->module = $module;
        $exception->action = $action;

        return $exception;
    }

    /** কোন মডিউল — টেস্ট আর লগের জন্য। */
    public function module(): string
    {
        return $this->module;
    }

    /** কোন কাজ — টেস্ট আর লগের জন্য। */
    public function action(): string
    {
        return $this->action;
    }

    private static function sentence(string $document): string
    {
        $line = __(self::KEY, ['document' => $document]);

        if (is_string($line) && $line !== self::KEY) {
            return $line;
        }

        /*
         * ⚠️ চাবিটা এখনো ভাষা-ফাইলে বসেনি।
         *
         * ⓘ অনুবাদক চাবি না পেলে চাবিটাই ফেরত দেয়, আর তখন পর্দায়
         * `core.approval.no_flow` লেখা উঠত — ⛔ যা পড়ে কেউ বুঝতেন না
         * কী করতে হবে। ⭐ তাই মালিকের ভাষায় একটা পূর্ণ বাক্য।
         */
        return "{$document} — এই কাজের অনুমোদনের ছক বসানো নেই, তাই টাকার কাগজটা পোস্ট হলো না। "
            .'অনুমোদন → ছক-এ এই কাজের একটা ছক বসান (No approval flow is set for this money action).';
    }

    /**
     * "মডিউল · কাজ" — অনুবাদ থাকলে সেটা, নাহলে কাঁচা নাম।
     *
     * ⓘ `core.module.*` আর `core.approval.action.*` — ইঞ্জিন বিজ্ঞপ্তিতে
     * ঠিক এই দুইটা চাবিই পড়ে, তাই দুই জায়গায় কাগজের নাম একই দেখায়।
     */
    private static function labelOf(string $module, string $action): string
    {
        $m = __('core.module.'.$module);
        $a = __('core.approval.action.'.$action);

        return trim(
            (is_string($m) && ! str_starts_with($m, 'core.') ? $m : $module)
            .' · '.
            (is_string($a) && ! str_starts_with($a, 'core.') ? $a : $action)
        );
    }
}
