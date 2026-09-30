<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Services\InsuranceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * বিমার অঙ্ক প্রতিবার পড়ার সময় শেষের দুই ঘর হারাত — চূড়ান্ত অডিট ⛔১২, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল ─────────────────────────────────────────────────────────
 * কলামগুলো আগেই `decimal(18,4)`-এ বাড়ানো হয়েছিল, কিন্তু মডেলের cast রয়ে গিয়েছিল
 * `decimal:2`। cast কলামের মাপ মানে না — পড়ার মুহূর্তে জোর করে দুই ঘরে গোল করে। ফলে
 * ১২৩৪.৫৬৭৮ বসিয়ে পড়লে ১২৩৪.৫৭, আর সেটা আবার সেভ হলে ঘর দুটো চিরতরে হারাত। বাকি সব
 * টাকার ঘর চার ঘরে — তাই প্রিমিয়ামের ভাউচারে যোগ-বিয়োগ এক পয়সা নড়তে পারত।
 *
 * ⭐ এখন পলিসির অঙ্ক, প্রিমিয়াম আর প্রিমিয়ামের কিস্তি — তিনটাই চার ঘরে পড়ে।
 */
final class TheInsuranceLostItsLastTwoPaisaOnEveryReadTest extends TestCase
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

    public function test_a_policy_and_its_premium_keep_four_places(): void
    {
        $insurer = Institution::query()->create([
            'company_id' => CompanyContext::id(),
            'kind' => Institution::INSURANCE,
            'name_en' => 'Pragati Insurance',
        ]);

        $policy = app(InsuranceService::class)->create([
            'institution_id' => $insurer->id,
            'policy_no' => 'P-4DP',
            'covers' => InsurancePolicy::WAREHOUSE,
            'subject' => 'Netrakona godown',
            'sum_insured' => '1234567.8912',
            'premium' => '18500.4567',
            'starts_on' => now()->toDateString(),
            'ends_on' => now()->addYear()->subDay()->toDateString(),
        ])->fresh();

        $this->assertSame('1234567.8912', (string) $policy->sum_insured, '⛔ বিমার অঙ্ক পড়ার সময় দুই ঘরে গোল হলো।');
        $this->assertSame('18500.4567', (string) $policy->premium, '⛔ প্রিমিয়াম পড়ার সময় দুই ঘরে গোল হলো।');

        $premium = $policy->premiums()->firstOrFail();
        $this->assertSame('18500.4567', (string) $premium->amount, '⛔ প্রিমিয়ামের কিস্তি পড়ার সময় দুই ঘরে গোল হলো।');
    }
}
