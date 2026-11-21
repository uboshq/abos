<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Messaging\Services\CallService;
use Illuminate\Console\Command;

/**
 * A ring nobody answered in 45 s becomes `missed` — every company, every minute (contract §4, [[CallService::sweep()]]).
 *
 * ⓘ Here and not in the module: Laravel finds commands only in `app/Console/Commands`, and `module.php` has no
 * `commands` key (same as [[ExpireLapsedPromotions]]). The work itself stays in the module.
 * ⓘ Safe to run twice: only `ringing` rows are taken, each under a row lock.
 */
final class SweepRingingCalls extends Command
{
    protected $signature = 'messenger:sweep-calls
        {--company= : only this company code}';

    protected $description = 'Mark ringing messenger calls nobody answered as missed, in every company';

    public function handle(CallService $calls): int
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $code) => $q->where('code', $code))
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($companies as $company) {
            // ⓘ forCompany puts the previous context back in `finally`, even if one company throws
            $total += (int) CompanyContext::forCompany($company->id, fn () => $calls->sweep());
        }

        if ($total > 0) {
            $this->info(sprintf('messenger:sweep-calls — %d call(s) missed', $total));
        }

        return self::SUCCESS;
    }
}
