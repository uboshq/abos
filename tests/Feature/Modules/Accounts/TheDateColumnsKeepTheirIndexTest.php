<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\Cheque;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\BalanceSheetService;
use App\Modules\Accounts\Services\PostingBacklog;
use App\Modules\Finance\Services\CashForecast;
use Closure;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * তারিখের কলাম সূচক হারায় না — অডিট §৫, ২৮ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কী ছিল ────────────────────────────────────────────────────────────
 * `whereDate('trx_date', '<=', $d)` লেখে `date(\`trx_date\`) <= ?` — কলামটা
 * একটা ফাংশনে মোড়া, তাই MySQL তার সূচক ব্যবহার করতে পারে না আর পুরো টেবিল
 * পড়ে। ⚠️ খাতা ছোট থাকতে কিছুই বোঝা যায় না; লাখ সারিতে স্থিতিপত্র বা
 * ভাউচারের তালিকা মিনিট নেয়। ⓘ কলামগুলো সবই DATE (যাচাই করা), তাই
 * `where('trx_date', '<=', 'Y-m-d')` হুবহু একই সারি দেয় — কেবল সূচক বাঁচে।
 *
 * ⭐ প্রতিটা দাবি একটা আসল পথ চালায় আর তার SQL ধরে: কলামটা অন্তত একবার
 * জিজ্ঞাসিত হয়েছে (তাই দাবিটা অন্ধ নয়), আর কখনো `date(...)`-এ মোড়া নয়।
 */
class TheDateColumnsKeepTheirIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    public function test_the_voucher_list_filters_by_day_without_wrapping_the_column(): void
    {
        $this->assertKeepsIndex('trx_date', fn () => $this->get(route('accounts.voucher.index', [
            'type' => Voucher::JOURNAL,
            'from' => now()->subMonth()->toDateString(),
            'to' => now()->toDateString(),
        ]))->assertOk());
    }

    public function test_the_balance_sheet_reads_the_ledger_by_its_index(): void
    {
        $this->assertKeepsIndex('trx_date', fn () => app(BalanceSheetService::class)->build(now()->toDateString()));
    }

    public function test_the_cheques_that_are_due_are_found_by_their_index(): void
    {
        $this->assertKeepsIndex('cheque_date', fn () => Cheque::query()->ripe(now())->get());
    }

    public function test_the_cash_forecast_reads_due_dates_by_their_index(): void
    {
        $this->assertKeepsIndex('due_on', fn () => app(CashForecast::class)->build());
    }

    public function test_the_posting_backlog_counts_by_its_index(): void
    {
        $this->assertKeepsIndex('trx_date', fn () => app(PostingBacklog::class)->count());
    }

    /**
     * পথটা চালিয়ে SQL ধরা — কলামটা জিজ্ঞাসিত, আর কখনো `date()`-এ মোড়া নয়।
     */
    private function assertKeepsIndex(string $column, Closure $run): void
    {
        $sql = [];
        DB::listen(function ($query) use (&$sql): void {
            $sql[] = strtolower($query->sql);
        });

        $run();

        $asked = array_filter($sql, fn (string $q) => str_contains($q, '`'.$column.'`'));
        $this->assertNotEmpty($asked, "প্রস্তুতিটাই ভুল — পথটা `{$column}` একবারও জিজ্ঞেস করেনি, তাই \"মোড়া নেই\" অন্ধ হত।");

        $wrapped = array_values(array_filter($asked,
            fn (string $q) => preg_match('/date\(\s*`?[a-z_]*`?\.?`?'.preg_quote($column, '/').'`?\s*\)/', $q) === 1));

        $this->assertSame([], $wrapped, "⛔ `{$column}` আবার `date(...)`-এ মোড়া — সূচক কাজ করে না:\n".implode("\n", $wrapped));
    }
}
