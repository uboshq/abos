<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\Money;
use App\Modules\Executive\Services\Snapshots;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ⭐ একবারের কাজ — মালিকের কেন্দ্রের পুরনো রাতের হিসাবের বিক্রি নিট করা (VAT ও ফেরত বাদে; fe, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ আগের রাতগুলোয় `sales` ঘরে বিলের মোট; নতুন কোড নিট লেখে। এই আদেশ প্রতিটা পুরনো সারি তার নিজের কোম্পানি, শাখা আর
 * দিনে নিট বিক্রিতে নতুন করে লেখে ([[Snapshots::salesToNet()]]), যাতে ইতিহাসের ধারায় দুই ভিত্তি না মেশে।
 * ⓘ দুইবার চালালে দ্বিতীয়বার কিছুই বদলায় না। ⓘ `--dry-run` কিছুই লেখে না — কয়টা সারি আর মোট কত বদলাত, কেবল সেটা বলে।
 * ⓘ `--company=` একটা কোম্পানিতে সীমিত; বদলের চিহ্ন সরে কেবল সব কোম্পানির পুরো চালে।
 * ⚠️ লাইভে চালানোর আগে ব্যাকআপ (fe চালাবেন)।
 */
class OwnerSnapshotsToNet extends Command
{
    protected $signature = 'abos:owner-snapshots-to-net
        {--dry-run : কিছুই লেখে না — কয়টা সারি আর মোট কত বদলাত, কেবল সেটা}
        {--company= : কেবল এই কোম্পানির (id) সারি}';

    protected $description = 'মালিকের কেন্দ্রের পুরনো রাতের হিসাবের বিক্রি নিট করে (VAT ও ফেরত বাদে) — একবারের কাজ';

    public function handle(Snapshots $snapshots): int
    {
        $dry = (bool) $this->option('dry-run');
        $company = $this->option('company');
        $company = $company === null || $company === '' ? null : (int) $company;

        $done = $snapshots->salesToNet($dry, $company);

        $summary = [
            'dry_run' => $dry,
            'company' => $company,
            'companies' => $done['companies'],
            'rows' => $done['rows'],
            'changed' => $done['changed'],
            'difference' => $done['difference'],
            'skipped' => $done['skipped'],
        ];
        Log::info('abos:owner-snapshots-to-net', $summary);

        $this->info(($dry ? '[dry-run] ' : '')."{$done['rows']}টি সারি দেখা হলো, {$done['changed']}টি "
            .($dry ? 'বদলাত' : 'বদলানো হলো').', মোট পার্থক্য '.Money::format($done['difference']).'।');

        if ($done['skipped'] !== []) {
            // ⛔ super_admin নেই — ঐ কোম্পানির সারি বদলানো যায়নি, তাই চিহ্নও থাকে
            $this->error('super_admin নেই, তাই বাদ: '.implode(', ', $done['skipped']));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
