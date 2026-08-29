<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\OpeningBalanceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * আগে বসানো খোলার জেরগুলো খাতায় তুলে দেয় — একবার, তারপর নিঃশব্দ।
 *
 * ── কেন এটা মাইগ্রেশন নয় ─────────────────────────────────────────────
 * মাইগ্রেশন স্কিমার কাজ; এটা **টাকার দাখিলা**। দাখিলা লিখতে কোম্পানির
 * প্রসঙ্গ, অর্থবছর আর পেছনের তালা — তিনটাই লাগে, আর তিনটাই থাকে
 * [[PostingEngine]]-এ। মাইগ্রেশনে ওগুলো ছাড়া সরাসরি সারি লিখলে সেটাই
 * হত সংখ্যাটার **তৃতীয়** উৎস — ঠিক যে রোগটা সারানো হচ্ছে।
 *
 * ── কেন এটা ডিপ্লয়ে চলতেই হবে ────────────────────────────────────────
 * [[Account::balanceOn()]] আর কলামটা যোগ করে না। অর্থাৎ এই কমান্ডটা না
 * চললে যে খাতগুলোর জের ঘোষিত আছে সেগুলোর ব্যালেন্স **কমে যাবে** —
 * আগে যেটা বেশি দেখাত, এখন সেটা কম দেখাবে। এক ভুলের বদলে আরেকটা।
 *
 * তাই `deploy.sh`-এ মাইগ্রেশনের ঠিক পরেই, আর বারবার চালানো নিরাপদ:
 * [[OpeningBalanceService::postFor()]] আগে বসানো জের দ্বিতীয়বার বসায় না।
 */
class PostMissingOpenings extends Command
{
    protected $signature = 'abos:post-missing-openings {--dry-run : কী হত তা দেখায়, কিছু বসায় না}';

    protected $description = 'ঘোষিত অথচ খাতায় না-বসা খোলার জেরগুলো দাখিলা হিসেবে বসায়';

    public function handle(OpeningBalanceService $openings): int
    {
        $dry = (bool) $this->option('dry-run');
        $posted = 0;
        $failed = 0;

        foreach (Company::query()->orderBy('id')->get() as $company) {
            CompanyContext::forCompany($company->id, function () use (
                $company, $openings, $dry, &$posted, &$failed
            ) {
                $rows = Account::query()
                    ->where('opening_balance', '!=', 0)
                    ->where('is_group', false)
                    ->orderBy('code')
                    ->get();

                foreach ($rows as $account) {
                    if ($dry) {
                        $this->line("  {$company->code}: {$account->code} {$account->name_en} = {$account->opening_balance}");
                        $posted++;

                        continue;
                    }

                    /*
                     * একটা খাতে আটকালে বাকিগুলো থেমে থাকে না।
                     *
                     * সবচেয়ে সম্ভাব্য কারণ পেছনের তালা: খোলার তারিখটা
                     * বন্ধ মাসে বা অর্থবছরের বাইরে। ওটা সত্যিকারের
                     * নিয়ম, ভাঙার জিনিস নয় — কিন্তু একটা খাতের জন্য
                     * পুরো কোম্পানি অর্ধেক সারানো অবস্থায় ফেলে রাখাও
                     * চলে না। নামটা লিখে দেওয়া হয়, হাতে সারানোর জন্য।
                     */
                    try {
                        if ($openings->forAccount($account) !== []) {
                            $this->line("  {$company->code}: {$account->code} বসল ({$account->opening_balance})");
                            $posted++;
                        }
                    } catch (Throwable $e) {
                        $failed++;
                        $this->warn("  {$company->code}: {$account->code} বসেনি — {$e->getMessage()}");
                    }
                }
            });
        }

        $this->info($posted === 0
            ? 'খাতায় না-বসা কোনো খোলার জের নেই।'
            : ($dry ? "{$posted}টা বসানোর মতো আছে।" : "{$posted}টা জের খাতায় বসেছে।"));

        if ($failed > 0) {
            $this->warn("{$failed}টা বসানো যায়নি — উপরের কারণগুলো দেখুন।");
        }

        return self::SUCCESS;
    }
}
