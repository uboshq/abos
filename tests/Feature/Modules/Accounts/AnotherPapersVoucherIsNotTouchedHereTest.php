<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ অন্য কাগজের ভাউচার Accounts-এর পর্দা থেকে বদলায় না, আলাদা পাকাও হয় না — অর্থের নিরীক্ষা, ১৩ অক্টোবর ২০২৬ (fe)।
 *
 * ⓘ জমা, হাতধার, ভাড়া, মূলধন, বীমা — প্রত্যেকে নিজের ভাউচার বানিয়ে নিজের সারিতে `voucher_id` রাখে। Accounts থেকে সেই ভাউচার
 * বদলালে বা হাতে পাকা করলে মূল কাগজ জানত না, আর তার নিজের সই এড়ানো যেত। এখন বার্তা বলে কোন মডিউলের পর্দায় যেতে হবে।
 */
final class AnotherPapersVoucherIsNotTouchedHereTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_hand_loans_voucher_is_refused_here_and_an_ordinary_one_is_not(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $vouchers = app(VoucherService::class);
        $lines = [
            ['account_id' => (int) Account::query()->where('code', '5202')->value('id'), 'debit' => '500'],
            ['account_id' => StandardChart::find(StandardChart::SALARY_PAYABLE)->id, 'credit' => '500'],
        ];

        $owned = $vouchers->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'হাতধারের ভাউচার'], $lines);

        // ⓘ অর্থ মডিউলের হাতধার-চলাচল এই ভাউচারকে নিজের বলে ধরে
        // ⓘ হাতধারের হিসাব সেবার পথে — `person_name` ঘর আর নেই, মানুষটা এখন মাস্টার তালিকা থেকে (জোড়া লাগানোর পরে, ১৪ অক্টোবর ২০২৬)
        $person = \App\Modules\MasterData\Models\Person::query()->firstOrCreate(
            ['company_id' => $company->id, 'name_en' => 'Rahim'],
            ['code' => 'P-RHM', 'name_bn' => 'রহিম', 'is_active' => true],
        );
        $account = app(\App\Modules\Finance\Services\HandLoanService::class)->open(['person_id' => $person->id])->id;
        DB::table('fin_hand_loan_movements')->insert(['company_id' => $company->id, 'account_id' => $account, 'direction' => 'out',
            'amount' => '500', 'moved_on' => now()->toDateString(), 'voucher_id' => $owned->id,
            'public_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);

        $message = __('accounts::revision.owned_elsewhere', ['no' => $owned->document_no, 'module' => __('accounts::revision.module_fin')]);

        foreach ([
            'হাতে পাকা' => fn () => $vouchers->post($owned->fresh(), byHand: true),
            'হাতে সম্পাদনা' => fn () => $vouchers->update($owned->fresh(), ['narration' => 'বদল'], $lines, byHand: true),
        ] as $what => $act) {
            try {
                $act();
                $this->fail("⛔ অর্থ মডিউলের ভাউচার Accounts থেকে {$what} হল — মূল কাগজ আর তার সই এড়িয়ে");
            } catch (ValidationException $e) {
                $this->assertSame($message, $e->errors()['status'][0] ?? null);
            }
        }

        $this->assertFalse($owned->fresh()->isPosted());
        $this->assertSame($message, $vouchers->whyNotRevisable($owned->fresh()), '⛔ পরে সংশোধনের পথও খোলা');

        // ⓘ নিজের মডিউলের পথ (byHand ছাড়া) আগের মতোই চলে
        $this->assertTrue($vouchers->post($owned->fresh())->isPosted());

        // ⓘ কারো নয় এমন ভাউচার আগের মতোই
        $plain = $vouchers->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'সাধারণ'], $lines);
        $this->assertNull($vouchers->whyNotRevisable($plain->fresh()), 'সাধারণ ভাউচারও অন্যের বলে ধরা হল');
    }
}
