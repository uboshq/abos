<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Inventory\Services\PackBackfill;
use Illuminate\Console\Command;

/**
 * পণ্যের প্যাকের টেবিল ভরা — পুরনো ডেটা থেকে, একবার।
 *
 * ⚠️ ডিফল্টে কিছু লেখে না: কী হত তার রিপোর্ট দেখায়, তারপর সব ফিরিয়ে
 * নেয়। লিখতে `--apply`। ⓘ চালানোর নিয়ম:
 *
 *     php artisan abos:packs-snapshot --save=before.json
 *     php artisan abos:packs-backfill            ← রিপোর্ট দেখুন
 *     php artisan abos:packs-backfill --apply
 *     php artisan abos:packs-snapshot --compare=before.json   ← অমিল হলে exit 1
 *
 * কী করে আর কেন — [[PackBackfill]]।
 */
class PacksBackfill extends Command
{
    protected $signature = 'abos:packs-backfill {--apply : সত্যিই লেখে; না দিলে কেবল রিপোর্ট}';

    protected $description = 'পণ্যের প্যাকের টেবিল পুরনো ডেটা থেকে ভরে (base সারি, একক ছাড়া পণ্যে PCS, ডজন = ১২ পিস)';

    public function handle(PackBackfill $backfill): int
    {
        /*
         * ⚠️ টেবিলটা না থাকলে সোজা কথায় থামা — ১৯ সেপ্টেম্বর ২০২৬, লোকাল
         * ডেটাবেসে মাপা: migrate ছাড়া চালালে SQL-এর লম্বা স্ট্যাক ট্রেস
         * আসত, যেটা পড়ে বোঝা যেত না যে কেবল ধাপের ক্রম ভুল।
         */
        if (! \Illuminate\Support\Facades\Schema::hasTable('inv_product_units')) {
            $this->error('inv_product_units টেবিল নেই — আগে `php artisan migrate` চালান।');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        $this->info($apply ? 'লেখা হচ্ছে…' : 'কেবল দেখা — কিছু লেখা হবে না (--apply দিলে লিখবে)');

        foreach ($backfill->run($apply) as $company) {
            $this->newLine();
            $this->line("■ {$company['company']}");

            if ($company['no_piece_unit']) {
                $this->warn('  PCS একক নেই — একক ছাড়া পণ্যে কিছু বসানো যায়নি, ডজনও জোড়া যায়নি।');
            }

            $this->line('  ডজন → ১২ পিস: '.($company['dozen_linked'] ? 'জোড়া হলো' : 'আগেই জোড়া / দরকার নেই'));
            $this->line('  একক ছাড়া পণ্যে PCS: '.($company['given_a_unit'] === []
                ? 'কেউ নেই'
                : count($company['given_a_unit']).'টা — '.implode(', ', $company['given_a_unit'])));
            $this->line("  base সারি নতুন: {$company['base_rows']}টা");

            foreach ($company['waiting'] as $wait) {
                $this->warn("  অপেক্ষায়: {$wait['product']} — মজুদ {$wait['unit']}-এ গোনা। "
                    ."১ {$wait['unit']} = কত PCS, মালিক বললে তবে base বদলাবে।");
            }
        }

        $this->newLine();
        $this->info($apply ? 'লেখা শেষ।' : 'কিছুই লেখা হয়নি।');

        return self::SUCCESS;
    }
}
