<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\BankFacility;
use App\Modules\Finance\Services\BankFacilityService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⛔ বকেয়া আসলসহ ঋণ বন্ধ করা যেত, আর বন্ধটা আবার বন্ধ করা যেত — Finance লাইন-নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (⚠️৩)।
 *
 * ⓘ [[BankFacilityService::close()]] তালা ছাড়া, অবস্থা না দেখে, দেনা না দেখে সারিটা "বন্ধ" লিখত। বন্ধ ঋণ সুদ জমা,
 * প্রতিষ্ঠানের পাতা আর কিস্তির তালিকা থেকে বাদ পড়ে — অথচ দায়টা ২২১১-এ থেকে যেত। বন্ধটা আবার বন্ধ করলে দিন আর নোট বদলাত।
 *
 * ⭐ দাবি:
 *   · খাতায় ১ লাখ বাকি এমন মেয়াদি ঋণ বন্ধ হয় না — কারণসহ, অবস্থা চালুই থাকে
 *   · শূন্য-বাকি ঋণ বন্ধ হয়; দ্বিতীয়বার থামে, বন্ধের দিন আর নোট বদলায় না
 *   · কাজটা সারিতে তালা দিয়ে
 */
final class ALoanWithMoneyStillOwedCouldBeClosedTest extends TestCase
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
    }

    public function test_a_loan_with_principal_still_owed_is_not_closed(): void
    {
        $this->post(route('finance.bank_facility.store'), $this->term([
            'already_running' => '1', 'opening_drawn' => '100000', 'instalments_paid' => 0,
        ]))->assertSessionHasNoErrors();
        $loan = BankFacility::query()->latest('id')->firstOrFail();
        $this->assertSame(0, bccomp(app(BankFacilityService::class)->standing(collect([$loan]))[$loan->id]['used'], '100000', 4),
            'প্রস্তুতিটাই ভুল — খোলা বকেয়া খাতায় বসেনি।');

        $this->from(route('finance.bank_facility.show', $loan))
            ->post(route('finance.bank_facility.close', $loan), ['note' => 'paid?'])
            ->assertSessionHasErrors('facility');

        $this->assertSame(DocumentStatus::CONFIRMED, (string) $loan->fresh()->status, '⛔ ১ লাখ বাকি থাকতেই ঋণটা বন্ধ হয়ে গেল।');
        $this->assertNull($loan->fresh()->closed_on);
    }

    public function test_a_closed_loan_is_not_closed_again_and_the_close_locks_the_row(): void
    {
        $this->post(route('finance.bank_facility.store'), $this->term())->assertSessionHasNoErrors();
        $loan = BankFacility::query()->latest('id')->firstOrFail();

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = strtolower($query->sql);
        });

        $this->post(route('finance.bank_facility.close', $loan), ['note' => 'repaid'])->assertSessionHasNoErrors();
        $this->assertSame(DocumentStatus::CLOSED, (string) $loan->fresh()->status, 'শূন্য-বাকি ঋণ বন্ধ হলো না।');
        $this->assertNotEmpty(array_filter($queries, fn ($sql) => str_contains($sql, 'from `fin_bank_facilities`') && str_contains($sql, 'for update')),
            '⛔ বন্ধ করার সময় সুবিধার সারিতে তালা নেই — দুই চাপ একসাথে পার হয়।');

        $loan->fresh()->forceFill(['closed_on' => '2026-01-31'])->save();

        $this->from(route('finance.bank_facility.show', $loan))
            ->post(route('finance.bank_facility.close', $loan), ['note' => 'again'])
            ->assertSessionHasErrors('facility');

        $this->assertSame('2026-01-31', $loan->fresh()->closed_on?->toDateString(), '⛔ বন্ধ ঋণ আবার বন্ধ হলো — বন্ধের দিন বদলে গেল।');
        $this->assertSame('repaid', $loan->fresh()->note, '⛔ বন্ধ ঋণ আবার বন্ধ হলো — নোট বদলে গেল।');
    }

    /**
     * @param  array<string, mixed>  $with
     * @return array<string, mixed>
     */
    private function term(array $with = []): array
    {
        return array_merge([
            'kind' => BankFacility::TERM, 'institution_new' => 'Sonali Bank', 'sanctioned_on' => now()->toDateString(),
            'limit_amount' => '1200000', 'interest_rate' => '10', 'instalments' => 12, 'instalment_amount' => '110000',
            'liability_account_id' => Account::query()->where('code', '2211')->value('id'),
        ], $with);
    }
}
