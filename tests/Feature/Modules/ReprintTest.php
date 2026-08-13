<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\AuditTrail;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\PrintJob;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * দ্বিতীয়বার ছাপা কাগজে DUPLICATE।
 *
 * ── কেন ─────────────────────────────────────────────────────────────
 * একই বিলের দুইটা একরকম কাগজ ঘুরলে কোনটা আসল তা বলার উপায় থাকে না।
 * ক্রেতা দুইটা নিয়ে দুইবার ফেরতের দাবি করতে পারেন, বা কর্মী একটা
 * দেখিয়ে দ্বিতীয়বার টাকা নিতে পারেন। কাগজে লেখা থাকলে দুইটাই থামে।
 */
class ReprintTest extends TestCase
{
    use RefreshDatabase;

    private SalesInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '1', 'rate' => '100']],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function print(): array
    {
        $seen = [];

        View::composer('print.document', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route('sales.print.invoice', ['invoice' => $this->invoice->id]))->assertOk();

        return $seen;
    }

    // ── প্রথম ও দ্বিতীয় ──────────────────────────────────────────

    /** প্রথম ছাপায় কোনো সতর্কবার্তা নেই। */
    public function test_the_first_print_says_nothing_extra(): void
    {
        $this->assertNull($this->print()['doc']->notice);
    }

    /** দ্বিতীয়বারে কাগজে DUPLICATE। */
    public function test_the_second_print_is_marked_duplicate(): void
    {
        $this->print();

        $this->assertSame(__('core.print.duplicate_notice'), $this->print()['doc']->notice);
    }

    /** গোনাটা বাড়ে, আর সেটাই সিদ্ধান্তের ভিত্তি। */
    public function test_each_print_is_counted(): void
    {
        $this->print();
        $this->print();
        $this->print();

        $job = PrintJob::query()
            ->where('document_type', 'sales_invoice')
            ->where('document_id', $this->invoice->id)
            ->firstOrFail();

        $this->assertSame(3, $job->printed_count);
    }

    /**
     * একই বিলের একটাই সারি, যতবারই ছাপা হোক।
     *
     * প্রতিবার নতুন সারি বসালে "কতবার ছাপা হলো" প্রশ্নের উত্তর সারি
     * গুনে বের করতে হত, আর ব্যর্থ চেষ্টাগুলোও গোনা হয়ে যেত — অথচ
     * ব্যর্থ চেষ্টায় কোনো কাগজ বেরোয়নি।
     */
    public function test_one_row_per_paper_not_per_press(): void
    {
        $this->print();
        $this->print();

        $this->assertSame(1, PrintJob::query()
            ->where('document_type', 'sales_invoice')
            ->where('document_id', $this->invoice->id)
            ->count());
    }

    /**
     * ভিন্ন কাগজে ভিন্ন সারি।
     *
     * ৮০mm রসিদ আর A4 বিল আলাদা কাগজ, আর একটা ছাপা হলে অন্যটা
     * DUPLICATE হয় না — ক্রেতার হাতে তখনো একটাই কপি।
     */
    public function test_a_different_paper_starts_its_own_count(): void
    {
        $this->print();

        $this->get(route('sales.print.invoice', [
            'invoice' => $this->invoice->id,
            'paper' => '80mm',
        ]))->assertOk();

        $this->assertSame(2, PrintJob::query()
            ->where('document_type', 'sales_invoice')
            ->where('document_id', $this->invoice->id)
            ->count());
    }

    // ── কে ছেপেছিলেন ──────────────────────────────────────────────

    /**
     * দ্বিতীয় কপিটা কে ছেপেছিলেন, অডিটে তার নাম থাকে।
     *
     * DUPLICATE কাগজটাকে চিনিয়ে দেয়, কিন্তু মানুষটাকে নয়। `created_by`
     * কেবল প্রথমবারের মানুষকে চেনে — তৃতীয়বার কে চেপেছিলেন, সেটা
     * টেবিলটায় নেই। কর্মীর দ্বিতীয়বার টাকা নেওয়ার প্রশ্নে ওই নামটাই
     * দরকারি, তাই সেটা অডিট রাখে।
     */
    public function test_a_reprint_names_the_person_who_pressed_it(): void
    {
        $this->print();

        $second = User::query()->where('email', 'sales@abos.test')->firstOrFail();
        $this->actingAs($second);
        $this->print();

        $job = PrintJob::query()
            ->where('document_type', 'sales_invoice')
            ->where('document_id', $this->invoice->id)
            ->firstOrFail();

        $trail = $job->auditTrail()->where('action', AuditTrail::UPDATED)->firstOrFail();

        $this->assertSame($second->id, $trail->user_id);
    }

    /** গোনাটার পুরনো-নতুন দুইটা মানই অডিটে যায়। */
    public function test_the_audit_carries_the_count_before_and_after(): void
    {
        $this->print();
        $this->print();

        $job = PrintJob::query()
            ->where('document_type', 'sales_invoice')
            ->where('document_id', $this->invoice->id)
            ->firstOrFail();

        $change = $job->auditTrail()
            ->where('action', AuditTrail::UPDATED)
            ->firstOrFail()
            ->changes
            ->firstWhere('field', 'printed_count');

        $this->assertNotNull($change, 'printed_count-এর বদলটা অডিটে নেই');
        $this->assertSame('1', (string) $change->old_value);
        $this->assertSame('2', (string) $change->new_value);
    }

    // ── খসড়া ─────────────────────────────────────────────────────

    /**
     * খসড়ায় DUPLICATE বসে না — "চূড়ান্ত নয়" লেখাটাই থাকে।
     *
     * খসড়া দিয়ে কেউ টাকা চাইতে গেলে সেটা DUPLICATE-এর চেয়ে বড় ভুল,
     * তাই ওই বার্তাটাই জেতে। আর খসড়া কতবার ছাপা হলো তা কারও জানার
     * দরকার নেই।
     */
    public function test_a_draft_keeps_its_own_notice(): void
    {
        $seen = [];

        View::composer('print.document', function ($view) use (&$seen) {
            $seen = $view->getData();
        });

        $this->get(route('sales.print.draft', ['invoice' => $this->invoice->id]))->assertOk();
        $this->get(route('sales.print.draft', ['invoice' => $this->invoice->id]))->assertOk();

        $this->assertSame(__('core.print.draft_notice'), $seen['doc']->notice);
    }
}
