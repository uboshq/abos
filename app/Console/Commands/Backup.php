<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Services\BackupService;
use App\Modules\Backup\Services\BackupRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ব্যাকআপ নেওয়া, আর নিয়েই সেটা ফিরিয়ে এনে দেখা।
 *
 * --verify ডিফল্টে চালু। কারণটা সহজ: যে ব্যাকআপ কখনো restore করে দেখা
 * হয়নি সেটা ব্যাকআপ নয়, আশা। ভাঙা ডাম্প নীরবে জমতে থাকে, আর সেটা জানা
 * যায় ঠিক সেই দিন যেদিন সত্যিই দরকার পড়ে — অর্থাৎ সবচেয়ে খারাপ দিনে।
 */
class Backup extends Command
{
    protected $signature = 'abos:backup
        {--no-verify : ফিরিয়ে এনে যাচাই কোরো না (দ্রুত, কিন্তু তখন আর জানা যায় না ডাম্পটা কাজের কি না)}
        {--keep-only : নতুন ডাম্প নিও না, শুধু পুরনোগুলো মুছে দাও}';

    protected $description = 'ডাটাবেজের ব্যাকআপ নেয়, যাচাই করে, আর পুরনোগুলো মুছে দেয়';

    public function handle(BackupService $backups): int
    {
        $now = Carbon::now();

        try {
            if (! $this->option('keep-only')) {
                $result = $backups->run($now);

                $this->info(sprintf(
                    'ব্যাকআপ নেওয়া হয়েছে: %s (%s)',
                    basename($result['file']),
                    $this->size($result['bytes']),
                ));

                if ($result['mirrored'] !== null) {
                    $this->line('  দ্বিতীয় কপি: '.$result['mirrored']);
                } else {
                    // থামানো হয় না — একটা ব্যাকআপ শূন্যের চেয়ে ভালো।
                    // কিন্তু বলা হয়, কারণ একই ডিস্কে রাখা ব্যাকআপ ডিস্ক
                    // ফেল করলে ব্যাকআপও নিয়ে যায়।
                    $this->warn('  দ্বিতীয় কোনো গন্তব্য বলা নেই (ABOS_BACKUP_MIRROR) — '
                        .'একই ডিস্ক নষ্ট হলে ব্যাকআপও হারাবে।');
                }

                if (! $this->option('no-verify')) {
                    $check = $backups->verify($result['file']);

                    $this->info("  যাচাই ঠিক আছে — ফিরিয়ে এনে {$check['tables']}টা টেবিল পাওয়া গেছে।");
                }

                /*
                 * ── গন্তব্যে কপি, আর যা হলো তা লিখে রাখা ─────────────
                 *
                 * ⚠️ এই ব্লকটা ৩ সেপ্টেম্বর ২০২৬-এ যোগ হয়েছে, আর কারণটা
                 * একটা ফাঁক যা প্রায় চোখ এড়িয়ে গিয়েছিল।
                 *
                 * নতুন গন্তব্য-ব্যবস্থাটা [[BackupRunner]]-এ, আর ওটা
                 * ডাকা হত কেবল **পর্দার বোতাম** থেকে। কিন্তু রোজকার
                 * ব্যাকআপ চলে এই কমান্ড দিয়ে (`abos:backup-due` →
                 * `abos:backup`), আর deploy-ও এটাই ডাকে।
                 *
                 * অর্থাৎ গন্তব্যে কপি যেত **কেবল যেদিন কেউ হাতে বোতাম
                 * চাপতেন** — রাতের ব্যাকআপগুলো, যেগুলোই আসল সুরক্ষা,
                 * কোথাও যেত না। আর পর্দা তবু সবুজ দেখাত, কারণ ফাইলটা
                 * তো তৈরি হচ্ছিল।
                 *
                 * ── কেন এখানে, `BackupRunner`-এর ভেতরে ডাম্পটা নয় ────
                 * এই কমান্ডটার signature `deploy.sh:93` ধরে আছে। ওটা
                 * বদলালে প্রতিটা deploy ব্যাকআপের ধাপেই থেমে যেত, আর
                 * ধরা পড়ত লাইভে। তাই ডাম্পের পথটা অপরিবর্তিত; কেবল
                 * কপি ও হিসাবটা পরে যোগ হয়।
                 *
                 * ⚠️ কনসোলে কোনো কোম্পানি-প্রসঙ্গ নেই, তাই রানার
                 * প্রতিটা কোম্পানির গন্তব্য আলাদা করে দেখে।
                 */
                app(BackupRunner::class)->recordAndCopy($result, $this);
            }

            $removed = $backups->prune($now);

            if ($removed !== []) {
                $this->line(sprintf(
                    '  %dটা পুরনো ডাম্প মুছে ফেলা হয়েছে (%d দিনের বেশি)।',
                    count($removed),
                    (int) config('abos.backup.keep_days'),
                ));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            /*
             * ব্যর্থতা চিৎকার করে বলা হয়।
             *
             * নীরবে ব্যর্থ হওয়া ব্যাকআপ সবচেয়ে বিপজ্জনক জিনিস: সবাই
             * ভাবে ব্যাকআপ আছে, অথচ নেই। তাই exit code-ও ব্যর্থ, যাতে
             * scheduler বা cron-এর নজরদারি এটা ধরতে পারে।
             */
            $this->error('ব্যাকআপ ব্যর্থ: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function size(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1).' '.$unit;
            }

            $bytes = (int) ($bytes / 1024);
        }

        return $bytes.' TB';
    }
}
