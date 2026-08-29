<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Services\CashCountService;
use App\Modules\Accounts\Services\CashTillService;
use App\Modules\Accounts\Services\OpeningBalanceService;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * নগদ গণনা — হাতে যা আছে, খাতায় যা থাকার কথা।
 *
 * মিললে কিছুই হয় না। না মিললে পার্থক্যটা হিসাবে বসে, কারণ খাতার
 * সংখ্যাটা তখন মিথ্যা — আর মিথ্যা রেখে দিলে পরদিনের গণনাও মিলবে না,
 * আর কোন দিনের ভুল তা আর বলা যাবে না।
 */
class CashCountTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private CashTill $till;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->user = User::query()->where('email', 'owner@abos.test')->firstOrFail();

        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs($this->user);

        app(StandardChart::class)->install();

        $this->till = app(CashTillService::class)->ensurePrimaryTill();

        // খাতায় ১০,০০০
        Account::query()->whereKey($this->till->account_id)->update([
            'opening_balance' => '10000',
            'opening_date' => '2026-07-01',
        ]);

        /*
         * ── কলামে বসিয়েই থামা যায় না, ৩০ আগস্ট ২০২৬ ──────────────────
         * আগে `Account::balanceOn()` কলামটা কোডে যোগ করত, তাই কেবল
         * `update()` করলেই খাতে টাকা দেখাত। কিন্তু রিপোর্টগুলো খতিয়ান
         * পড়ে — তারা ওই টাকাটা কোনোদিন দেখত না, আর সেটাই HP-র ধরা বাগ
         * ([[OneNumberOneSourceTest]])।
         *
         * এখন জেরটা সত্যিকারের দাখিলা, তাই পরীক্ষাটাও সেই পথেই যায় —
         * ঠিক যেভাবে পুরনো সারিগুলোর জন্য ডিপ্লয়ের কমান্ডটা যায়।
         */
        app(OpeningBalanceService::class)->forAccount(
            Account::query()->findOrFail($this->till->account_id),
        );
    }

    private function service(): CashCountService
    {
        return app(CashCountService::class);
    }

    /**
     * count() নামটা ব্যবহার করা যায় না — PHPUnit-এর TestCase::count()
     * final, আর একই ভুল আগেও হয়েছিল post() দিয়ে।
     *
     * @param  array<int, int|null>  $counts
     */
    private function recordCount(array $counts): CashCount
    {
        return $this->service()->record(
            ['cash_till_id' => $this->till->id, 'trx_date' => '2026-08-10'],
            $counts,
        );
    }

    // ── নোটের হিসাব ────────────────────────────────────────────────────

    public function test_the_total_comes_from_the_notes_not_from_a_typed_figure(): void
    {
        // ৫×১০০০ + ৮×৫০০ + ১০×১০০ = ১০,০০০
        $count = $this->recordCount([1000 => 5, 500 => 8, 100 => 10]);

        $this->assertSame('10000.0000', $count->counted_amount);
        $this->assertSame([1000 => 5, 500 => 8, 100 => 10], $count->denominations);
    }

    public function test_zero_and_blank_note_rows_are_dropped(): void
    {
        $count = $this->recordCount([1000 => 5, 500 => 0, 200 => null, 100 => 10]);

        // শূন্য সংখ্যার সারি রাখলে ছাপা কাগজে অর্থহীন লাইন জমত
        $this->assertSame([1000 => 5, 100 => 10], $count->denominations);
    }

    public function test_every_bangladeshi_note_is_offered(): void
    {
        // ১, ২ ও ৫ টাকার কয়েনও — খুচরা দোকানে ওগুলোই বেশি জমে
        $this->assertSame([1000, 500, 200, 100, 50, 20, 10, 5, 2, 1], CashCount::DENOMINATIONS);
    }

    // ── মিলল কি মিলল না ────────────────────────────────────────────────

    public function test_a_matching_count_needs_no_adjustment(): void
    {
        $count = $this->recordCount([1000 => 10]);

        $this->assertTrue($count->matches());

        $approved = $this->service()->approve($count);

        $this->assertNull($approved->adjustment_voucher_id);
        $this->assertTrue($approved->isApproved());
    }

    public function test_a_shortage_posts_the_difference_as_an_expense(): void
    {
        // খাতায় ১০,০০০, হাতে ৯,৫০০ — ৫০০ কম
        $count = $this->recordCount([1000 => 9, 500 => 1]);

        $this->assertSame('-500.0000', $count->difference);
        $this->assertFalse($count->isSurplus());

        $approved = $this->service()->approve($count);

        $this->assertNotNull($approved->adjustment_voucher_id);

        // নগদের খাত ৫০০ কমেছে — খাতার সংখ্যাটা এখন সত্যি
        $this->assertSame('9500.0000', $this->till->fresh()->balance());

        // আর টাকাটা খরচে বসেছে, হাওয়ায় মেলায়নি
        $expense = StandardChart::find('5299');
        $this->assertSame('500.0000', $expense->fresh()->balanceOn());
    }

    public function test_a_surplus_posts_the_difference_as_other_income(): void
    {
        // খাতায় ১০,০০০, হাতে ১০,২০০
        $count = $this->recordCount([1000 => 10, 200 => 1]);

        $this->assertSame('200.0000', $count->difference);
        $this->assertTrue($count->isSurplus());

        $this->service()->approve($count);

        $this->assertSame('10200.0000', $this->till->fresh()->balance());
        $this->assertSame('200.0000', StandardChart::find('4300')->fresh()->balanceOn());
    }

    public function test_the_adjustment_journal_is_reachable_from_the_count(): void
    {
        $count = $this->service()->approve($this->recordCount([1000 => 9]));

        // নিয়ম ১ — "পার্থক্যের টাকাটা কোথায় বসল" এক ক্লিক দূরে
        $this->assertNotNull($count->adjustment);
        $this->assertStringStartsWith('JRN-', $count->adjustment->document_no);
        $this->assertTrue($count->adjustment->isPosted());
    }

    public function test_a_count_cannot_be_approved_twice(): void
    {
        $count = $this->service()->approve($this->recordCount([1000 => 9]));

        $this->expectException(ValidationException::class);

        $this->service()->approve($count);
    }

    /**
     * খাতার সংখ্যাটা গণনার তারিখ পর্যন্ত, আজ পর্যন্ত নয়।
     *
     * পুরনো তারিখের গণনা লিখলে আজকের ব্যালেন্সের সাথে মেলানোটা
     * অর্থহীন হত — মাঝের দিনগুলোর লেনদেন সব গোনা হয়ে যেত।
     */
    public function test_the_book_figure_is_taken_as_at_the_count_date(): void
    {
        // গণনার তারিখের পরে একটা লেনদেন — এটা গোনা হওয়ার কথা নয়
        LedgerEntry::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->till->branch_id,
            'financial_year_id' => $this->company->currentFinancialYear()?->id,
            'account_id' => $this->till->account_id,
            'trx_date' => '2026-08-15',
            'debit' => '3000',
            'credit' => '0',
            'source_type' => 'receipt_voucher',
            'source_id' => 1,
            'document_no' => 'RCV-LATER',
        ]);

        // ১০ আগস্টের গণনা — ১৫ তারিখের ৩,০০০ ধরা হবে না
        $count = $this->recordCount([1000 => 10]);

        $this->assertSame('10000.0000', $count->expected_amount);
        $this->assertTrue($count->matches());

        // আর আজকের ব্যালেন্সে ওটা আছেই
        $this->assertSame('13000.0000', $this->till->fresh()->balance());
    }

    // ── স্ক্রিন ─────────────────────────────────────────────────────────

    public function test_the_form_never_shows_the_book_figure(): void
    {
        // দেখালে ক্যাশিয়ার ওই সংখ্যাটাই টাইপ করে দিত, আর গণনার পুরো
        // উদ্দেশ্যটাই হারাত
        $this->get(route('accounts.count.create'))
            ->assertOk()
            ->assertDontSee('10,000.00')
            ->assertDontSee(__('accounts::field.expected'), false);
    }

    public function test_counting_through_the_screen_works_end_to_end(): void
    {
        $this->post(route('accounts.count.store'), [
            'cash_till_id' => $this->till->id,
            'trx_date' => '2026-08-10',
            'counts' => [1000 => 9, 500 => 1],
        ])->assertRedirect();

        $count = CashCount::query()->latest('id')->firstOrFail();

        $this->assertSame('9500.0000', $count->counted_amount);
        $this->assertSame('10000.0000', $count->expected_amount);

        $this->get(route('accounts.count.show', $count))
            ->assertOk()
            ->assertSee('9,500.00')
            ->assertSee('10,000.00');
    }

    public function test_a_user_without_the_approve_permission_cannot_approve(): void
    {
        $count = $this->recordCount([1000 => 9]);

        $stranger = User::factory()->create();
        $stranger->companies()->attach($this->company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $this->company->id])->save();
        $stranger->givePermissionTo(
            Permission::findOrCreate('accounts.count.create', 'web')
        );

        $this->actingAs($stranger)
            ->post(route('accounts.count.approve', $count))
            ->assertForbidden();

        $this->assertFalse($count->fresh()->isApproved());
    }
}
