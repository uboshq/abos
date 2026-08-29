<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use Illuminate\Console\Command;

/**
 * ১১০২ ছিল "ব্যাংক ও মোবাইল ব্যাংকিং" — এখন দুইটা মাথা।
 *
 * ── কেন, ৩০ আগস্ট ২০২৬ ───────────────────────────────────────────────
 * মালিকের প্রশ্ন: *"bank & MFS eksathe keno? hisab milate somossaw hobe
 * karon mfs e charge kate"* — আর তিনি ঠিক। বিকাশ ক্যাশ-আউটে চার্জ কাটে,
 * মিলকরণের কাগজ আলাদা, সেটেলমেন্টের সময়ও আলাদা। মিশিয়ে রাখলে "ব্যাংকে
 * কত আছে" সংখ্যাটাই মিথ্যা।
 *
 * ছকের ঘোষণা বদলানো সহজ। কিন্তু চলমান কোম্পানিতে বিকাশের হিসাবগুলো
 * ইতিমধ্যেই ১১০২-এর নিচে বসে আছে — ঘোষণা বদলালে ওগুলো নিজে থেকে সরে
 * যায় না। এই কমান্ডটা সেটাই করে।
 *
 * ── কেন নাম দেখে চেনা, আর সেটা কতটা নিরাপদ ───────────────────────────
 * সারিতে "এটা MFS" বলার মতো কোনো ঘর নেই — ব্যাংক আর MFS দুইটাই
 * `is_bank` পতাকা পরে। তাই একমাত্র সংকেত নামটাই।
 *
 * তালিকাটা বাংলাদেশের চেনা প্রদানকারীদের, আর **যা মেলে না তা ছোঁয়া
 * হয় না** — অচেনা কিছু ব্যাংকেই থেকে যায়। উল্টোটা করলে (সন্দেহ হলেই
 * সরানো) একটা ব্যাংক হিসাব ভুল মাথায় চলে যেত, আর সেটা ধরা পড়ত
 * মিলকরণের দিনে।
 *
 * সরানো মানে কেবল `parent_id` বদলানো — কোনো দাখিলা নড়ে না, কোনো
 * ব্যালেন্স বদলায় না। ভুল হলে হাতে ফেরানো যায়।
 */
class SplitBankAndMobileMoney extends Command
{
    protected $signature = 'abos:split-bank-and-mfs {--dry-run : কী হত তা দেখায়, কিছু সরায় না}';

    protected $description = 'বিকাশ/নগদ/রকেটের হিসাবগুলো ব্যাংকের নিচ থেকে মোবাইল ব্যাংকিংয়ের নিচে সরায়';

    /**
     * বাংলাদেশে চালু MFS প্রদানকারী — নামের অংশ মিললেই MFS।
     *
     * ছোট হাতের অক্ষরে মিলানো হয়, কারণ কেউ লেখেন "bKash", কেউ "BKASH",
     * কেউ "বিকাশ"।
     *
     * @var list<string>
     */
    private const PROVIDERS = [
        'bkash', 'বিকাশ',
        'nagad', 'নগদ',
        'rocket', 'রকেট',
        'upay', 'উপায়',
        'mcash', 'এমক্যাশ',
        'surecash', 'শিওরক্যাশ',
        'tap ', 'ট্যাপ',
        'okwallet', 'ok wallet',
        'islamic wallet',
        'mfs',
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $moved = 0;

        foreach (Company::query()->orderBy('id')->get() as $company) {
            CompanyContext::forCompany($company->id, function () use ($company, $dry, &$moved) {
                $bank = StandardChart::find(StandardChart::BANK);
                $mfs = StandardChart::find(StandardChart::MOBILE_MONEY);

                if ($bank === null || $mfs === null) {
                    $this->warn("  {$company->code}: ১১০২ বা ১১০৫ নেই — আগে `abos:sync-chart` চালান।");

                    return;
                }

                /*
                 * মাথাটার নামও বদলায় — আগে ছিল "ব্যাংক ও মোবাইল
                 * ব্যাংকিং", আর এখন MFS ওখানে নেই। নাম না বদলালে
                 * পর্দায় একটা মিথ্যা লেবেল থেকে যেত।
                 *
                 * কোম্পানি নিজে নাম বদলে থাকলে ছোঁয়া হয় না — সেটা
                 * তাঁর সিদ্ধান্ত, আর `install()`-এর নিয়মও তাই।
                 */
                if (! $dry && $bank->name_en === 'Bank & Mobile Money') {
                    $bank->forceFill(['name_en' => 'Bank', 'name_bn' => 'ব্যাংক'])->save();
                    $this->line("  {$company->code}: ১১০২-এর নাম বদলে 'ব্যাংক' হলো");
                }

                foreach (Account::query()->where('parent_id', $bank->id)->orderBy('code')->get() as $account) {
                    if (! $this->looksLikeMfs($account)) {
                        continue;
                    }

                    $this->line("  {$company->code}: {$account->code} {$account->name_en} → ১১০৫");
                    $moved++;

                    if (! $dry) {
                        $account->forceFill(['parent_id' => $mfs->id])->save();
                    }
                }
            });
        }

        $this->info($moved === 0
            ? 'ব্যাংকের নিচে সরানোর মতো কোনো MFS হিসাব নেই।'
            : ($dry ? "{$moved}টা সরানোর মতো আছে।" : "{$moved}টা হিসাব মোবাইল ব্যাংকিংয়ের নিচে গেল।"));

        return self::SUCCESS;
    }

    private function looksLikeMfs(Account $account): bool
    {
        $name = mb_strtolower(trim($account->name_en.' '.$account->name_bn));

        foreach (self::PROVIDERS as $provider) {
            if (str_contains($name, $provider)) {
                return true;
            }
        }

        return false;
    }
}
