<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Tenancy;
use App\Modules\Finance\Models\TenancyCharge;
use App\Modules\Finance\Services\TenancyService;
use App\Modules\MasterData\Models\Person;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ⛔ এক ভাড়াটের ভুলে বাকি সব ভাড়াটের মাসের দাবি থামত — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (ⓘ৭)।
 *
 * ⓘ ভাড়ার মাসের জমা ([[RentalAccrualService::run()]]) ৯ অক্টোবর থেকে প্রতিটা চুক্তি নিজের লেনদেনে চালায়; ভাড়াটের দাবিতে সেটা
 * ছিল না — একটার খাত ভাঙা হলে পুরো চাল ব্যতিক্রমে থামত, পরের সবাই বাদ। ⭐ এখন বাকিরা বসে, ভাঙাটা নাম ধরে ফেরে, আর গোনা
 * লেনদেনের পরে।
 */
final class OneBadTenancyStoppedTheMonthsChargesTest extends TestCase
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

    public function test_the_other_tenants_are_charged_and_the_broken_one_is_named(): void
    {
        $broken = $this->open('Broken Tenant');
        $fine = $this->open('Fine Tenant');
        $this->breakIt($broken);

        $done = app(TenancyService::class)->charge(now()->startOfMonth());

        $this->assertSame(1, TenancyCharge::query()->where('tenancy_id', $fine->id)->count(), '⛔ ভাঙা ভাড়াটের পরের ভাড়াটের দাবি বসল না');
        $this->assertSame(0, TenancyCharge::query()->where('tenancy_id', $broken->id)->count(), '⛔ ভাঙা ভাড়াটের আধা-বসা সারি রয়ে গেল');
        $this->assertSame(1, $done['charged'], '⛔ বসেনি এমন দাবিও গোনা হলো');
        $this->assertCount(1, $done['failed']);
        $this->assertStringContainsString((string) $broken->document_no, $done['failed'][0], '⛔ কোন ভাড়াটের দাবি বসল না তা বলা নেই');
    }

    public function test_the_button_shows_which_tenant_was_left_out(): void
    {
        $this->breakIt($this->open('Broken Tenant'));

        $this->post(route('finance.tenancy.charge'), ['month' => now()->format('Y-m')])
            ->assertRedirect()
            ->assertSessionHasErrors('month')
            ->assertSessionHas('saved');
    }

    /** ⓘ আয়ের খাত একটা দলের খাত — খাতা এমন খাতে কিছু নেয় না, তাই এই ভাড়াটের পোস্টিং ভাঙে */
    private function breakIt(Tenancy $tenancy): void
    {
        $group = Account::query()->where('is_group', true)->where('type', Account::INCOME)->value('id');
        $this->assertNotNull($group, 'দৃশ্যটাই বানানো যায়নি — আয়ের দলের খাত নেই');
        $tenancy->forceFill(['income_account_id' => $group])->save();
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
}
