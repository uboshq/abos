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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ পাকা হয়ে যাওয়া ভাউচারের সারি পুরনো পর্দা থেকে বদলায় না — Accounts লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬।
 *
 * ⓘ [[VoucherService::update()]] "খসড়া কি না" দেখত হাতের কপিতে। একজন খসড়া খুলে রাখলেন, অন্যজন সেটা পাকা করলেন; প্রথমজনের
 * সংরক্ষণ তারপরও সারি মুছে নতুন অঙ্ক বসাত — খাতায় ১,০০০, কাগজে ৫,০০০, কোনো উল্টো দাখিলা ছাড়া।
 */
final class APostedVoucherKeepsItsLinesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_stale_draft_copy_cannot_rewrite_a_voucher_that_was_posted_meanwhile(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $expense = (int) Account::query()->where('code', '5202')->value('id');
        $payable = StandardChart::find(StandardChart::SALARY_PAYABLE)->id;
        $vouchers = app(VoucherService::class);

        $draft = $vouchers->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'খসড়া'], [
            ['account_id' => $expense, 'debit' => '1000'],
            ['account_id' => $payable, 'credit' => '1000'],
        ]);

        // ⓘ দুই পর্দায় একই খসড়া — একজন পাকা করলেন
        $stale = Voucher::query()->findOrFail($draft->id);
        $vouchers->post(Voucher::query()->findOrFail($draft->id));

        try {
            $vouchers->update($stale, ['narration' => 'বদলানো'], [
                ['account_id' => $expense, 'debit' => '5000'],
                ['account_id' => $payable, 'credit' => '5000'],
            ]);
            $this->fail('⛔ পাকা ভাউচারের সারি পুরনো পর্দা থেকে বদলে গেল — খাতা আর কাগজ দুই কথা');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(0, bccomp((string) $draft->fresh()->lines()->sum('debit'), '1000', 4), '⛔ পাকা ভাউচারের সারি বদলেছে');
    }
}
