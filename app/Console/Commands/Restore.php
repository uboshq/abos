<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

/**
 * একটা ব্যাকআপ থেকে ডাটাবেজ ফিরিয়ে আনা।
 *
 * এই কমান্ডটা ডাটাবেজের সব কিছু মুছে ফেলে ডাম্পের অবস্থায় ফিরিয়ে নেয়।
 * তাই এখানে দুইটা তালা: কোন ফাইল থেকে ফেরানো হচ্ছে ও তাতে কী আছে সেটা
 * দেখিয়ে নিশ্চিত হওয়া, আর ফেরানোর আগে চলতি অবস্থার একটা ডাম্প নেওয়া।
 *
 * দ্বিতীয়টা বেশি জরুরি: ভুল ফাইল থেকে ফেরানো হলে আজকের কাজ হারায়, আর
 * তখন ফেরার কোনো পথ থাকে না। "ফেরানোর আগের ব্যাকআপ" ঠিক ওই একটা
 * পরিস্থিতির জন্য।
 */
class Restore extends Command
{
    protected $signature = 'abos:restore
        {file? : কোন ডাম্প থেকে; না দিলে সবচেয়ে নতুনটা}
        {--force : নিশ্চিত হওয়ার প্রশ্ন এড়াও (স্ক্রিপ্টের জন্য)}
        {--no-safety : ফেরানোর আগে চলতি অবস্থার ডাম্প নিও না}';

    protected $description = 'একটা ব্যাকআপ থেকে ডাটাবেজ ফিরিয়ে আনে';

    public function handle(BackupService $backups): int
    {
        $file = $this->argument('file') ?? $backups->latest();

        if ($file === null) {
            $this->error('কোনো ব্যাকআপ পাওয়া যায়নি — '.config('abos.backup.path'));

            return self::FAILURE;
        }

        if (! is_file($file)) {
            $this->error("ফাইলটা নেই: {$file}");

            return self::FAILURE;
        }

        try {
            /*
             * আগে যাচাই, তারপর ফেরানো।
             *
             * ভাঙা ডাম্প দিয়ে ফেরাতে গেলে অর্ধেক টেবিল বসে বাকিটা বসে
             * না — আর তখন ডাটাবেজটা না পুরনো, না নতুন। যাচাই আলাদা
             * ডাটাবেজে হয়, তাই চলতি ডেটা তখনো অক্ষত।
             */
            $this->line('যাচাই করা হচ্ছে: '.basename($file));

            $check = $backups->verify($file);

            $this->info("  ডাম্পটা কাজের — {$check['tables']}টা টেবিল আছে।");

            if (! $this->option('force') && ! $this->confirm(
                'চলতি ডাটাবেজের সব কিছু মুছে গিয়ে এই ডাম্পের অবস্থায় ফিরবে। নিশ্চিত?',
                false,
            )) {
                $this->line('বাতিল করা হলো।');

                return self::SUCCESS;
            }

            if (! $this->option('no-safety')) {
                // ভুল ফাইল থেকে ফেরালে আজকের কাজ হারায় — এটাই একমাত্র
                // ফেরার পথ
                $safety = $backups->run(now());

                $this->line('  ফেরানোর আগের অবস্থা রাখা হলো: '.basename($safety['file']));
            }

            $backups->restore($file);

            $this->info('ফিরিয়ে আনা হয়েছে: '.basename($file));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('ফেরানো ব্যর্থ: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
