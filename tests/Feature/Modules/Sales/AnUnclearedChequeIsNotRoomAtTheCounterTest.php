<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Services\CreditExposure;
use App\Modules\Sales\Services\OrderStanding;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ ক্লিয়ার না হওয়া চেক কাউন্টারেও টাকা নয় — বাকি ও আদায়, ৫ অক্টোবর ২০২৬ ([[CreditExposure::assertRoom()]])।
 *
 * সীমা ১০,০০০, বকেয়া ৪,০০০; হাতে আসার দিনেই জমায় বসা ২,০০০-এর চেক এখনো ক্লিয়ার নয় (খাতায় বকেয়া ২,০০০)।
 * DO-র যাচাই ([[check()]]) আগে থেকেই বলত জায়গা ৬,০০০; কাউন্টার/চালান/বিলের দেয়াল বলত ৮,০০০। এখন দুটোই ৬,০০০,
 * আর কাউন্টারের সারাংশও ([[OrderStanding]]) একই কথা বলে। চেক ক্লিয়ার হলে জায়গা ৮,০০০।
 */
final class AnUnclearedChequeIsNotRoomAtTheCounterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->customer = Customer::query()->create([
            'code' => 'CHQ-WALL', 'name_en' => 'Cheque Wall', 'name_bn' => 'Cheque Wall', 'is_active' => true, 'credit_limit' => '10000',
        ]);
    }

    public function test_the_counter_wall_and_the_order_check_give_the_same_room(): void
    {
        $receivable = StandardChart::find(StandardChart::RECEIVABLE)->id;
        $party = ['party_type' => 'customer', 'party_id' => $this->customer->id];

        $this->postLines([['account_id' => $receivable, 'debit' => '4000', ...$party], ['account_id' => StandardChart::find(StandardChart::SALES)->id, 'credit' => '4000']]);

        $cheque = $this->cheque('2000');
        $this->postLines([
            ['account_id' => StandardChart::find(StandardChart::CHEQUES_IN_HAND)->id, 'debit' => '2000'],
            ['account_id' => $receivable, 'credit' => '2000', ...$party],
        ], 'cheque', $cheque);

        $credit = app(CreditExposure::class);

        // ⓘ একই অঙ্কে দুই পথ একমত
        $this->assertTrue($credit->check($this->customer->fresh(), '6000')['fits']);
        $credit->assertRoom($this->customer->fresh(), '6000');
        $this->assertFalse(app(OrderStanding::class)->for($this->customer->fresh(), '6000')['over_limit']);

        $this->assertFalse($credit->check($this->customer->fresh(), '6001')['fits']);
        $this->assertTrue(app(OrderStanding::class)->for($this->customer->fresh(), '6001')['over_limit'], '⛔ সারাংশ চেকটাকে টাকা ধরেছে।');

        try {
            $credit->assertRoom($this->customer->fresh(), '6001');
            $this->fail('⛔ কাউন্টারের দেয়াল ক্লিয়ার না হওয়া চেককে টাকা ধরেছে — DO-র যাচাইয়ের চেয়ে ২,০০০ বেশি জায়গা।');
        } catch (ValidationException) {
            // ঠিক
        }

        DB::table('acc_cheques')->where('id', $cheque)->update(['status' => 'cleared']);

        $credit->assertRoom($this->customer->fresh(), '8000');
        $this->assertFalse(app(OrderStanding::class)->for($this->customer->fresh(), '8000')['over_limit']);
    }

    private function postLines(array $lines, string $source = 'test:chq-wall', ?int $sourceId = null): void
    {
        app(PostingEngine::class)->post(
            sourceType: $source, sourceId: $sourceId ?? random_int(1, 9_999_999), trxDate: now()->toDateString(), lines: $lines,
            branchId: $this->company->defaultBranch()?->id,
        );
    }

    private function cheque(string $amount): int
    {
        return (int) DB::table('acc_cheques')->insertGetId([
            'public_id' => (string) Str::uuid7(),
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'direction' => 'received',
            'cheque_date' => now()->toDateString(),
            'received_on' => now()->toDateString(),
            'cheque_no' => 'CW-'.random_int(1000, 9999),
            'amount' => $amount,
            'party_type' => 'customer',
            'party_id' => $this->customer->id,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
