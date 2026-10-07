<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Modules\Customer\Services\LocationBranchBackfill;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ পুরনো এরিয়া, টেরিটরি, পয়েন্ট আর রুটে শাখা বসানো — মালিক, ৬ অক্টোবর ২০২৬ ([[LocationBranchBackfill]])।
 *
 * ⓘ `--apply` ছাড়া কেবল দেখায়, প্রতিটা নোডে তিনটা জিনিস: কী হবে (শাখা পাবে / নকল / সব শাখার থাকবে), শাখা ধরে
 * গ্রাহক কয়জন, আর কী কী বাঁধা। `--apply` এক লেনদেনে চলে, আগের অবস্থা `storage/app/location-backfill/`-এ JSON-এ
 * রাখে; `--revert=<ফাইল>` সেটা ফেরায়। আবার চালালে আগে বসানোগুলো ছোঁয়া হয় না।
 */
class LocationsToBranches extends Command
{
    protected $signature = 'master-data:locations-to-branches
        {--apply : সত্যিই বসায় — না দিলে কেবল দেখায়}
        {--revert= : আগের --apply-এর ফেরত-ফাইল ধরে ফেরায়}
        {--company= : কেবল এই কোম্পানির কোড (যেমন AVA)}';

    protected $description = 'পুরনো এলাকা আর রুটে শাখা বসায়, যে শাখার গ্রাহক আর চালান সেগুলো ব্যবহার করে; মিশ্র আর অবাঁধা হলে শাখা ধরে নকল';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $revert = $this->option('revert');
        $only = $this->option('company');

        $companies = Company::query()->orderBy('id')
            ->when($only, fn ($q, $code) => $q->where('code', $code))
            ->get();

        if ($revert && $companies->count() !== 1) {
            $this->error('  --revert-এর সাথে --company দিন — ফাইলটা এক কোম্পানির');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($companies as $company) {
            CompanyContext::forCompany((int) $company->id, function () use ($company, $apply, $revert, &$failed) {
                $backfill = app(LocationBranchBackfill::class);

                if ($revert) {
                    try {
                        $this->info("  {$company->code}: ".$backfill->revert((string) $revert).'টা এলাকা আগের অবস্থায় ফিরল, নকলগুলো মোছা হলো');
                    } catch (ValidationException $e) {
                        $this->error("  {$company->code}: ".collect($e->errors())->flatten()->implode(' '));
                        $failed = true;
                    }

                    return;
                }

                $plan = $backfill->plan();
                $names = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->pluck('code', 'id');
                $branch = fn ($id) => $id === null ? '—' : ($names[(int) $id] ?? (string) $id);

                if ($plan === []) {
                    $this->line("  {$company->code}: কিছু করার নেই");

                    return;
                }

                foreach ($plan as $e) {
                    $what = match ($e['action']) {
                        LocationBranchBackfill::SET => 'শাখা পাবে '.$branch($e['branch']),
                        LocationBranchBackfill::INHERIT => 'বাবার শাখা পাবে '.$branch($e['branch']),
                        LocationBranchBackfill::SPLIT => 'নকল — আসলটা '.$branch($e['branch']).'; '
                            .collect($e['copies'])->map(fn ($code, $b) => $branch($b).' → '.$code)->implode(', '),
                        default => 'সব শাখার থাকবে ('.($e['reason'] === 'bound' ? 'বাঁধা আছে' : 'নিচের এলাকা ভাগ হয়নি').')',
                    };
                    $people = $e['customers'] === [] ? '০' : collect($e['customers'])->map(fn ($n, $b) => $branch($b).": {$n}")->implode(', ');
                    $tied = $e['bound'] === [] ? 'নেই' : collect($e['bound'])->map(fn ($n, $t) => "{$t}: {$n}")->implode(', ');

                    $line = "  {$company->code}: {$e['level']} {$e['code']} ({$e['name']}) — {$what} | গ্রাহক {$people} | বাঁধা {$tied}";
                    $e['action'] === LocationBranchBackfill::SHARED ? $this->warn($line) : $this->line($line);
                }

                if (! $apply) {
                    $this->line("  {$company->code}: --apply দিলে উপরের সবটা এক লেনদেনে বসবে");

                    return;
                }

                // ⛔ একই সেকেন্ডে দুইবার চালালে আগের ফেরত-ফাইল মুছে যেত — কখনো ওপরে লেখা নয়, পরের খালি নাম
                $stem = storage_path('app/location-backfill/'.$company->code.'-'.now()->format('Ymd-His'));
                $file = $stem.'.json';

                for ($n = 2; file_exists($file); $n++) {
                    $file = "{$stem}-{$n}.json";
                }

                $done = $backfill->apply($plan, $file);
                $this->info("  {$company->code}: {$done['set']}টায় শাখা বসল, {$done['copies']}টা নকল, {$done['moved']}টা দোকান নকলে সরল");
                $this->info("  {$company->code}: ফেরত-ফাইল {$done['file']}");
            });
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
