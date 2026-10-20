<?php

declare(strict_types=1);

namespace App\Modules\CreditControl\Services;

use App\Core\Services\NotificationService;
use App\Core\Services\Ownership;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Modules\Customer\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                $rows[] = ['public_id' => (string) Str::uuid7(), 'company_id' => $companyId, 'customer_id' => $id, 'taken_on' => $day,
                    'level' => $r['level'], 'score' => min(65535, $r['score']), 'created_at' => now()];
            }

            DB::table('credit_risk_snapshots')->upsert($rows, ['company_id', 'customer_id', 'taken_on'], ['level', 'score', 'created_at']);
            $done += count($rows);
        });

        return $done;
    }

    /**
     * ⭐ অস্থায়ী সীমা শেষ হয়ে আসছে — শেষ দিন আজ থেকে তিন দিনের মধ্যে, মালিক(দের) খবর (ধাপ ৩.৪)।
     *
     * ⓘ একই সীমার একই শেষ দিনে একবারই (চাবি গ্রাহক + শেষ দিন) — রোজ চললেও; নতুন শেষ দিন বসালে নতুন খবর।
     * ⓘ খবর কেবল জানায়: শেষ দিনের পরদিন সীমা নিজে থেকেই সাধারণে নামে ([[Customer::effectiveCreditLimit()]])।
     *
     * @return int কতটা সীমার খবর গেল
     */
    public function temporaryEnding(?Carbon $on = null): int
    {
        $today = ($on ?? Carbon::today())->copy()->startOfDay();
        $companyId = (int) CompanyContext::id();
        $owners = app(Ownership::class)->activeOwnersIn($companyId);
        $told = 0;

        $ending = $this->activeCustomers()
            ->whereNotNull('temp_credit_limit')
            ->whereBetween('temp_credit_limit_until', [$today->toDateString(), $today->copy()->addDays(self::ENDING_DAYS)->toDateString()])
            // ⓘ খবরের নামের পাশে পয়েন্ট আর শাখা — গোটা দলের সাথে একবারে, সারি ধরে নয়
            ->with(['location.parent', 'branch'])
            ->get();

        foreach ($ending as $customer) {
            $until = $customer->temp_credit_limit_until->toDateString();

            foreach ($owners as $owner) {
                app(NotificationService::class)->send(
                    $owner,
                    self::TEMPORARY_ENDING,
                    // ⭐ "নাম · পয়েন্ট · শাখা" — কোন দোকান, কোন শাখা (মালিক, ১৯ অক্টোবর ২০২৬, স্থায়ী নিয়ম)
                    __('credit_control::notice.temporary_ending_title', ['customer' => $customer->nameWithPoint(), 'date' => DateFormat::format($until)]),
                    __('credit_control::notice.temporary_ending_body', [
                        'amount' => Money::format((string) $customer->temp_credit_limit),
                        'normal' => Money::format((string) ($customer->credit_limit ?? '0')),
                    ]),
                    route('credit_control.customer.show', $customer->id),
                    evenToSelf: true,
                    key: 'credit_control:temp_ending:'.$customer->id.':'.$until,
                    about: $customer,
                );
            }

            $told++;
        }

        return $told;
    }

    /** কত দিন আগে খবর — মালিকের তালিকা: "৩ দিনের মধ্যে শেষ হবে" */
    public const ENDING_DAYS = 3;

    public const TEMPORARY_ENDING = 'credit_control.temporary_limit_ending';

    /** @return \Illuminate\Database\Eloquent\Builder<Customer> */
    private function activeCustomers()
    {
        return Customer::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->whereNull('deleted_at')
            ->where('is_active', true);
    }
}
