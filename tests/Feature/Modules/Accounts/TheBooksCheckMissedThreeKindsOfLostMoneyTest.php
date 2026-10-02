<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Posting\PostingEngine;
use App\Core\Integrity\IntegrityFinding;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Integrity\AccountsChecks;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Accounts\Services\VoucherService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * খাতার যাচাই তিন রকম হারানো টাকা দেখত না — abos-63-এর তালিকা (abos-bb-র নিরীক্ষার বাকি), ২ অক্টোবর ২০২৬।
 *
 * ⛔ [[AccountsChecks]]: (১) মুছে ফেলা খাতের সারি অনাথ ধরা হত না — কেবল না-থাকা খাত; (২) কাগজ মুছে গেছে অথচ দাখিলা
 * খাতায় — কোনো যাচাই ছিল না; (৩) একশোর পরে চুপ — ৫,০০০ আর ১০০ একই রকম দেখাত।
 * ⭐ এখন তিনটাই ধরা পড়ে, আর একশোর বেশি হলে "আরও কয়টা" এক সারিতে।
 */
final class TheBooksCheckMissedThreeKindsOfLostMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Account $cash;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();
        $this->cash = app(CashTillService::class)->ensurePrimaryTill()->account;
    }

    public function test_entries_on_a_deleted_account_are_orphans_and_the_rest_are_counted_past_a_hundred(): void
    {
        $head = Account::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => '5999',
            'name_en' => 'Soon Deleted',
            'name_bn' => 'Soon Deleted',
            'parent_id' => StandardChart::find(StandardChart::OPERATING_EXPENSES)?->id,
            'type' => Account::EXPENSE,
            'nature' => Account::DEBIT,
            'is_active' => true,
        ]);

        foreach (range(1, 101) as $n) {
            app(PostingEngine::class)->post(sourceType: 'test:orphan', sourceId: $n, trxDate: now()->toDateString(), lines: [
                ['account_id' => $head->id, 'debit' => '10'],
                ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'credit' => '10'],
            ]);
        }

        DB::table('accounts')->where('id', $head->id)->update(['deleted_at' => now()]);

        $found = AccountsChecks::everyEntryHasAnAccount()->run();

        $this->assertCount(101, $found, '⛔ মুছে ফেলা খাতের ১০১টা সারির ১০০টা আর "আরও"-র সারি আসার কথা।');
        $this->assertStringContainsString('1', end($found)->detail, '⛔ একশোর পরের সারিগুলোর সংখ্যা বলা হয়নি।');
        $this->assertSame(__('accounts::integrity.and_more_what'), end($found)->what);
    }

    public function test_entries_whose_paper_was_deleted_are_found_and_a_reversed_one_is_not(): void
    {
        $gone = $this->postedJournal('GONE');
        $reversed = $this->postedJournal('REVERSED');
        app(VoucherService::class)->cancel($reversed, 'যাচাইয়ের জন্য বাতিল');

        DB::table('vouchers')->whereIn('id', [$gone->id, $reversed->id])->update(['deleted_at' => now()]);

        $found = collect(AccountsChecks::everyEntryHasItsPaper()->run());
        $ids = $found->map(fn (IntegrityFinding $f) => $f->sourceId)->all();

        $this->assertContains($gone->id, $ids, '⛔ মুছে যাওয়া কাগজের দাখিলা খাতায় রয়ে গেছে, অথচ যাচাই চুপ।');
        $this->assertNotContains($reversed->id, $ids, 'বাতিল করে উল্টানো কাগজও ধরা হলো — ওর দাখিলা মিলে শূন্য।');
    }

    private function postedJournal(string $tag): Voucher
    {
        $vouchers = app(VoucherService::class);
        $voucher = $vouchers->create(
            ['type' => Voucher::JOURNAL, 'trx_date' => now()->toDateString(), 'narration' => $tag],
            [
                ['account_id' => StandardChart::find(StandardChart::RENT)->id, 'debit' => '100', 'credit' => '0'],
                ['account_id' => StandardChart::find(StandardChart::OWNER_CAPITAL)->id, 'debit' => '0', 'credit' => '100'],
            ],
        );

        return $vouchers->post($voucher);
    }
}
