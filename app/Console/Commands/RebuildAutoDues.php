<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Company;
use App\Core\Support\CompanyContext;
use App\Modules\Sales\Services\AutoDues;
use Illuminate\Console\Command;

/** ⭐ বিলের অটো বাকি খাতা থেকে আবার গোনা — প্রথমবার আর মিলিয়ে দেখতে ([[AutoDues]]); কোনো কাগজ ছোঁয় না। */
final class RebuildAutoDues extends Command
{
    protected $signature = 'abos:auto-dues {--company= : কেবল এই কোম্পানির আইডি}';

    protected $description = 'Counts again how much of each bill is settled by the customer ledger';

    public function handle(AutoDues $dues): int
    {
        foreach (Company::query()->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))->get() as $company) {
            $n = CompanyContext::forCompany((int) $company->id, fn () => $dues->company((int) $company->id));
            $this->line($company->id.': '.$n);
        }

        return self::SUCCESS;
    }
}
