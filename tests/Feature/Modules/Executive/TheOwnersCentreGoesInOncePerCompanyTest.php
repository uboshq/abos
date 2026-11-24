<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Executive;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Executive\Services\Alerts;
use App\Modules\Executive\Services\Board;
use App\Modules\Executive\Services\CompanyLens;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\Collection;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ মালিকের কেন্দ্র এক কোম্পানিতে একবার ঢোকে (২৪ নভেম্বর ২০২৬) — আর সংখ্যা একটাও বদলায় না।
 *
 * দাবি: আট সতর্কতায় কোম্পানিপ্রতি চাবির তালিকা একবারই পড়া হয়; সতর্কতার প্রতিটা গোনা একা-গোনার সমান, ক্যাশ থেকেও; দিন ধরে আদায় আর
 * নিট বিক্রি প্রতিটা দিনে আগের দিন-ধরে-ধরে গোনার হুবহু সমান, খালি দিনসহ; "এখনই নতুন করে" চাপলে ক্যাশ বাসি হয়।
 */
final class TheOwnersCentreGoesInOncePerCompanyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_eight_alerts_go_into_a_company_once_and_count_the_same(): void
    {
        $companies = app(CompanyLens::class)->companies($this->owner);
        $this->assertGreaterThan(1, count($companies), 'প্রস্তুতিটাই ভুল — এক কোম্পানিতে কিছু মাপা যায় না।');
        $alerts = app(Alerts::class);

        Cache::flush();
        $reads = 0;
        DB::listen(function ($q) use (&$reads): void {
            if (str_starts_with($q->sql, 'select * from `permissions`')) {
                $reads++;
            }
        });
        $all = $alerts->all($this->owner, $companies);

        $this->assertLessThanOrEqual(2 * count($companies), $reads, '⛔ এক কোম্পানিতে বারবার ঢোকা হলো।');
        $this->assertSame(Alerts::KINDS, array_column($all, 'kind'));

        $one = new \ReflectionMethod($alerts, 'countHere');
        foreach ($all as $row) {
            foreach ($row['companies'] as $part) {
                $alone = app(CompanyLens::class)->within($this->owner, $part['id'], $part['only'], fn () => $one->invoke($alerts, $this->owner, $row['kind']));
                $this->assertSame($alone, $part['count'], "⛔ {$row['kind']} গোনা বদলে গেছে।");
            }
        }

        $this->assertSame($all, $alerts->all($this->owner, $companies), '⛔ ক্যাশ থেকে আলাদা উত্তর।');
    }

    public function test_refresh_makes_the_alerts_counted_again(): void
    {
        $companies = array_slice(app(CompanyLens::class)->companies($this->owner), 0, 1);
        Cache::flush();
        $before = app(Alerts::class)->all($this->owner, $companies);
        $i = array_search(Alerts::FAILED_LOGIN, Alerts::KINDS, true);
        $this->assertNotNull($before[$i]['companies'][0]['count'], 'প্রস্তুতিটাই ভুল — মালিক লগইন দেখেন না।');

        $key = implode(':', ['executive-alert', 'v'.Board::version($this->owner), 'u'.$this->owner->id, 'c'.$companies[0]['id'], 'ball', Alerts::FAILED_LOGIN, Carbon::today()->toDateString()]);
        Cache::put($key, 777, 60);
        $this->assertSame(777, app(Alerts::class)->all($this->owner, $companies)[$i]['total'], 'প্রস্তুতিটাই ভুল — ক্যাশ পড়া হয়নি।');

        app(Board::class)->refresh($this->owner);
        $this->assertSame($before[$i]['total'], app(Alerts::class)->all($this->owner, $companies)[$i]['total']);
    }

    public function test_the_daily_lines_are_the_day_by_day_figures(): void
    {
        $to = Carbon::today();
        $from = $to->copy()->subDays(29);

        // ⓘ ডেমোতে কোনো আদায় নেই — দুই পথের দুটো করে নিজেরা
        app(StandardChart::class)->install();
        $customer = Customer::query()->firstOrFail();
        $bank = Account::query()->money()->postable()->active()->firstOrFail();
        foreach ([1, 2] as $n) {
            Collection::query()->create(['branch_id' => CompanyContext::branchId(), 'document_no' => 'COL-OC-'.$n, 'customer_id' => $customer->id,
                'account_id' => $bank->id, 'trx_date' => $to->toDateString(), 'amount' => (string) (100 * $n), 'status' => DocumentStatus::CONFIRMED]);
            $voucher = app(VoucherService::class)->create(['type' => Voucher::RECEIPT, 'trx_date' => $to->toDateString(), 'party_type' => 'customer',
                'party_id' => $customer->id, 'narration' => 'x'], app(VoucherService::class)->twoLineEntry(Voucher::RECEIPT,
                    (int) StandardChart::find(StandardChart::RECEIVABLE)->id, (int) $bank->id, (string) (1000 * $n), null, null));
            $voucher->forceFill(['status' => DocumentStatus::CONFIRMED])->saveQuietly();
        }

        // ⓘ দুই পথের আদায় আলাদা দিনে, আর একদিনে দুটোই
        $c = Collection::query()->withoutGlobalScopes()->where('company_id', CompanyContext::id())->orderBy('id')->limit(2)->pluck('id');
        $v = Voucher::query()->withoutGlobalScopes()->where('company_id', CompanyContext::id())->where('type', Voucher::RECEIPT)->where('party_type', 'customer')->orderBy('id')->limit(2)->pluck('id');
        foreach ($c as $i => $id) {
            DB::table((new Collection)->getTable())->where('id', $id)->update(['trx_date' => $to->copy()->subDays(3 + $i)->toDateString()]);
        }
        foreach ($v as $i => $id) {
            DB::table('vouchers')->where('id', $id)->update(['trx_date' => $to->copy()->subDays(3 + 2 * $i)->toDateString()]);
        }

        $money = SalesMetrics::collectionByDay($from->toDateString(), $to->toDateString());
        $sales = SalesMetrics::netByDay($from->toDateString(), $to->toDateString());
        $this->assertCount(30, $money);
        $this->assertNotSame(0, bccomp(array_reduce($money, fn ($s, $x) => bcadd($s, $x, 4), '0'), '0', 4), 'প্রস্তুতিটাই ভুল — ত্রিশ দিনে আদায় নেই।');

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $d = $day->toDateString();
            $this->assertSame(SalesMetrics::collectionTotal($d, $d), $money[$d], "⛔ আদায় {$d}");
            $this->assertSame(0, bccomp(SalesMetrics::net($d, $d), $sales[$d], 4), "⛔ নিট বিক্রি {$d}");
        }
    }
}
