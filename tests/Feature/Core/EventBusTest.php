<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Events\DomainEvent;
use App\Core\Events\EventRegistry;
use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Events\InvoiceConfirmed;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Event Bus — প্ল্যান WP-0.3, আর্কিটেকচার §৭।
 *
 * ── এই পরীক্ষাগুলো আসলে কী পাহারা দেয় ────────────────────────────────
 * ইভেন্ট ব্যবস্থার সবচেয়ে বড় বিপদ হলো এটা **নীরবে কাজ না করা**। ইভেন্ট
 * ছোড়া হয় ঠিকই, শ্রোতা নিবন্ধিত হয়নি — কোথাও কোনো ভুল নেই, শুধু কিছুই
 * ঘটে না। তাই এখানে প্রতিটা পরীক্ষা সত্যিকারের পথ ধরে হাঁটে: আসল বিল
 * নিশ্চিত করে, আসল ঘটনা ধরে।
 *
 * আর সবচেয়ে জরুরি পাহারাটা উল্টো দিকের: **যা ইভেন্টে যাওয়ার কথা নয়,
 * তা যায়নি তো?** দাখিলা বা স্টক ইভেন্টে চলে গেলে একদিন খাতা মিলবে না,
 * আর সেদিন কারণটা খুঁজে বের করা প্রায় অসম্ভব।
 */
class EventBusTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->product = Product::query()->firstOrFail();
        $this->customer = Customer::query()->first() ?? app(CustomerService::class)->create([
            'name_en' => 'Event Test Customer', 'credit_limit' => 0, 'credit_days' => 0,
        ]);
    }

    /**
     * একটা বিল নিশ্চিত করলে সত্যিই ঘটনাটা ছোড়া হয়।
     *
     * Event::fake() পরে বসানো হয় — সিডার নিজেও বিল বানায়, আর শুরুতেই
     * বসালে ওগুলোও ধরা পড়ত।
     */
    public function test_confirming_an_invoice_announces_it(): void
    {
        Event::fake([InvoiceConfirmed::class]);

        $invoice = app(SalesInvoiceService::class)->confirm($this->draft());

        Event::assertDispatched(
            InvoiceConfirmed::class,
            fn (InvoiceConfirmed $event) => $event->publicId === $invoice->public_id
                && $event->companyId === $this->company->id
                && $event->payload['document_no'] === $invoice->document_no
        );
    }

    /**
     * খসড়া থেকে গেলে কিছুই ঘোষণা হয় না।
     *
     * ── কেন এই পরীক্ষাটা আলাদা করে লাগে ─────────────────────────────
     * উপরেরটা কেবল দেখায় "ছোড়া হয়েছে"। কেউ যদি ভুল করে বিল **তৈরির**
     * সময়ও ঘটনাটা ছোড়ে, উপরেরটা তবু পাশ করত — কারণ ঘটনাটা তো ছোড়া
     * হয়েছেই। তখন শ্রোতা এমন বিলের খবর দিত যা এখনো কেউ নিশ্চিত করেনি।
     */
    public function test_a_draft_announces_nothing(): void
    {
        Event::fake([InvoiceConfirmed::class]);

        $this->draft();

        Event::assertNotDispatched(InvoiceConfirmed::class);
    }

    /**
     * লেনদেন রোল-ব্যাক হলে ঘটনাটা কখনো বেরোয় না।
     *
     * ── কেন এটাই সবচেয়ে জরুরি পরীক্ষা ───────────────────────────────
     * ঘটনাটা লেনদেনের ভেতরে ছুড়লে শ্রোতা এমন একটা বিল দেখত যা এখনো
     * কমিট হয়নি। SMS চলে যেত, তারপর লেনদেন উল্টে যেত — গ্রাহক একটা
     * বিলের খবর পেতেন যেটার কোনো অস্তিত্ব নেই, আর সেটা ফেরানোর উপায়
     * নেই।
     *
     * এখানে ইচ্ছে করে বাইরে থেকে একটা লেনদেন খুলে ভেতরে বিল নিশ্চিত
     * করা হয়, তারপর সবটা উল্টে দেওয়া হয়। ঘটনাটা `DB::afterCommit`-এ
     * বাঁধা না থাকলে এই পরীক্ষাটা ভাঙবে।
     */
    public function test_a_rolled_back_invoice_never_announces_itself(): void
    {
        Event::fake([InvoiceConfirmed::class]);

        $draft = $this->draft();

        DB::beginTransaction();
        app(SalesInvoiceService::class)->confirm($draft);
        DB::rollBack();

        Event::assertNotDispatched(InvoiceConfirmed::class);
    }

    /**
     * ঘোষিত শ্রোতারা সত্যিই বসানো হয়।
     *
     * নিবন্ধনটা বুট-টাইমে হয়, আর সেটা ভুলে গেলে কোথাও কোনো ভুল আসত
     * না — শুধু কিছুই ঘটত না। তাই এখানে একটা শ্রোতা রেজিস্ট্রি দিয়ে
     * বসিয়ে সত্যিই ডাকা হয় কিনা দেখা হয়।
     */
    public function test_a_declared_listener_actually_hears_the_event(): void
    {
        RememberTheInvoice::$heard = [];

        app('events')->listen(InvoiceConfirmed::class, RememberTheInvoice::class);

        $invoice = app(SalesInvoiceService::class)->confirm($this->draft());

        $this->assertSame([$invoice->public_id], RememberTheInvoice::$heard,
            'শ্রোতা ডাকা হয়নি — ইভেন্ট ছোড়া হলেও কিছুই ঘটেনি।');
    }

    /**
     * ঘোষিত প্রতিটা ইভেন্ট সত্যিই আছে, আর সত্যিই DomainEvent।
     *
     * প্ল্যান WP-0.3-এর নিজের দাবি। রেজিস্ট্রি ধরে হাঁটে, তাই নতুন
     * মডিউল নিজে থেকেই আওতায় পড়ে।
     */
    public function test_every_declared_event_exists_and_is_a_domain_event(): void
    {
        $declared = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->events as $event) {
                $declared[] = $event;

                $this->assertTrue(is_subclass_of($event, DomainEvent::class),
                    "{$module->code} ঘোষণা করেছে {$event}, কিন্তু সেটা DomainEvent নয়।");
            }
        }

        $this->assertNotEmpty($declared,
            'একটাও ঘোষিত ইভেন্ট নেই — তাহলে এই পরীক্ষাটা কিছুই দেখছে না।');
    }

    /**
     * ঘোষণার তালিকা আর বাস্তব এক — যা ছোড়া হয়, তা ঘোষিতও।
     *
     * ── কেন এটা দরকার ───────────────────────────────────────────────
     * ঘোষণা একটা চুক্তি। কেউ ঘোষণা না করেই ইভেন্ট ছুড়লে অন্য মডিউল
     * সেটা জানত না, অথচ শুনতে শুরু করলে একদিন সেটা নীরবে সরে যেত —
     * "এটা তো কখনো চুক্তির অংশ ছিল না" বলে।
     */
    public function test_what_gets_announced_was_also_declared(): void
    {
        $declared = [];

        foreach (app(EventRegistry::class)->published() as $events) {
            foreach ($events as $event) {
                $declared[$event] = true;
            }
        }

        $this->assertArrayHasKey(InvoiceConfirmed::class, $declared,
            'বিক্রয় বিল নিশ্চিত হওয়ার ঘটনাটা ছোড়া হয়, কিন্তু module.php-তে ঘোষিত নয়।');
    }

    private function draft(): SalesInvoice
    {
        return app(SalesInvoiceService::class)->create(
            ['customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
                'trx_date' => now()->toDateString()],
            [['product_id' => $this->product->id, 'qty' => '2', 'rate' => '100']],
        );
    }
}

/** পরীক্ষার শ্রোতা — যা শুনল তা মনে রাখে, আর কিছু করে না। */
class RememberTheInvoice
{
    /** @var list<string> */
    public static array $heard = [];

    public function handle(InvoiceConfirmed $event): void
    {
        self::$heard[] = $event->publicId;
    }
}
