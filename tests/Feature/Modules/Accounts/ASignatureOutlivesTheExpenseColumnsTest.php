<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Approval\DocumentFingerprint;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ⛔ খরচের নতুন ঘরগুলো (অংশ খ) মাইগ্রেশনের দিন সইয়ের অপেক্ষার কোনো ভাউচারের ছাপ বদলায় না (ec, ২৫ অক্টোবর ২০২৬)।
 *
 * ⓘ ছাপ নেওয়া হয় ডাটাবেজের সারি থেকে ([[DocumentFingerprint::asStored()]]); নতুন ১১টা ঘর ফাঁকা হয়েও ছাপে ঢুকলে লাইভে সইয়ের
 * অপেক্ষার প্রতিটা ভাউচারের সই "পুরনো" হত আর আবার চাওয়া হত, ADI-র পুরো সারিতে।
 *
 * দাবি:
 *   ১. ঘর ফাঁকা — ছাপ ঠিক মাইগ্রেশনের আগের মতো (`without()` দিয়ে ঘরগুলো না-থাকা ধরে নেওয়া ছাপের সমান)
 *   ২. মাইগ্রেশন যে ঘরগুলো যোগ করে, প্রতিটার জায়গা ঠিক করা — নতুন কোনো ঘর তালিকার বাইরে নয়
 *   ৩. সইয়ের পরে মুদ্রা বা বিলের তারিখ বসালে ছাপ বদলায় — সই আবার চাওয়া হয়
 *   ৪. বাজেটের হিসাব আর সতর্কতা (সার্ভারের ছবি) বসালে ছাপ বদলায় না
 */
final class ASignatureOutlivesTheExpenseColumnsTest extends TestCase
{
    use RefreshDatabase;

    private Voucher $voucher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $this->voucher = app(VoucherService::class)->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => 'সইয়ের অপেক্ষায় থাকা পুরনো ভাউচার'],
            [['account_id' => StandardChart::find(StandardChart::FUEL)->id, 'debit' => '900'],
                ['account_id' => StandardChart::find(StandardChart::PAYABLE)->id, 'credit' => '900']],
        );
    }

    /** ১ */
    public function test_with_the_new_columns_empty_the_fingerprint_is_the_one_from_before_the_migration(): void
    {
        $prints = app(DocumentFingerprint::class);
        $beforeMigration = $prints->without($this->voucher, $this->addedByTheMigration());

        $this->assertSame($beforeMigration, $prints->of($this->voucher->fresh()),
            '⛔ ফাঁকা নতুন ঘরও ছাপে ঢুকল — মাইগ্রেশনের দিন সইয়ের অপেক্ষার প্রতিটা ভাউচারের সই বাতিল হয়ে আবার চাওয়া হত');
    }

    /** ২ */
    public function test_every_column_the_migration_adds_has_its_place(): void
    {
        $this->assertSame([], array_values(array_diff($this->addedByTheMigration(), [...Voucher::EXPENSE_HEAD_FIELDS, ...Voucher::EXPENSE_SNAPSHOTS])),
            '⛔ মাইগ্রেশনের কোনো ঘর ছাপের তালিকায় নেই — ফাঁকা অবস্থায়ও ছাপ বদলাবে');
        foreach ($this->addedByTheMigration() as $column) {
            $this->assertTrue(Schema::hasColumn('vouchers', $column), "প্রস্তুতিটাই ভুল — vouchers-এ {$column} নেই");
        }
    }

    /** ৩ */
    public function test_a_currency_or_bill_date_set_after_signing_asks_for_the_signature_again(): void
    {
        $prints = app(DocumentFingerprint::class);
        $signed = $prints->of($this->voucher->fresh());

        DB::table('vouchers')->where('id', $this->voucher->id)->update(['bill_date' => '2026-10-20']);
        $this->assertNotSame($signed, $prints->of($this->voucher->fresh()), '⛔ সইয়ের পরে বিলের তারিখ বসল, অথচ ছাপ একই — সইকারী যা দেখেননি তাতে সই থাকল');

        $currency = DB::table('mdm_currencies')->where('company_id', $this->voucher->company_id)->where('code', 'USD')->value('id')
            ?? DB::table('mdm_currencies')->insertGetId([
                'public_id' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $this->voucher->company_id,
                'code' => 'USD', 'name_en' => 'US Dollar', 'symbol' => '$', 'created_at' => now(), 'updated_at' => now(),
            ]);
        DB::table('vouchers')->where('id', $this->voucher->id)->update(['bill_date' => null, 'currency_id' => $currency]);
        $this->assertNotSame($signed, $prints->of($this->voucher->fresh()), '⛔ সইয়ের পরে মুদ্রা বসল, অথচ ছাপ একই — সই আবার চাওয়া হয় না');
    }

    /** ৪ */
    public function test_the_servers_budget_check_and_warnings_never_move_the_fingerprint(): void
    {
        $prints = app(DocumentFingerprint::class);
        $signed = $prints->of($this->voucher->fresh());

        DB::table('vouchers')->where('id', $this->voucher->id)->update([
            'budget_check' => json_encode(['head' => 5216, 'left' => '100.0000']), 'warnings' => json_encode(['over_budget']),
        ]);

        $this->assertSame($signed, $prints->of($this->voucher->fresh()),
            '⛔ সার্ভারের লেখা বাজেটের হিসাব বা সতর্কতা ছাপ বদলাল — বাজেটের সই কখনো আর ভাউচারকে ঢাকত না');
    }

    /** @return list<string> */
    private function addedByTheMigration(): array
    {
        return ['bill_date', 'due_date', 'employee_id', 'currency_id', 'fx_rate', 'fx_date', 'fx_rate_reason', 'budget_reason',
            'budget_check', 'warnings', 'template_id'];
    }
}
