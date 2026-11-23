<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Contracts\PartyOpenBills;
use App\Core\Contracts\PayableOpenBills;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Models\CashCount;
use App\Modules\Accounts\Services\DayChecklist;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ দিন বন্ধের চেকলিস্টের তিন সারি — মালিক, ১৮ নভেম্বর ২০২৬: "আজকের যে বিলগুলো বাকিতে গেল", "আজ সরবরাহকারীর যে টাকা দেওয়ার কথা অথচ
 * দেওয়া হয়নি", আর "নগদ গোনায় তফাত" (আগে কেবল দিন বন্ধের সময় দেখা হত)।
 *
 * দাবি: (১) আজকের পাকা বিলে বিক্রয় মডিউল যে বাকি বলে, চেকলিস্টে সেই কয়টা আর সেই অঙ্ক; পুরো শোধ হলে সারি নেই;
 * (২) আজ শোধের দিনের সরবরাহকারীর বিল, ক্রয় মডিউল যেটাকে এখনো বাকি বলে — সারি আছে; অন্য দিনের শেষ তারিখের বিল নয়;
 * (৩) পাকা নগদ গোনায় তফাত থাকলে সারি, তফাত শূন্য হলে নয়; (৪) সবগুলো কেবল সতর্কতা — দিন বন্ধের পাতা খোলে, কিছু আটকায় না।
 */
final class TheDayChecklistNamesCreditBillsUnpaidSuppliersAndCountGapsTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-11-16';

    private Company $company;

    private Branch $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Carbon::setTestNow(self::DAY.' 18:00:00');

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->a = Branch::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('code', 'MMS')->firstOrFail();
        CompanyContext::set($this->company->id, null);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['locale' => 'en'])->save();
        $this->actingAs($owner->fresh());
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_todays_bills_on_credit_show_the_sales_modules_own_due(): void
    {
        $invoice = $this->paper('sal_invoices', ['trx_date' => self::DAY]);
        $old = $this->paper('sal_invoices', ['trx_date' => '2026-11-10'], [(int) $invoice->id]);

        $this->fakeOpen(PartyOpenBills::class, 'customer', [
            ['id' => (int) $invoice->id, 'outstanding' => '1500.0000'],
            ['id' => (int) $old->id, 'outstanding' => '900.0000'], // ⓘ পুরনো বিলের বাকি আজকের "বাকিতে গেল" নয়
        ]);

        $row = $this->row('credit_bills');
        $this->assertNotNull($row, '⛔ আজকের বাকির বিল চেকলিস্টে আসেনি');
        $this->assertSame(1, $row['count']);
        $this->assertSame(0, bccomp((string) $row['amount'], '1500', 2), '⛔ বাকির অঙ্ক বিক্রয় মডিউলের অঙ্ক নয়');
        $this->assertSame('info', $row['tone'], '⛔ বাকিতে বিক্রি দোষ নয় — তথ্যের রঙে');

        $this->fakeOpen(PartyOpenBills::class, 'customer', []);
        $this->assertNull($this->row('credit_bills'), '⛔ পুরো শোধ হওয়া বিলও "বাকিতে গেল" দেখাচ্ছে');
    }

    public function test_supplier_bills_due_today_and_still_owed_are_named(): void
    {
        $due = $this->paper('pur_bills', ['due_on' => self::DAY]);
        $later = $this->paper('pur_bills', ['due_on' => '2026-11-30'], [(int) $due->id]);

        $this->fakeOpen(PayableOpenBills::class, 'supplier', [
            ['id' => (int) $due->id, 'outstanding' => '4000.0000'],
            ['id' => (int) $later->id, 'outstanding' => '700.0000'],
        ]);

        $row = $this->row('supplier_due');
        $this->assertNotNull($row, '⛔ আজ শোধের দিনের বাকি বিল চেকলিস্টে আসেনি');
        $this->assertSame(1, $row['count'], '⛔ অন্য দিনের শেষ তারিখের বিলও গোনা হলো');
        $this->assertSame(0, bccomp((string) $row['amount'], '4000', 2));

        $this->fakeOpen(PayableOpenBills::class, 'supplier', []);
        $this->assertNull($this->row('supplier_due'), '⛔ দেওয়া হয়ে গেলেও "দেওয়া হয়নি" বলছে');
    }

    public function test_a_gap_in_a_posted_cash_count_is_on_the_list_and_a_clean_count_is_not(): void
    {
        $count = CashCount::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->a->id, 'document_no' => 'CC-T-1', 'trx_date' => self::DAY,
            'counted_amount' => '9500', 'expected_amount' => '10000', 'difference' => '-500', 'status' => DocumentStatus::CONFIRMED,
        ]);

        $row = $this->row('count_gap');
        $this->assertNotNull($row, '⛔ গোনায় তফাত চেকলিস্টে নেই — কেবল দিন বন্ধের সময় দেখা হচ্ছে');
        $this->assertSame(0, bccomp((string) $row['amount'], '-500', 2));
        $this->assertSame('danger', $row['tone']);

        $count->forceFill(['counted_amount' => '10000', 'difference' => '0'])->save();
        $this->assertNull($this->row('count_gap'), '⛔ মিলে যাওয়া গোনাকেও তফাত বলছে');
    }

    public function test_the_new_rows_never_block_the_day_closing_page(): void
    {
        CashCount::query()->forceCreate([
            'company_id' => $this->company->id, 'branch_id' => $this->a->id, 'document_no' => 'CC-T-2', 'trx_date' => self::DAY,
            'counted_amount' => '100', 'expected_amount' => '300', 'difference' => '-200', 'status' => DocumentStatus::CONFIRMED,
        ]);

        $page = $this->get(route('executive.dayclose', ['date' => self::DAY]));
        $page->assertOk();
        $page->assertSee('data-check="count_gap"', false);
        $page->assertSee('data-day-close-form', false);
    }

    /** @return array<string, mixed>|null */
    private function row(string $key): ?array
    {
        return collect(app(DayChecklist::class)->for(Carbon::parse(self::DAY)))->firstWhere('key', $key);
    }

    /**
     * ⓘ ডেমোর একটা কাগজকে এই দিনের পাকা কাগজ বানানো — বিল বানানোর পুরো পথ এই পরীক্ষার বিষয় নয়, বাকির অঙ্ক মডিউল নিজে বলে (নিচের নকল)।
     *
     * @param  array<string, mixed>  $set
     * @param  list<int>  $not
     */
    private function paper(string $table, array $set, array $not = []): object
    {
        $row = DB::table($table)->where('company_id', $this->company->id)->whereNull('deleted_at')
            ->whereNotIn('id', $not ?: [0])->orderBy('id')->first();
        $this->assertNotNull($row, "⛔ ডেমোতে {$table}-এর কাগজ নেই — পরীক্ষাটা কিছুই দেখছে না");

        DB::table($table)->where('id', $row->id)->update([...$set, 'status' => DocumentStatus::CONFIRMED, 'branch_id' => $this->a->id]);

        return DB::table($table)->where('id', $row->id)->first();
    }

    /**
     * @param  class-string  $contract
     * @param  list<array<string, mixed>>  $rows
     */
    private function fakeOpen(string $contract, string $side, array $rows): void
    {
        $this->app->instance($contract, new class($side, $rows) implements PartyOpenBills, PayableOpenBills
        {
            public function __construct(private string $side, private array $rows) {}

            public function openBills(string $partyType, int $partyId, int $limit = 50, bool $withNotes = false): array
            {
                return $partyType === $this->side ? $this->rows : [];
            }
        });
    }
}
