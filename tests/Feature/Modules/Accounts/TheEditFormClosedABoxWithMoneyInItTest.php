<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\MoneyTransferService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\PutsMoneyInTheTill;
use Tests\TestCase;

/**
 * ম৮ (বন্ধ) — সম্পাদনার ফর্ম দিয়ে টাকাসহ বা পথে-টাকাসহ বাক্স বন্ধ হয় না (Accounts-Finance অডিট, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ "বন্ধ করুন" বোতাম টাকা দেখত, কিন্তু সম্পাদনার ফর্মে "চালু" টিক তুলে দিলে সেই পাহারা এড়িয়ে বাক্স বন্ধ হত — টাকা
 * খাতায় থাকত, পর্দায় আর দেখা যেত না।
 */
final class TheEditFormClosedABoxWithMoneyInItTest extends TestCase
{
    use PutsMoneyInTheTill;
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

    public function test_a_box_with_money_is_not_closed_by_the_edit_form(): void
    {
        $till = $this->till('M8 money');
        $this->putMoneyIn($till->account, '300');

        $this->from(route('accounts.till.edit', $till))
            ->put(route('accounts.till.update', $till), $this->form($till, active: false))
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($till->fresh()->is_active, '⛔ টাকাসহ বাক্স সম্পাদনার ফর্মে বন্ধ হলো।');
    }

    public function test_a_box_with_money_on_the_way_is_not_closed(): void
    {
        $from = $this->till('M8 from');
        $to = $this->till('M8 to');
        $this->putMoneyIn($from->account, '500');

        app(MoneyTransferService::class)->initiate([
            'from_till_id' => $from->id, 'to_till_id' => $to->id, 'amount' => '500', 'trx_date' => now()->toDateString(),
        ]);

        // ⓘ দুই বাক্সেরই জের এখন শূন্য — তবু টাকা পথে
        $this->assertRefused(fn () => app(CashTillService::class)->deactivate($to->fresh()), '⛔ গ্রহণের অপেক্ষায় থাকা বাক্স বন্ধ হলো।');
        $this->assertRefused(fn () => app(CashTillService::class)->deactivate($from->fresh()), '⛔ পাঠানো টাকা পথে থাকতেই দাতার বাক্স বন্ধ হলো।');
    }

    /** ⓘ খালি বাক্স আজকের মতোই বন্ধ হয় */
    public function test_an_empty_box_still_closes_from_the_edit_form(): void
    {
        $till = $this->till('M8 empty');

        $this->put(route('accounts.till.update', $till), $this->form($till, active: false))->assertSessionHasNoErrors();

        $this->assertFalse($till->fresh()->is_active);
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function till(string $name): CashTill
    {
        return app(CashTillService::class)->create(['name_en' => $name, 'holder_id' => $this->owner->id]);
    }

    /** @return array<string, mixed> */
    private function form(CashTill $till, bool $active): array
    {
        return array_filter([
            'code' => $till->code,
            'name_en' => $till->name_en,
            'holder_id' => $till->holder_id,
            'limit_amount' => '0',
            'is_active' => $active ? '1' : '0',
        ], fn ($v) => $v !== null);
    }

    private function assertRefused(\Closure $act, string $why): void
    {
        try {
            $act();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('is_active', $e->errors());

            return;
        }

        $this->fail($why);
    }
}
