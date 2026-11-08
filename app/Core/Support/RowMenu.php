<?php

namespace App\Core\Support;

use App\Core\Services\OpenPeriod;
use Carbon\Carbon;

/**
 * তালিকার "অ্যাকশন ▾" মেনুর ভাগ করা জিনিস — মালিক, ৮ নভেম্বর ২০২৬: "প্রতিটি লিস্টে শেষের দিকে একটা অ্যাকশন বাটন
 * থাকবে … ড্রপ ডাউন"; নমুনা "লিস্টের অ্যাকশন বোতাম" ([[x-ui.row-actions]])।
 *
 * ⓘ প্রতিটা কাগজের নিজের partial মেনু সাজায় (কোন কাজ, কোন চাবি); এখানে কেবল যা সব কাগজে একই — মাথা, তিন ভাগের
 * শিরোনাম, আর "মাসটা বন্ধ কি না"।
 */
final class RowMenu
{
    private const PERIOD = 'abos.row-menu.period';

    /** মেনুর মাথা — কাগজের নম্বর আর অবস্থা */
    public static function head(?string $no, string $status): array
    {
        return ['head' => (string) $no, 'state' => __('core.status.'.$status)];
    }

    /** ভাগের শিরোনাম: look | paper | work */
    public static function group(string $which): array
    {
        return ['group' => __('core.row_menu.group_'.$which)];
    }

    /**
     * কাগজের তারিখের মাসটা খোলা কি না।
     *
     * ⚠️ সারিপ্রতি নতুন [[OpenPeriod]] হলে পঁচিশ সারির পাতায় পঁচিশবার একই প্রশ্ন — তাই এক অনুরোধে একটাই, আর সে নিজেই
     * মাসের উত্তর মনে রাখে। ⓘ এটা কেবল মেনুর ধূসর-না-সাদা; কাজটা করার মুখে সার্ভার আবার নিজে যাচাই করে।
     */
    public static function monthOpen(Carbon|string|null $date): bool
    {
        if ($date === null) {
            return true;
        }

        $app = app();

        if (! $app->bound(self::PERIOD)) {
            $app->instance(self::PERIOD, $app->make(OpenPeriod::class));
        }

        return $app->make(self::PERIOD)->isOpen($date);
    }
}
