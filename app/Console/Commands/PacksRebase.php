<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\PackRebase;
use App\Modules\MasterData\Models\Unit;
use Illuminate\Console\Command;

/**
 * একটা পণ্যের base একক নামানো — "১ কার্টন = ২৪ পিস"।
 *
 * ⚠️ ডিফল্টে কিছু লেখে না, কেবল দেখায় আর ফিরিয়ে নেয়। লিখতে `--apply`।
 *
 *     php artisan abos:packs-rebase PRD-0001 --per=24 --to=PCS --company=DEM
 *     php artisan abos:packs-rebase PRD-0001 --per=24 --to=PCS --company=DEM --apply
 *
 * ⓘ কেন এটা আলাদা কমান্ড, আর ফর্ম থেকে নয় — [[PackRebase]]-এর মাথায়।
 */
class PacksRebase extends Command
{
    protected $signature = 'abos:packs-rebase
        {code : পণ্যের কোড}
        {--to=PCS : নতুন base এককের কোড}
        {--per= : ১ পুরনো এককে কতটা নতুন একক}
        {--company= : কোম্পানির কোড; না দিলে সবগুলোয় খোঁজে}
        {--apply : সত্যিই লেখে; না দিলে কেবল রিপোর্ট}';

    protected $description = 'একটা পণ্যের মজুদ ছোট এককে নামায় (১ কার্টন = ২৪ পিস), মূল্য অক্ষত রেখে';

    public function handle(PackRebase $rebase): int
    {
        $per = (string) $this->option('per');

        if ($per === '' || ! is_numeric($per)) {
            $this->error('--per দিন, যেমন --per=24 (১ পুরনো এককে কতটা নতুন একক)।');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $found = 0;

        foreach ($this->companies() as $company) {
            CompanyContext::forCompany($company->id, function () use ($company, $per, $apply, $rebase, &$found) {
                $product = Product::query()->where('code', $this->argument('code'))->first();
                $to = Unit::query()->where('code', $this->option('to'))->first();

                if ($product === null || $to === null) {
                    return;
                }

                $found++;
                $report = $rebase->run($product, $to, $per, $apply);

                $this->newLine();
                $this->line("■ {$company->code} · {$report['product']}: {$report['from']} → {$report['to']} (১ {$report['from']} = {$report['per']} {$report['to']})");

                foreach ($report['rows'] as $table => $rows) {
                    $this->line("    {$table}: {$rows} সারি");
                }

                $this->line("    পরিমাণ: {$report['qty_before']} → {$report['qty_after']}");
                $this->line("    মূল্য:  {$report['value_before']} → {$report['value_after']}");

                if ($report['layers_split'] > 0) {
                    $this->line("    খরচের স্তর ভাগ হলো: {$report['layers_split']}টা (অবশিষ্ট বহনের জন্য, মূল্য হুবহু রাখতে)");
                }

                if (bccomp($report['value_before'], $report['value_after'], 4) !== 0) {
                    $this->error('    ⛔ মূল্য বদলে গেছে — এটা হওয়ার কথা নয়, লেখা হয়নি।');
                }
            });
        }

        if ($found === 0) {
            $this->error('ঐ কোডের পণ্য বা একক পাওয়া যায়নি।');

            return self::FAILURE;
        }

        $this->newLine();

        /*
         * ⚠️ যা লুকানো হয় না: ছাপা লাইনে "পরিমাণ × দর" আর বিলের অঙ্ক
         * পয়সার ভগ্নাংশ আলাদা দেখাতে পারে। ⓘ ২ কার্টন × ১৭২.৫৪ = ৩৪৫.০৮,
         * আর ৪৮ পিস × ৭.১৮৯১ = ৩৪৫.০৭৬৮ — কারণ প্রতি-পিস দর চার ঘরেই
         * লেখা যায়। ⭐ বিলের টাকা (`amount`) অক্ষত থাকে, তাই খাতার অঙ্ক
         * বদলায় না; কেবল কাগজে গুণ করলে শেষ পয়সার ভগ্নাংশে ফারাক।
         */
        $this->line('ⓘ পুরনো কাগজে "পরিমাণ × দর" বিলের অঙ্কের চেয়ে পয়সার ভগ্নাংশ আলাদা দেখাতে পারে — বিলের টাকা অপরিবর্তিত।');
        $this->newLine();
        $this->info($apply ? 'লেখা শেষ।' : 'কিছুই লেখা হয়নি (--apply দিলে লিখবে)।');

        return self::SUCCESS;
    }

    /** @return iterable<Company> */
    private function companies(): iterable
    {
        $code = $this->option('company');

        return Company::query()
            ->when($code, fn ($q) => $q->where('code', $code))
            ->orderBy('id')
            ->get();
    }
}
