<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ক্রয়মূল্য ও মুনাফা যাঁর দেখার কথা নয়, তিনি দেখেন না।
 *
 * ── কেন এটা দরকার ───────────────────────────────────────────────────
 * "কে কত কিনছে" প্রশ্নটা বিক্রয়কর্মীর রোজকার কাজ, তাই রিপোর্টটা তাঁর
 * দরকার। কিন্তু ওই একই সারিতে কত লাভ হলো সেটা তাঁর জানার কথা নয় —
 * জানলে দরকষাকষিতে সেটাই ব্যবহার হয়, আর ক্রয়মূল্য প্রতিযোগীর কাছে
 * পৌঁছানোর সবচেয়ে সহজ পথ ওটাই।
 *
 * ── কেন তিন পথেই পরীক্ষা ────────────────────────────────────────────
 * একটা রিপোর্ট তিনভাবে বেরোয়: পর্দায়, রপ্তানিতে, ছাপায়। এক জায়গায়
 * ঢাকা আর অন্য জায়গায় খোলা থাকলে সেটা **আড়াল না থাকার চেয়ে খারাপ** —
 * কারণ পর্দা দেখে সবাই ধরে নেয় সংখ্যাটা ঢাকা আছে।
 */
class CostIsHiddenTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        // একটা নিশ্চিত বিল, যাতে রিপোর্টে সারি থাকে
        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '2', 'rate' => '100']],
        );

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(SalesInvoiceService::class)->confirm($invoice);

        $this->invoiceId = $invoice->id;
    }

    private int $invoiceId;

    /**
     * বিক্রয়কর্মী — রিপোর্ট দেখেন, মুনাফা নয়।
     *
     * @param  list<string>  $extra
     */
    private function clerk(array $extra = []): User
    {
        foreach (['sales.report', 'sales.invoice.view', 'sales.cost.view'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();
        $user->givePermissionTo(array_merge(['sales.report', 'sales.invoice.view'], $extra));

        return $user->fresh();
    }

    // ── পর্দা ────────────────────────────────────────────────────

    /** অনুমতি ছাড়া মুনাফার কলামটা রিপোর্টে নেই। */
    public function test_the_profit_column_is_absent_without_permission(): void
    {
        $this->actingAs($this->clerk())
            ->get(route('sales.report.show', ['slug' => 'by-customer']))
            ->assertOk()
            ->assertDontSee(__('sales::field.gross_profit'));
    }

    /** অনুমতি থাকলে দেখা যায় — নাহলে পরীক্ষাটা কিছুই প্রমাণ করত না। */
    public function test_the_profit_column_is_there_with_permission(): void
    {
        $this->actingAs($this->clerk(['sales.cost.view']))
            ->get(route('sales.report.show', ['slug' => 'by-customer']))
            ->assertOk()
            ->assertSee(__('sales::field.gross_profit'));
    }

    // ── রপ্তানি ──────────────────────────────────────────────────

    /**
     * রপ্তানিতেও নেই।
     *
     * রপ্তানি পর্দার টেবিলটাই ধরে নেয়, তাই কলামটা পর্দা থেকে সরলে
     * ফাইলেও থাকে না। এই পরীক্ষাটা সেই সংযোগটাই পাহারা দেয় — কেউ
     * রপ্তানিকে আলাদা পথে নিয়ে গেলে এটা ভাঙবে।
     */
    public function test_the_profit_column_is_absent_from_the_export(): void
    {
        $response = $this->actingAs($this->clerk())
            ->get(route('sales.report.show', ['slug' => 'by-customer', 'export' => 'csv']))
            ->assertOk();

        /*
         * সত্যিই একটা ফাইল, পাতা নয়।
         *
         * এই লাইনটা আগে ছিল না, আর তাই পরীক্ষাটা **HTML পাতাটাই পড়ত**:
         * রিপোর্টের পর্দায় রপ্তানি কোনোদিন কাজ করেনি, `?export=csv`
         * চুপচাপ পাতাটাই ফেরত দিত। পাতায় কলামটা এমনিতেই ঢাকা, তাই
         * দাবিটা পাশ করত — রপ্তানি না থাকলেও।
         */
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'),
            'রপ্তানি একটা ফাইল দেয়নি — পাতাটাই ফিরেছে।');

        $csv = $response->getContent();

        $this->assertStringNotContainsString(__('sales::field.gross_profit'), $csv);

        // ফাইলটা সত্যিই বেরিয়েছে — নাহলে উপরের দাবিটা খালি স্ট্রিং
        // দেখেও পাশ করত, আর পরীক্ষাটা কিছুই প্রমাণ করত না
        $this->assertStringContainsString(__('sales::field.total'), $csv);
    }

    /** অনুমতি থাকলে রপ্তানিতেও থাকে। */
    public function test_the_profit_column_is_in_the_export_with_permission(): void
    {
        $response = $this->actingAs($this->clerk(['sales.cost.view']))
            ->get(route('sales.report.show', ['slug' => 'by-customer', 'export' => 'csv']))
            ->assertOk();

        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'),
            'রপ্তানি একটা ফাইল দেয়নি — পাতাটাই ফিরেছে।');

        $csv = $response->getContent();

        $this->assertStringContainsString(__('sales::field.gross_profit'), $csv);
    }

    // ── বিলের পর্দা ──────────────────────────────────────────────

    /** বিলের গায়ে বিক্রীত পণ্যের ব্যয়ও ঢাকা। */
    public function test_the_invoice_does_not_show_cost_without_permission(): void
    {
        $this->actingAs($this->clerk())
            ->get(route('sales.invoice.show', ['invoice' => $this->invoiceId]))
            ->assertOk()
            ->assertDontSee(__('sales::field.cost_of_goods'));
    }

    /** অনুমতি থাকলে বিলে দেখা যায়। */
    public function test_the_invoice_shows_cost_with_permission(): void
    {
        $this->actingAs($this->clerk(['sales.cost.view']))
            ->get(route('sales.invoice.show', ['invoice' => $this->invoiceId]))
            ->assertOk()
            ->assertSee(__('sales::field.cost_of_goods'));
    }
}
