<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\RentalAccrual;
use App\Modules\Finance\Models\RentalContract;
use App\Modules\Finance\Services\RentalAccrualService;
use App\Modules\Finance\Services\RentalContractService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ এক চুক্তির ভুলে গোটা কোম্পানির মাসের ভাড়া বসানো থামত — পুরো-ERP পুনঃঅডিট, ৯ অক্টোবর ২০২৬ (সারাই ৯)।
 *
 * ⓘ একটা চুক্তির খাত ভুল (দলের খাত, বা মুছে যাওয়া) হলে পোস্টিং ব্যতিক্রম ছুড়ত, আর তার পরের সব চুক্তির মাস বসত না।
 *
 * ⭐ দাবি:
 *   · ভাঙা চুক্তি বাদে বাকি চুক্তির মাস বসে
 *   · ভাঙা চুক্তিটা নম্বর ধরে ফলাফলে আসে, আর পর্দার বোতামে ভুলের বার্তা হয়ে দেখায়
 */
final class OneBadContractStoppedTheMonthsRentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        app(CashTillService::class)->ensurePrimaryTill();
    }

    public function test_the_other_contracts_are_booked_and_the_broken_one_is_named(): void
    {
        $broken = $this->contract('Broken Landlord');
        $fine = $this->contract('Fine Landlord');

        // ⓘ খরচের খাত একটা দলের খাত — খাতা এমন খাতে কিছু নেয় না, তাই এই চুক্তির পোস্টিং ভাঙে
        $broken->forceFill(['expense_account_id' => Account::query()->where('is_group', true)->where('type', Account::EXPENSE)->value('id')])->save();

        $done = app(RentalAccrualService::class)->run(now()->startOfMonth());

        $this->assertSame(1, RentalAccrual::query()->where('rental_contract_id', $fine->id)->count(), '⛔ ভাঙা চুক্তির পরের চুক্তির মাস বসল না');
        $this->assertSame(0, RentalAccrual::query()->where('rental_contract_id', $broken->id)->count(), '⛔ ভাঙা চুক্তির আধা-বসা সারি রয়ে গেল');
        $this->assertSame(1, $done['accrued']);
        $this->assertCount(1, $done['failed']);
        $this->assertStringContainsString((string) $broken->document_no, $done['failed'][0], '⛔ কোন চুক্তি বসল না তা বলা নেই');
    }

    public function test_the_button_shows_which_contract_was_left_out(): void
    {
        $broken = $this->contract('Broken Landlord');
        $broken->forceFill(['expense_account_id' => Account::query()->where('is_group', true)->where('type', Account::EXPENSE)->value('id')])->save();

        $this->post(route('finance.rental.accrue'), ['month' => now()->format('Y-m')])
            ->assertRedirect()
            ->assertSessionHasErrors('month')
            ->assertSessionHas('saved');
    }

    private function contract(string $who): RentalContract
    {
        return app(RentalContractService::class)->open([
            'counterparty' => $who, 'subject' => $who.' shop', 'deposit_amount' => '0', 'monthly_rent' => '10000',
            'monthly_adjustment' => '0', 'term_months' => 12, 'starts_on' => now()->startOfMonth()->toDateString(),
        ]);
    }
}
