<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Hr\Models\LeaveType;
use Illuminate\Console\Command;

/**
 * ⭐ চলতি কোম্পানিগুলোর অর্জিত ছুটির "বছরে দিন" শূন্যে — তথ্য বদলের কাজ, স্কিমা নয় (fe-র উত্তর ৪, ২১ অক্টোবর ২০২৬; শ্রম আইন
 * ধারা ১১৭; [[EarnedLeave]]; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ অর্জিত ছুটির পাওনা এখন হাজিরা থেকে; পুরনো কোম্পানির ধরনে বসানো "১৫ দিন" আর কিছু করে না, কিন্তু পর্দায় আর খাতায় মিথ্যা
 * বলে। ⭐ আগে `--dry` — কোন কোম্পানির কত থেকে শূন্য হবে তার তালিকা, কিছু না লিখে। তারপর আসল চালানো: মডেল ধরে বদল, তাই আগের
 * মান অডিটে ([[IsAudited]]; পুরনো → নতুন)। বারবার চালানো নিরাপদ — যা শূন্য তা ছোঁয় না।
 */
class EarnedLeaveDaysToZero extends Command
{
    protected $signature = 'abos:hr-earned-days-zero {--dry : কেবল তালিকা, কিছু লেখে না}';

    protected $description = 'প্রতিটা কোম্পানির অর্জিত ছুটির ধরনে "বছরে দিন" শূন্য করা (আগের মান অডিটে)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $changed = 0;

        foreach (Company::query()->orderBy('id')->get(['id', 'code']) as $company) {
            $changed += (int) CompanyContext::forCompany((int) $company->id, function () use ($company, $dry): int {
                $count = 0;

                foreach (LeaveType::query()->where('code', LeaveType::EARNED)->where('days_per_year', '!=', 0)->get() as $type) {
                    $this->line(sprintf('%s · %s: %s → 0%s', $company->code, $type->code, rtrim(rtrim((string) $type->days_per_year, '0'), '.'), $dry ? ' (dry)' : ''));

                    if (! $dry) {
                        $type->forceFill(['days_per_year' => 0])->save();
                    }

                    $count++;
                }

                return $count;
            });
        }

        $this->info(($dry ? 'বদলাবে: ' : 'বদলাল: ').$changed);

        return self::SUCCESS;
    }
}
