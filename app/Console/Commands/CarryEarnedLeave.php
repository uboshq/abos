<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\Hr\Services\EarnedLeave;
use Illuminate\Console\Command;

/**
 * ⭐ অর্জিত ছুটির জের — শেষ হওয়া বছরের সমাপনী পরের বছরে, প্রতিটা কোম্পানিতে (শ্রম আইন ধারা ১১৭; [[EarnedLeave::carryForward()]];
 * a6, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ বছর না দিলে আগের বছর। রোজ ভোরে চলে ([[routes/console.php]]) — বারবার চালালে একই সারি নতুন করে লেখে, তাই জানুয়ারির ১ তারিখ
 * ফসকালেও পরের ভোরে বসে, আর আগের বছরের দেরিতে লেখা হাজিরাও ধরা পড়ে। ⚠️ পুরনো কয়েক বছর একসাথে বসাতে হলে ক্রমে চালান
 * (`--year=2025`, তারপর `--year=2026`) — আগের বছরের জের পরের বছরের সমাপনীতে ঢোকে।
 */
class CarryEarnedLeave extends Command
{
    protected $signature = 'abos:hr-carry-earned-leave {--year= : যে বছর শেষ হয়েছে (ডিফল্ট: আগের বছর)}';

    protected $description = 'অর্জিত ছুটির বছর শেষের জের পরের বছরে বসানো (৪০ দিন পর্যন্ত)';

    public function handle(EarnedLeave $earned): int
    {
        $year = $this->option('year') !== null ? (int) $this->option('year') : (int) now()->year - 1;

        // ⛔ চলতি বা ভবিষ্যতের বছর নয় — বছর শেষ না হলে সমাপনী নেই, আর আধা বছরের "জের" পরের বছরে ভুল দিন বসাত
        if ($year < 2000 || $year >= (int) now()->year) {
            $this->error("বছরটা ঠিক নয় (শেষ হওয়া বছর চাই): {$year}");

            return self::FAILURE;
        }

        $written = 0;

        foreach (Company::query()->orderBy('id')->pluck('id') as $companyId) {
            $written += (int) CompanyContext::forCompany((int) $companyId, fn (): int => $earned->carryForward($year));
        }

        $this->info("{$year} → ".($year + 1).": {$written}");

        return self::SUCCESS;
    }
}
