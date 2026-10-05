<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Finance\Services\OwnerCapital;
use Illuminate\Console\Command;

/**
 * ⭐ এ পর্যন্ত বসা শুরুর মূলধন মালিকের নামে রেজিস্টারে — মালিকের আদেশ, ৫ অক্টোবর ২০২৬ ([[OwnerCapital]])।
 *
 * ⓘ কোম্পানি আর শাখা ধরে ৩১০০-এ যা আছে কিন্তু রেজিস্টারে নেই, তা মালিকের নামে `opening` সারি হয়। `--apply` ছাড়া কেবল
 * গোনে আর দেখায়। মালিক বাছা না থাকলে সেই কোম্পানি বাদ, আর নামটা বলে। আবার চালালে ফাঁক শূন্য, তাই কিছুই হয় না।
 */
class BringOpeningCapitalIntoTheRegister extends Command
{
    protected $signature = 'finance:opening-capital
        {--apply : সত্যিই সারি বসায় — না দিলে কেবল গোনে}
        {--company= : কেবল এই কোম্পানির কোড (যেমন AVA)}';

    protected $description = 'খাতায় বসা শুরুর মূলধন মালিকের নামে মূলধনের রেজিস্টারে তোলে, শাখা ধরে';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $only = $this->option('company');

        $companies = Company::query()->orderBy('id')
            ->when($only, fn ($q, $code) => $q->where('code', $code))
            ->get();

        foreach ($companies as $company) {
            CompanyContext::forCompany((int) $company->id, function () use ($company, $apply) {
                $capital = app(OwnerCapital::class);
                $gaps = $capital->gaps();

                if ($gaps === []) {
                    $this->line("  {$company->code}: রেজিস্টার খাতার সাথে মেলে — কিছু করার নেই");

                    return;
                }

                foreach ($gaps as $branch => $gap) {
                    $this->line("  {$company->code}: শাখা ".($branch === '' ? '—' : $branch)." — রেজিস্টারে কম {$gap}");
                }

                $owner = $capital->owner();

                if ($owner === null) {
                    $this->warn("  {$company->code}: মালিক বাছা নেই (finance.owner_person_id) — বাদ রইল");

                    return;
                }

                if (! $apply) {
                    $this->line("  {$company->code}: --apply দিলে {$owner->name()}-এর নামে ".count($gaps).'টা সারি বসবে');

                    return;
                }

                foreach ($capital->reconcile() as $row) {
                    $this->info("  {$company->code}: {$row->document_no} — {$owner->name()}, {$row->amount}");
                }
            });
        }

        return self::SUCCESS;
    }
}
