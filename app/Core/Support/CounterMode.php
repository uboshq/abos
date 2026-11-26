<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * ⭐ কাউন্টার মোড — সরাসরি ক্রয়/বিক্রয় নতুন ট্যাবে, মেনু-বার-ফুটার ছাড়া, কেবল বিলের পর্দা (মালিক, ২৬ নভেম্বর ২০২৬, নমুনা "ঠিক আছে")।
 *
 * ⓘ কেবল আঁকা বদলায় ([[layouts.app]]) — অনুমতি, কোম্পানি, শাখা, স্কোপ সবই আগের মতো। ঠিকানায় `?counter=1`, জমার পরে একই ট্যাবে
 *   নতুন খালি বিল ([[DirectPurchaseController::store()]])। মেনু থেকে নতুন ট্যাবে খোলে [[counter-mode.js]] (বড় পর্দায়)।
 */
final class CounterMode
{
    public const PARAM = 'counter';

    public static function wanted(): bool
    {
        return request()->boolean(self::PARAM);
    }
}
