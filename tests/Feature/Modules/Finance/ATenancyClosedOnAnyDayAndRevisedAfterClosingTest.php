<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Tenancy;
use App\Modules\Finance\Services\TenancyService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ ভাড়াটের চুক্তি যেকোনো দিনে শেষ করা যেত, আর শেষ হওয়ার পরেও দর বদলানো যেত — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (ⓘ৯)।
 *
 * ⓘ আগামী বছর বা শুরুর আগের দিন "শেষ" লেখা যেত; শেষ চুক্তির দর বদল চুপচাপ বসত। ⭐ এখন শেষের দিন শুরু থেকে আজ পর্যন্ত
 * (ভাড়ার চুক্তির একই নিয়ম), আর দর বদল কেবল চালু চুক্তিতে, সারিতে তালা দিয়ে।
 */
final class ATenancyClosedOnAnyDayAndRevisedAfterClosingTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();

        Carbon::setTestNow(now()->startOfMonth()->addDays(9));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_closing_day_falls_between_the_start_and_today_and_a_closed_tenancy_keeps_its_terms(): void
    {
        $tenancy = $this->open('Closing Tenant');

        foreach ([now()->addDay(), $tenancy->starts_on->copy()->subDay()] as $day) {
            $this->assertRefused(fn () => app(TenancyService::class)->close($tenancy->fresh(), ['closed_on' => $day->toDateString()]),
                'closed_on', "⛔ {$day->toDateString()}-এ ভাড়াটের চুক্তি শেষ করা গেল");
            $this->assertSame(Tenancy::ACTIVE, $tenancy->fresh()->status, '⛔ ফেরানো শেষ তবু চুক্তি থামাল');
        }

        app(TenancyService::class)->close($tenancy->fresh(), ['closed_on' => now()->toDateString()]);
        $this->assertSame(Tenancy::CLOSED, $tenancy->fresh()->status, 'আজকের শেষও আটকে গেল — যাচাই বেশি চেপেছে');

        $this->assertRefused(fn () => app(TenancyService::class)->revise($tenancy->fresh(), ['monthly_rent' => '99000']),
            'status', '⛔ শেষ হওয়া চুক্তির দর বদলানো গেল');
        $this->assertSame(0, bccomp('10000', (string) $tenancy->fresh()->monthly_rent, 2), '⛔ শেষ হওয়া চুক্তির দর বদলে গেল');
    }

    private function open(string $name): Tenancy
    {
        $person = Person::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'name_en' => $name],
            ['code' => 'P-'.mb_substr(md5($name), 0, 6), 'name_bn' => $name, 'is_active' => true],
        );

        return app(TenancyService::class)->open([
            'party_type' => 'person', 'party_id' => $person->id, 'tenant' => $name, 'monthly_rent' => '10000',
            'deposit_amount' => '0', 'term_months' => 12, 'starts_on' => now()->startOfMonth()->subMonth()->toDateString(),
        ]);
    }

    private function assertRefused(callable $what, string $field, string $why): void
    {
        try {
            $what();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors(), $why.' (অন্য ঘরে আটকেছে: '.implode(', ', array_keys($e->errors())).')');

            return;
        }

        $this->fail($why);
    }
}
