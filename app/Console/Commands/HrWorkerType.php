<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\MasterData\Models\EmploymentType;
use Illuminate\Console\Command;

/**
 * ⭐ "শ্রমিক" নিয়োগের ধরন (`WORKER`) প্রতিটা চলমান কোম্পানিতে — অধিকাল ডিফল্টে কেবল এই ধরনের (BLA ২০০৬ ধারা ১০৮; a6, ২১ অক্টোবর
 * ২০২৬; [[OvertimeService::isEligible()]])। নতুন কোম্পানি এমনিতেই পায় ([[MasterListService::installDefaults()]])।
 *
 * ⓘ BLA ধারা ২(৬৫): ব্যবস্থাপনা বা প্রশাসনিক কাজে নিয়োজিত মানুষ "শ্রমিক" নন — তাঁদের এই ধরনে বসানো যাবে না, আর তাঁরা অধিকাল
 * পান না। কমান্ডটা সেই কথাও বলে।
 *
 * ⓘ বারবার চালানো নিরাপদ — কোড `WORKER` (মোছা সারিসহ) থাকলে কিছুই নয়। `--dry` কেবল দেখায়।
 */
class HrWorkerType extends Command
{
    protected $signature = 'abos:hr-worker-type {--dry : কিছু না বসিয়ে কেবল দেখায়}';

    protected $description = 'প্রতিটা কোম্পানিতে "শ্রমিক" (WORKER) নিয়োগের ধরন বসায় — না থাকলে';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $made = 0;

        foreach (Company::query()->orderBy('id')->get() as $company) {
            $exists = CompanyContext::forCompany($company->id,
                fn () => EmploymentType::query()->withTrashed()->where('code', 'WORKER')->exists());

            if ($exists) {
                continue;
            }

            if (! $dry) {
                CompanyContext::forCompany($company->id, fn () => EmploymentType::query()->create([
                    'company_id' => $company->id, 'code' => 'WORKER', 'name_en' => 'Worker', 'name_bn' => 'শ্রমিক',
                    'is_default' => false, 'is_active' => true,
                ]));
            }

            $this->line(($dry ? 'বসবে' : 'বসল').' — '.$company->code.': WORKER (শ্রমিক)');
            $made++;
        }

        $this->info($made === 0 ? 'সব কোম্পানিতে "শ্রমিক" ধরন আগে থেকেই আছে।' : ($dry ? "{$made}টা কোম্পানিতে বসবে (শুকনো চালানো)।" : "{$made}টা কোম্পানিতে বসল।"));
        $this->line(__('hr::message.worker_note'));

        return self::SUCCESS;
    }
}
