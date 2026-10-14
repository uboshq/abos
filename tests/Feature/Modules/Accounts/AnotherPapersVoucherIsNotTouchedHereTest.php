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

        // ⓘ অন্য একটা কাগজ (এখানে চেকের রেজিস্টার) এই ভাউচারকে নিজের বলে ধরে — অর্থের জমা, হাতধার, ভাড়া একইভাবে `voucher_id` রাখে
        $cheque = app(\App\Modules\Accounts\Services\ChequeService::class)->create([
            'direction' => \App\Modules\Accounts\Models\Cheque::RECEIVED, 'cheque_no' => 'OWN'.random_int(100000, 999999), 'bank_name' => 'Sonali Bank',
            'cheque_date' => now()->toDateString(), 'amount' => '500',
            'party_type' => 'customer', 'party_id' => \App\Modules\Customer\Models\Customer::query()->orderBy('id')->firstOrFail()->id,
        ]);
        DB::table('acc_cheques')->where('id', $cheque->id)->update(['voucher_id' => $owned->id]);

        $message = __('accounts::revision.owned_elsewhere', ['no' => $owned->document_no, 'module' => __('accounts::revision.module_acc')]);

        foreach ([
            'হাতে পাকা' => fn () => $vouchers->post($owned->fresh(), byHand: true),
            'হাতে সম্পাদনা' => fn () => $vouchers->update($owned->fresh(), ['narration' => 'বদল'], $lines, byHand: true),
        ] as $what => $act) {
            try {
                $act();
                $this->fail("⛔ অন্য কাগজের ভাউচার Accounts-এর পর্দা থেকে {$what} হল — মূল কাগজ আর তার সই এড়িয়ে");
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
