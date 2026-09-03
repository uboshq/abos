<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\ImportRunner;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\LedgerEntry;
use App\Modules\Accounts\Services\AccountService;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * পুরনো খাতা থেকে হিসাবের ছক ও খোলার জের — নতুন কোম্পানির সবচেয়ে কষ্টের
 * দুই কাজ (প্ল্যান ধাপ ৩)।
 *
 * সবচেয়ে জরুরি পরীক্ষা: ইমপোর্ট করা খাত হাতে-বসানো খাতের মতোই নিয়ম মানে
 * (কোডের অনন্যতা, অভিভাবক-গ্রুপ), আর খোলার জের বসলে **খতিয়ান মেলে**
 * (Dr = Cr) — অর্ধেক বসা খতিয়ান নয়। খালি সংগ্রহ মিলিয়ে সবুজ দেখানোর ফাঁদ
 * এড়াতে সব assertion আসল গণনা ও মান ধরে।
 */
class AccountsImportTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);
    }

    private function runner(): ImportRunner
    {
        return app(ImportRunner::class);
    }

    private function csv(string $body): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'abos-import').'.csv';
        file_put_contents($path, $body);

        return new UploadedFile($path, 'import.csv', 'text/csv', null, true);
    }

    // ── মডিউল নিজে দুইটা ইমপোর্টার ঘোষণা করে ───────────────────────────

    public function test_accounts_module_declares_both_importers(): void
    {
        $available = $this->runner()->available();

        $this->assertArrayHasKey('chart_of_accounts', $available);
        $this->assertArrayHasKey('opening_balance', $available);
    }

    // ── হিসাবের ছক ─────────────────────────────────────────────────────

    public function test_chart_importer_creates_a_real_account(): void
    {
        $before = Account::query()->count();

        $result = $this->runner()->run('chart_of_accounts', $this->csv(
            "code,name_en,name_bn,parent,type,nature,is_group\n".
            "7777,Marketing Expense,বিপণন খরচ,,expense,debit,no\n"
        ));

        $this->assertSame(1, $result['imported']);
        $this->assertSame([], $result['failed']);

        $account = Account::query()->where('code', '7777')->first();
        $this->assertNotNull($account);
        $this->assertSame('Marketing Expense', $account->name_en);
        $this->assertSame('expense', $account->type);
        $this->assertSame('debit', $account->nature);
        $this->assertSame($before + 1, Account::query()->count());
    }

    public function test_chart_importer_rejects_a_duplicate_code(): void
    {
        // ১১০১ = হাতে নগদ, প্রমিত ছকে ইতিমধ্যেই আছে
        $result = $this->runner()->run('chart_of_accounts', $this->csv(
            "code,name_en,name_bn,parent,type,nature,is_group\n".
            StandardChart::CASH_IN_HAND.",Duplicate Cash,,,asset,debit,no\n"
        ));

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['failed']);
    }

    public function test_chart_importer_rejects_an_unknown_type(): void
    {
        $result = $this->runner()->run('chart_of_accounts', $this->csv(
            "code,name_en,name_bn,parent,type,nature,is_group\n".
            "7778,Bad Type,,,notatype,debit,no\n"
        ));

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['failed']);
        $this->assertNull(Account::query()->where('code', '7778')->first());
    }

    // ── খোলার জের ──────────────────────────────────────────────────────

    public function test_opening_balance_importer_posts_and_the_books_balance(): void
    {
        $account = app(AccountService::class)->create([
            'name_en' => 'Imported Cash', 'type' => 'asset', 'nature' => 'debit', 'is_group' => false,
        ]);

        // তৈরির সময় জের ০ ছিল, তাই এখনো কোনো opening দাখিলা নেই
        $this->assertFalse(app(OpeningBalanceService::class)->exists('account', $account->id));

        $result = $this->runner()->run('opening_balance', $this->csv(
            "account_code,opening_balance,opening_date\n{$account->code},5000,\n"
        ));

        $this->assertSame(1, $result['imported']);
        $this->assertSame([], $result['failed']);
        $this->assertTrue(app(OpeningBalanceService::class)->exists('account', $account->id));

        // খতিয়ান মেলে: এই দাখিলার ডেবিট = ক্রেডিট
        $entries = LedgerEntry::query()
            ->where('source_type', 'account'.OpeningBalanceService::SOURCE_SUFFIX)
            ->where('source_id', $account->id)
            ->get();

        $this->assertGreaterThanOrEqual(2, $entries->count());
        $this->assertSame(0, bccomp(
            (string) $entries->sum('debit'),
            (string) $entries->sum('credit'),
            4,
        ));
    }

    public function test_opening_balance_import_is_idempotent(): void
    {
        $account = app(AccountService::class)->create([
            'name_en' => 'Idempotent Cash', 'type' => 'asset', 'nature' => 'debit', 'is_group' => false,
        ]);
        $body = "account_code,opening_balance,opening_date\n{$account->code},5000,\n";

        $this->runner()->run('opening_balance', $this->csv($body));
        $first = LedgerEntry::query()
            ->where('source_type', 'account'.OpeningBalanceService::SOURCE_SUFFIX)
            ->where('source_id', $account->id)->count();

        // আবার চালালে দ্বিগুণ নয় — exists() গার্ড থামায়
        $result = $this->runner()->run('opening_balance', $this->csv($body));
        $second = LedgerEntry::query()
            ->where('source_type', 'account'.OpeningBalanceService::SOURCE_SUFFIX)
            ->where('source_id', $account->id)->count();

        $this->assertSame(1, $result['imported']);
        $this->assertSame($first, $second);
    }

    public function test_opening_balance_rejects_a_group_account(): void
    {
        $group = Account::query()->where('is_group', true)->firstOrFail();

        $result = $this->runner()->run('opening_balance', $this->csv(
            "account_code,opening_balance,opening_date\n{$group->code},5000,\n"
        ));

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['failed']);
    }

    public function test_opening_balance_rejects_an_unknown_account_code(): void
    {
        $result = $this->runner()->run('opening_balance', $this->csv(
            "account_code,opening_balance,opening_date\n9999999,5000,\n"
        ));

        $this->assertSame(0, $result['imported']);
        $this->assertCount(1, $result['failed']);
    }
}
