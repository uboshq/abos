<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Services\Backup\BackupFreshness;
use Illuminate\Console\Command;

/**
 * শেষ সফল ব্যাকআপ যথেষ্ট টাটকা কি না — আর না হলে চিৎকার।
 *
 * ── ⛔ কেন এই কমান্ডটা লাগল, ১৫ সেপ্টেম্বর ২০২৬ ──────────────────────
 * লাইভে ব্যাকআপ ছয় দিন ধরে ব্যর্থ হয়েছে, আর কেউ জানত না। ⓘ `proc_open`
 * বন্ধ থাকার ভুলটা এমন এক আউটপুটে যেত যা কোথাও জমত না।
 *
 * ⚠️ শিডিউলারকে দোষ দেওয়া যায় না — সে exit code দেখেই `DONE` না `FAIL`
 * ঠিক করে ([[ScheduleRunCommand::runEvent()]])। ⛔ সে `FAIL`-ই লিখত,
 * এমন এক ফাইলে **যা কেউ খোলে না**। ⭐ রোগটা যন্ত্রের সততায় ছিল না,
 * ছিল আমাদের তাকানোয় — আর সেজন্যই এই কমান্ড ফিসফিস করে না।
 *
 * ── ⭐ এই কমান্ডটা কোনো দাবি যাচাই করে না ───────────────────────────
 * সে জিজ্ঞেস করে না *"ব্যাকআপ কমান্ড সফল বলেছিল?"* — সে দেখে **ফাইলটা
 * আছে কি না, আর কত পুরনো**।
 *
 * ⚠️ পার্থক্যটা মূলে: কমান্ডের সাফল্য একটা দাবি, ফাইলের তারিখ একটা ঘটনা।
 * ⛔ দাবির উপর পাহারা বসালে দাবিটাই নিজেকে পাহারা দেয় — আর ছয় দিন ধরে
 * ঠিক সেটাই হয়েছে।
 *
 * ⓘ তাই এটা `abos:backup`-এর উপর নির্ভর করে না; cron বন্ধ হয়ে গেলেও,
 * বা শিডিউলের শর্তটা কোনোদিন সত্যি না হলেও, এই কমান্ড আলাদাভাবে চলে
 * আর সত্যিটা বলে।
 */
class BackupFresh extends Command
{
    protected $signature = 'abos:backup-fresh';

    protected $description = 'শেষ সফল ব্যাকআপ যথেষ্ট টাটকা কি না দেখে, না হলে জোরে বলে';

    public function handle(BackupFreshness $freshness): int
    {
        $complaint = $freshness->complaint();

        if ($complaint === null) {
            $hours = $freshness->hoursOld();

            $this->info(sprintf(
                'ব্যাকআপ টাটকা — শেষটা %d ঘণ্টা আগের (সীমা %d)।',
                (int) abs($hours ?? 0),
                BackupFreshness::STALE_AFTER_HOURS,
            ));

            return self::SUCCESS;
        }

        /*
         * ⚠️ তিন জায়গায় একই কথা, আর তিনটাই দরকার।
         *
         * ⓘ `error()` → কনসোল ও `backup.log` (শিডিউলে appendOutputTo)
         *    `critical()` → laravel.log, যেখানে নজরদারি তাকায়
         *    `FAILURE` → cron বা শিডিউলারের `onFailure`
         *
         * ⛔ একটা হলেই যথেষ্ট মনে হয়, কিন্তু ছয় দিনের নীরবতার কারণই
         * ছিল ধরে নেওয়া যে "কেউ না কেউ দেখবে"। কেউ দেখেনি।
         */
        $this->error($complaint);

        logger()->critical('ব্যাকআপ বাসি: '.$complaint);

        return self::FAILURE;
    }
}
