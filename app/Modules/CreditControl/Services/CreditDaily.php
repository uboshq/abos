<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Customer\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ বাকি নিয়ন্ত্রণের রোজকার কাজ — চলতি কোম্পানিতে (বাকি নিয়ন্ত্রণ ধাপ ৩, `abos:credit-due` ডাকে)।
 *
 * ⓘ রাতের/সকালের কাজ — কোনো মানুষ বসে নেই, তাই কোম্পানির সব সক্রিয় গ্রাহক (কারও দেখার দেয়াল নয়); কোম্পানি ডাকার পক্ষ বসায়।
 * ⓘ বারবার চালানো নিরাপদ: দিনের ছবি একই দিনে একটাই সারি, খবর একই চাবিতে একবারই।
 */
final class CreditDaily
{
    public function __construct(private readonly CreditRisk $risk) {}

    /**
     * ⭐ আজকের ঝুঁকির ছবি — প্রতিটা সক্রিয় গ্রাহকের মাত্রা আর নম্বর ("ঝুঁকির ধারা" রিপোর্ট পড়ে, [[CreditRiskTrend]])।
     *
     * @return int কতজনের
     */
    public function snapshot(?Carbon $on = null): int
    {
        $day = ($on ?? Carbon::today())->toDateString();
        $companyId = (int) CompanyContext::id();
        $done = 0;

        $this->activeCustomers()->chunkById(500, function ($chunk) use ($day, $companyId, &$done): void {
            $rows = [];

            foreach ($this->risk->rateAll($chunk->keyBy('id')->all()) as $id => $r) {
                $rows[] = ['company_id' => $companyId, 'customer_id' => $id, 'taken_on' => $day,
                    'level' => $r['level'], 'score' => min(65535, $r['score']), 'created_at' => now()];
            }

            DB::table('credit_risk_snapshots')->upsert($rows, ['company_id', 'customer_id', 'taken_on'], ['level', 'score', 'created_at']);
            $done += count($rows);
        });

        return $done;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Customer> */
    private function activeCustomers()
    {
        return Customer::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->whereNull('deleted_at')
            ->where('is_active', true);
    }
}
