<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
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
use Tests\TestCase;

/**
 * যে কাগজ বেরোয়নি — তার তালিকা।
 *
 * ── কী ছিল না ───────────────────────────────────────────────────────
 * `PrintQueue` সারিটা রাখত, গোনা রাখত, ব্যর্থতার কারণ রাখত — আর কেউ
 * কখনো সেটা দেখতে পেত না। যে তালিকা কেউ দেখে না তা তালিকা নয়, শুধু
 * একটা টেবিল যেখানে সারি জমে।
 */
class PrintQueueScreenTest extends TestCase
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

    private function job(string $status = PrintJob::WAITING, ?string $failure = null): PrintJob
    {
        return PrintJob::query()->create([
            'company_id' => CompanyContext::id(),
            'branch_id' => CompanyContext::branchId(),
            'document_type' => PrintJob::INVOICE,
            'document_id' => $this->invoice->id,
            'document_no' => $this->invoice->document_no,
            'paper' => 'a4',
            'status' => $status,
            'printed_count' => 0,
            'failure' => $failure,
        ]);
    }

    // ── তালিকা ───────────────────────────────────────────────────

    /** যে কাগজ বেরোয়নি সেটা তালিকায় আছে, নম্বর ধরে চেনা যায়। */
    public function test_a_paper_that_did_not_come_out_is_on_the_list(): void
    {
        $this->job();

        $this->get(route('sales.print_queue.index'))
            ->assertOk()
            ->assertSee($this->invoice->document_no);
    }

    /** ব্যর্থতার কারণটা দেখানো হয় — "কাগজ ফুরিয়েছে" আর "প্রিন্টার বন্ধ" এক কাজ নয়। */
    public function test_the_reason_it_failed_is_shown(): void
    {
        $this->job(PrintJob::FAILED, 'প্রিন্টারে কাগজ নেই');

        $this->get(route('sales.print_queue.index'))
            ->assertOk()
            ->assertSee('প্রিন্টারে কাগজ নেই');
    }

    /** যে কাগজ বেরিয়ে গেছে সেটা অপেক্ষার তালিকায় নেই। */
    public function test_a_printed_paper_is_not_waiting(): void
    {
        $this->job(PrintJob::PRINTED);

        $this->get(route('sales.print_queue.index'))
            ->assertOk()
            ->assertDontSee($this->invoice->document_no);
    }

    /**
     * সারিটা ছাপার পাতার দিকে একটা লিংক দেয়।
     *
     * বোতাম নয় — সার্ভার নিজে কাগজ বের করতে পারে না, আর একটা "ছাপাও"
     * বোতাম চাপলে সারিটা "ছাপা হয়েছে" হয়ে যেত অথচ কাগজ বেরোত না।
     */
    public function test_each_row_links_to_the_paper_itself(): void
    {
        $this->job();

        $this->get(route('sales.print_queue.index'))
            ->assertOk()
            ->assertSee(route('sales.print.invoice', ['invoice' => $this->invoice->id, 'paper' => 'a4']), false);
    }

    // ── হাতে চিহ্নিত করা ──────────────────────────────────────────

    /**
     * "বেরিয়ে গেছে" চাপলে সারিটা অপেক্ষা থেকে সরে, কিন্তু গোনা বাড়ে না।
     *
     * কাগজটা এই ব্যবস্থার ভেতর দিয়ে বেরোয়নি — অন্য মেশিন থেকে, বা
     * হাতে লেখা হয়েছে। তাই "কতবার ছাপা হলো" সংখ্যাটা বাড়ার কথা নয়,
     * নাহলে DUPLICATE বসত এমন একটা কাগজে যা কখনো ছাপাই হয়নি।
     */
    public function test_settling_a_job_clears_it_without_counting_a_print(): void
    {
        $job = $this->job();

        $this->post(route('sales.print_queue.settle', ['job' => $job->id]))
            ->assertRedirect();

        $job->refresh();

        $this->assertSame(PrintJob::PRINTED, $job->status);
        $this->assertSame(0, $job->printed_count);
    }

    /**
     * সরানো সারিটা আর তালিকায় থাকে না।
     *
     * ── কেন নম্বরটা খুঁজে দেখা হয় না ────────────────────────────────
     * প্রথমে `assertDontSee($document_no)` লিখেছিলাম, আর সেটা কখনোই
     * পাশ করত না — সরানোর পরের বার্তাটাই ("… অপেক্ষার তালিকা থেকে
     * সরানো হলো") নম্বরটা বলে। পরীক্ষাটা তখন ঠিক কাজটার বদলে পাতার
     * অক্ষর গুনত।
     *
     * তাই মাপা হয় যা আসলে জানতে চাই: তালিকাটা এখন খালি।
     */
    public function test_a_settled_job_leaves_the_list(): void
    {
        $job = $this->job();

        $this->post(route('sales.print_queue.settle', ['job' => $job->id]));

        $this->get(route('sales.print_queue.index'))
            ->assertOk()
            ->assertSee(__('sales::message.print_queue_empty'));
    }

    // ── অনুমতি ───────────────────────────────────────────────────

    /** বিল দেখার অনুমতি ছাড়া তালিকাটাই খোলে না। */
    public function test_it_needs_permission_to_see_invoices(): void
    {
        $stranger = User::factory()->create();
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $stranger->companies()->attach($company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $company->id])->save();

        $this->actingAs($stranger)
            ->get(route('sales.print_queue.index'))
            ->assertForbidden();
    }
}
