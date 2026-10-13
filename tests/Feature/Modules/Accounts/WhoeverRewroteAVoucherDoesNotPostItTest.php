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
use App\Modules\Accounts\Services\VoucherWriter;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⛔ লেখক ≠ পাকাকারী — যিনি অন্যের খসড়া বদলালেন তিনিও লেখক (Accounts লাইন-ধরে নিরীক্ষা, ১৩ অক্টোবর ২০২৬)।
 *
 * ⓘ আগে [[VoucherService::writerMayNotPost()]] কেবল `created_by` দেখত: ক একটা খসড়া লিখলেন ১,০০০-এর, খ খুলে ৯০,০০০ করে একই
 * অনুরোধে পাকা করলেন — শেষ কথা লিখলেন আর পাকাও করলেন একজনই, অনুমোদন বন্ধ কোম্পানিতে (UB) আর কিছুই থামাত না।
 */
final class WhoeverRewroteAVoucherDoesNotPostItTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_one_who_changed_the_lines_cannot_post_them(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $maker = $this->staff();
        $editor = $this->staff();
        $checker = $this->staff();
        $expense = (int) Account::query()->where('code', '5202')->value('id');
        $payable = StandardChart::find(StandardChart::SALARY_PAYABLE)->id;

        $this->actingAs($maker);
        $draft = app(VoucherService::class)->create(['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'খসড়া'], [
            ['account_id' => $expense, 'debit' => '1000'],
            ['account_id' => $payable, 'credit' => '1000'],
        ]);

        // ⓘ অন্যজন খুলে অঙ্ক বদলান আর একই অনুরোধে পাকা করতে চান
        $this->actingAs($editor);
        $result = app(VoucherWriter::class)->update($draft, ['narration' => 'বদলানো'], [
            ['account_id' => $expense, 'debit' => '90000'],
            ['account_id' => $payable, 'credit' => '90000'],
        ], false);

        $this->assertSame('checker', $result, '⛔ অন্যের খসড়া বদলে নিজেই পাকা করলেন');
        $this->assertFalse($draft->fresh()->isPosted());

        // ⓘ পরে আলাদা করে পাকা করতে গেলেও না
        try {
            app(VoucherService::class)->post($draft->fresh(), byHand: true);
            $this->fail('⛔ সারি বদলানো মানুষটাই পরে পাকা করলেন');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        // ⓘ তৃতীয় জন পারেন
        $this->actingAs($checker);
        $this->assertTrue(app(VoucherService::class)->post($draft->fresh(), byHand: true)->isPosted());
    }

    private function staff(): User
    {
        $user = User::factory()->create(['current_company_id' => CompanyContext::id()]);
        $user->companies()->attach(CompanyContext::id(), ['is_active' => true]);

        return $user;
    }
}
