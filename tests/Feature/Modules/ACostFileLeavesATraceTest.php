<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\ExportLog;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Services\SalesInvoiceService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ক্রয়মূল্যের ফাইল বাইরে গেলে কোনো চিহ্ন থাকত না।
 *
 * ── কেন এটা আজ জরুরি হলো ────────────────────────────────────────────
 * ABOS ক্রয়মূল্য ও মুনাফা যত্ন করে ঢেকেছে — কলাম ধরে, তিন পথেই। কিন্তু
 * যাঁর অনুমতি আছে তিনি পুরো তালিকাটা এক ক্লিকে নামিয়ে নিতে পারেন।
 *
 * ঝুঁকিটা এতদিন তাত্ত্বিক ছিল, কারণ **রিপোর্টের রপ্তানি আসলে কাজই করত
 * না** — `?export=csv` চুপচাপ HTML পাতাটাই ফেরত দিত। সেটা সারানোর পর
 * সত্যিই ফাইল নামে, তাই খাতাটা এখনই।
 */
class ACostFileLeavesATraceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        // রিপোর্টে অন্তত একটা সারি, নাহলে ফাইলটাই বেরোয় না
        $invoice = app(SalesInvoiceService::class)->create(
            [
                'customer_id' => Customer::query()->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => now()->toDateString(),
            ],
            [['product_id' => Product::query()->value('id'), 'qty' => '2', 'rate' => '100']],
        );

        app(SalesInvoiceService::class)->confirm($invoice);
    }

    private function exportByCustomer(array $extra = []): TestResponse
    {
        return $this->get(route('sales.report.show', [
            'slug' => 'by-customer',
            'export' => 'csv',
            ...$extra,
        ]));
    }

    // ── চিহ্নটা থাকে ────────────────────────────────────────────────

    /** একটা রপ্তানি মানে খাতায় একটা সারি। */
    public function test_taking_a_file_writes_a_row(): void
    {
        $this->assertSame(0, ExportLog::query()->count());

        $this->exportByCustomer()->assertOk();

        $this->assertSame(1, ExportLog::query()->count(), 'ফাইল বেরোল, অথচ খাতায় কিছু বসেনি।');
    }

    /** কে নিয়েছেন — নামসহ। */
    public function test_it_says_who_took_it(): void
    {
        $this->exportByCustomer()->assertOk();

        $row = ExportLog::query()->latestFirst()->firstOrFail();

        $this->assertSame(auth()->id(), $row->user_id);
        $this->assertSame(auth()->user()->name, $row->user_name);
    }

    /**
     * কোন ছাঁকনিতে — "করিম রপ্তানি করেছেন" যথেষ্ট নয়।
     *
     * কোন তারিখের, কোন শাখার — ওটাই বলে দেয় তিনি নিজের কাজের জন্য
     * নামিয়েছেন নাকি গোটা বছরের তালিকা নিয়ে গেছেন।
     */
    public function test_it_says_which_filters_were_used(): void
    {
        $this->exportByCustomer(['from' => '2026-01-01', 'to' => '2026-12-31'])->assertOk();

        $row = ExportLog::query()->latestFirst()->firstOrFail();

        $this->assertSame('2026-01-01', $row->filters['from'] ?? null);
        $this->assertSame('2026-12-31', $row->filters['to'] ?? null);

        // `export=csv` ছাঁকনি নয় — ওটা "ফাইল চাই" বলার উপায়
        $this->assertArrayNotHasKey('export', (array) $row->filters);
    }

    /**
     * কয়টা সারি গেছে।
     *
     * দশ সারির ফাইল আর দশ হাজার সারির ফাইল — দুইটার মানে সম্পূর্ণ
     * আলাদা, অথচ খাতায় দুইটাই "একটা রপ্তানি"।
     */
    public function test_it_counts_the_rows_that_left(): void
    {
        $this->exportByCustomer()->assertOk();

        $this->assertSame(1, ExportLog::query()->latestFirst()->firstOrFail()->row_count);
    }

    /** কোন পর্দা থেকে। */
    public function test_it_says_which_screen(): void
    {
        $this->exportByCustomer()->assertOk();

        $row = ExportLog::query()->latestFirst()->firstOrFail();

        $this->assertSame('sales.report.show', $row->route);
        $this->assertSame('by-customer', $row->title);
    }

    // ── যা খাতায় ওঠে না ─────────────────────────────────────────────

    /**
     * ফাইল না বেরোলে খাতায় কিছু বসে না।
     *
     * ── কেন এটা আলাদা করে পরীক্ষা ───────────────────────────────────
     * উপরে বসালে সেই অনুরোধগুলোও উঠত যেগুলোয় আসলে কিছু নামেইনি। তখন
     * খাতা পড়ে মনে হত ফাইল গেছে, অথচ যায়নি — আর ভুল চিহ্ন কোনো চিহ্ন
     * না থাকার চেয়ে খারাপ।
     */
    public function test_a_page_that_gives_no_file_writes_nothing(): void
    {
        // ড্যাশবোর্ডে রপ্তানিযোগ্য কোনো তালিকা নেই
        $this->get(route('dashboard', ['export' => 'csv']))->assertOk();

        $this->assertSame(0, ExportLog::query()->count(),
            'কোনো ফাইল বেরোয়নি, তবু খাতায় সারি বসেছে।');
    }

    /** সাধারণ দেখা — ফাইল নয় — খাতায় ওঠে না। */
    public function test_just_looking_at_a_report_writes_nothing(): void
    {
        $this->get(route('sales.report.show', ['slug' => 'by-customer']))->assertOk();

        $this->assertSame(0, ExportLog::query()->count());
    }

    // ── পর্দা ───────────────────────────────────────────────────────

    /** @param  list<string>  $extra */
    private function clerk(array $extra = []): User
    {
        foreach (['governance.audit.view', 'sales.report'] as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user = User::factory()->create();
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id])->save();
        $user->givePermissionTo($extra);

        return $user->fresh();
    }

    /**
     * খাতাটা পড়া যায়।
     *
     * যে খাতা কেউ পড়তে পারে না, সেটা থাকা আর না থাকা সমান।
     */
    public function test_the_journal_can_be_read(): void
    {
        $this->exportByCustomer()->assertOk();

        $this->actingAs($this->clerk(['governance.audit.view']))
            ->get(route('governance.export.index'))
            ->assertOk()
            ->assertSee('by-customer')
            ->assertSee(__('governance::menu.export_log'));
    }

    /** অনুমতি ছাড়া নয় — খাতাটা নিজেই সংবেদনশীল। */
    public function test_it_is_behind_a_permission(): void
    {
        $this->actingAs($this->clerk())
            ->get(route('governance.export.index'))
            ->assertForbidden();
    }

    /**
     * খাতাটা নিজে রপ্তানি হয় না।
     *
     * ── কেন ─────────────────────────────────────────────────────────
     * হলে প্রতিবার নামানোয় খাতায় আরেকটা সারি বসত — একটা খাতা যা নিজের
     * দিকে তাকিয়ে বাড়ে। তার চেয়েও বড় কথা: যিনি নিজের চিহ্ন ঢাকতে চান,
     * তাঁর প্রথম কাজই হত পুরো খাতাটা নামিয়ে দেখা কী ধরা পড়েছে।
     */
    public function test_the_journal_itself_cannot_be_exported(): void
    {
        $this->exportByCustomer()->assertOk();

        $before = ExportLog::query()->count();

        $response = $this->actingAs($this->clerk(['governance.audit.view']))
            ->get(route('governance.export.index', ['export' => 'csv']))
            ->assertOk();

        $this->assertStringNotContainsString('text/csv',
            (string) $response->headers->get('content-type'),
            'রপ্তানির খাতাটাই রপ্তানি হয়ে গেল।');

        $this->assertSame($before, ExportLog::query()->count());
    }

    // ── খাতাটা বদলানো যায় না ───────────────────────────────────────

    /**
     * সারিটার সংশোধনের সময় নেই — কারণ সংশোধন হয় না।
     *
     * `audit_trails`-এর একই নিয়ম: যে খাতা বদলানো যায়, সেটা খাতা নয়।
     */
    public function test_a_row_has_no_updated_at(): void
    {
        $this->exportByCustomer()->assertOk();

        $this->assertNull(ExportLog::UPDATED_AT);

        $this->assertFalse(
            Schema::hasColumn('export_log', 'updated_at'),
            'খাতার সারিতে সংশোধনের সময় আছে — অর্থাৎ কেউ বদলাতে পারে।'
        );
    }

    /**
     * ব্যবহারকারী মুছে ফেললেও চিহ্নটা থাকে।
     *
     * নাহলে যিনি ফাইলটা নিয়ে গেছেন তাঁকে সরিয়ে দিয়েই প্রমাণটা মুছে
     * ফেলা যেত — আর সেটাই ঠিক ওই মুহূর্ত যখন খাতাটা লাগে।
     */
    public function test_the_trace_survives_the_user_being_removed(): void
    {
        $clerk = $this->clerk(['sales.report']);

        $this->actingAs($clerk);
        $this->exportByCustomer()->assertOk();

        $name = $clerk->name;
        $clerk->forceDelete();

        $row = ExportLog::query()->latestFirst()->firstOrFail();

        $this->assertNull($row->user_id);
        $this->assertSame($name, $row->user_name, 'ব্যবহারকারীর সাথে চিহ্নটাও মুছে গেছে।');
        $this->assertSame($name, $row->who());
    }
}
