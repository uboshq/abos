<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Hr\Services\LeaveTypeDefaults;
use Illuminate\Console\Command;

/**
 * ⭐ চলতি কোম্পানিগুলোতে বাকি প্রমিত ছুটির ধরন — মাতৃত্বকালীন ছুটি সহ (শ্রম আইন অধ্যায় ৪; [[LeaveTypeDefaults]]; a6, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ নতুন কোম্পানি খোলার দিনেই পায় (মডিউলের `provisions`); এটা আগের কোম্পানিগুলোর জন্য, ডিপ্লয়ের পরে একবার। বারবার চালানো নিরাপদ —
 * যে কোড আছে (বা মোছা) সেটা ছোঁয় না।
 */
class InstallLeaveTypes extends Command
{
    protected $signature = 'abos:hr-install-leave-types';

    protected $description = 'প্রতিটা কোম্পানিতে বাকি প্রমিত ছুটির ধরন বসানো (মাতৃত্বকালীন সহ)';

    public function handle(LeaveTypeDefaults $defaults): int
    {
        $added = 0;

        foreach (Company::query()->orderBy('id')->pluck('id') as $companyId) {
            $added += (int) CompanyContext::forCompany((int) $companyId, fn (): int => $defaults->install());
        }

        $this->info("নতুন বসল: {$added}");

        return self::SUCCESS;
    }
}
