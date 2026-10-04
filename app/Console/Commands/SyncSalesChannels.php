<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\MasterData\Services\SalesChannelDefaults;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * ডিপ্লয়ের সময় চলে — বিক্রয়ের পথের প্রমিত সারিগুলো প্রতিটা চলমান কোম্পানিতে।
 *
 * ── ⛔ কেন দরকার ─────────────────────────────────────────────────────
 * [[SalesChannelDefaults]] নতুন কোম্পানি খোলার সময় চলে। ⚠️ লাইভের
 * কোম্পানিগুলো আগেই খোলা, তাই এই কমান্ড না চললে ওদের পথের তালিকা চিরকাল
 * খালি থাকত — গ্রাহকের ফর্মে ড্রপডাউন ফাঁকা, আর রিপোর্টে সব বিক্রি
 * "পথ বসানো হয়নি"। কিছুই লাল হত না ([[SyncReasonCodes]]-এর একই শিক্ষা)।
 *
 * ⓘ বারবার চালানো নিরাপদ: বসানো, নাম-বদলানো বা মুছে-ফেলা সারি ছোঁয়া হয় না।
 */
class SyncSalesChannels extends Command
{
    protected $signature = 'abos:sync-sales-channels';

    protected $description = 'প্রতিটা কোম্পানিতে অনুপস্থিত বিক্রয়-পথগুলো বসায়';

    public function handle(SalesChannelDefaults $channels): int
    {
        $total = 0;

        foreach (Company::query()->orderBy('id')->get() as $company) {
            // প্রতিটা কোম্পানির নিজের প্রসঙ্গে — নাহলে স্কোপ আগের কোম্পানির সারি দেখত
            try {
                $added = CompanyContext::forCompany(
                    $company->id,
                    fn () => $channels->installMissing(),
                );
            } catch (ValidationException $refused) {
                /*
                 * ⚠️ একটা কোম্পানিতে আটকালে বাকিরা যেন বাদ না পড়ে — সম্ভবত
                 * কেউ একটা পথের নাম এমন রেখেছেন যা নতুন সারির নামের সাথে মেলে।
                 */
                $this->warn("{$company->code}: ".$refused->getMessage());

                continue;
            }

            if ($added > 0) {
                $this->line("{$company->code}: {$added}টা পথ যোগ হলো");
            }

            $total += $added;
        }

        $this->info($total === 0
            ? 'সব কোম্পানির বিক্রয়-পথ আগে থেকেই বসানো।'
            : "মোট {$total}টা পথ যোগ হয়েছে।");

        return self::SUCCESS;
    }
}
