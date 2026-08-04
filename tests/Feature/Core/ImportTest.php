<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\ImportRunner;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * পুরনো খাতা থেকে আনা।
 *
 * প্রতিটা নতুন গ্রাহক আসে Tally, Excel বা হাতের খাতা নিয়ে। এই পর্দাটা
 * না থাকলে প্রথম দিনে তিনশো গ্রাহক হাতে তুলতে হত, আর go-live ছয় মাস
 * পিছিয়ে যেত — ফিচারের অভাবে নয়।
 *
 * এখানকার সবচেয়ে জরুরি পরীক্ষা: **ইমপোর্ট করা সারি হাতে বসানো সারির
 * মতোই নিয়ম মানে কি না।** ইমপোর্টার নিজে সেভ করলে বাংলা নামের নিয়ম,
 * কোডের অনন্যতা বা খোলা ব্যালেন্সের দাখিলা কোনোটাই খাটত না, আর সেটা
 * ধরা পড়ত মাস পরে — যখন ট্রায়াল ব্যালেন্স আর পক্ষের পাতা আলাদা বলত।
 */
class ImportTest extends TestCase
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

    private function header(): string
    {
        return 'code,name_en,name_bn,phone,email,address,contact_person,bin,tin,'
            .'party_type,payment_term,credit_limit,opening_balance,opening_date';
    }

    // ── মডিউল নিজে ঘোষণা করে ───────────────────────────────────────────

    /**
     * ইমপোর্টের তালিকাটা মডিউলের ঘোষণা থেকে আসে।
     *
     * কোর কোডে হাতে লেখা তালিকা থাকলে Inventory যেদিন আসত সেদিন এই
     * ফাইলটাও ছুঁতে হত — আর ছুঁতে ভুলে গেলে ইমপোর্টটা নীরবে অনুপস্থিত
     * থাকত (সেকশন ১৯.৭)।
     */
    public function test_the_list_of_importers_comes_from_the_modules(): void
    {
        $available = $this->runner()->available();

        $this->assertArrayHasKey('supplier', $available);
        $this->assertArrayHasKey('customer', $available);
    }

    public function test_the_template_names_every_column_in_order(): void
    {
        $csv = $this->runner()->template('supplier');

        // BOM — নাহলে Excel বাংলা লেখা ভেঙে দেখায়, আর ব্যবহারকারী ভাবেন
        // ফাইলটাই নষ্ট
        $this->assertStringStartsWith("\u{FEFF}", $csv);
        $this->assertStringContainsString('name_en', $csv);
        $this->assertStringContainsString('opening_balance', $csv);
    }

    // ── আগে দেখা, তারপর বসানো ─────────────────────────────────────────

    /**
     * যাচাই কিছুই সেভ করে না।
     *
     * করলে "আগে দেখুন" বোতামটা মিথ্যা বলত, আর ব্যবহারকারী দেখতে গিয়ে
     * ভুল ডেটা বসিয়ে ফেলতেন।
     */
    public function test_checking_saves_nothing(): void
    {
        $before = Supplier::query()->count();

        $result = $this->runner()->check('supplier', $this->csv(
            $this->header()."\n,Rangpur Foods,,,,,,,,,,0,0,\n"
        ));

        $this->assertSame(1, $result['ok']);
        $this->assertSame($before, Supplier::query()->count());
    }

    public function test_a_good_file_is_brought_in(): void
    {
        $result = $this->runner()->run('supplier', $this->csv(
            $this->header()
            ."\n,Rangpur Foods,রংপুর ফুডস,01711223344,,Rangpur,Kamal,,,VENDOR,NET30,50000,15000,01/07/2026"
            ."\n,Bogura Traders,,01811223344,,Bogura,,,,VENDOR,,0,0,\n"
        ));

        $this->assertSame(2, $result['imported']);
        $this->assertSame([], $result['failed']);

        $rangpur = Supplier::query()->where('name_en', 'Rangpur Foods')->firstOrFail();

        $this->assertSame('রংপুর ফুডস', $rangpur->name_bn);
        $this->assertStringStartsWith('SUP-', $rangpur->code, 'কোড না দিলে সিরিজ থেকে আসবে।');
    }

    /**
     * খোলা ব্যালেন্স ইমপোর্টেও খাতায় বসে।
     *
     * এটাই ইমপোর্টারকে Service ডাকতে বাধ্য করার আসল কারণ। নিজে
     * Supplier::create() ডাকলে সারিটা তৈরি হত, বকেয়াও দেখাত, কিন্তু
     * ট্রায়াল ব্যালেন্সে ১৫,০০০ কোথাও থাকত না।
     */
    public function test_an_imported_opening_balance_reaches_the_ledger(): void
    {
        $this->runner()->run('supplier', $this->csv(
            $this->header()."\n,Rangpur Foods,,,,,,,,,,0,15000,01/07/2026\n"
        ));

        $supplier = Supplier::query()->where('name_en', 'Rangpur Foods')->firstOrFail();

        $this->assertSame(0, bccomp($supplier->payable(), '15000', 4));
    }

    // ── ভুল সারি ───────────────────────────────────────────────────────

    /**
     * ভুল সারিগুলো বাদ যায়, পুরো ফাইল নয়।
     *
     * তিনশো সারির মধ্যে দুইটা ভুল থাকলে বাকি ২৯৮টা আটকে রাখার কারণ নেই।
     * আটকালে ব্যবহারকারী দুইটা ঠিক করে পুরো ফাইল আবার পাঠাতেন, আর
     * প্রতিবার নতুন একটা ভুল বেরোত।
     */
    public function test_bad_rows_are_skipped_and_the_rest_still_come_in(): void
    {
        $result = $this->runner()->run('supplier', $this->csv(
            $this->header()
            ."\n,Good One,,,,,,,,,,0,0,"
            ."\n,,No Name Here,,,,,,,,,0,0,"          // name_en খালি
            ."\n,Bad Number,,,,,,,,,,abc,0,"          // credit_limit সংখ্যা নয়
            ."\n,Another Good,,,,,,,,,,0,0,\n"
        ));

        $this->assertSame(2, $result['imported']);
        $this->assertCount(2, $result['failed']);

        // সারি নম্বরগুলো ফাইলের সাথে মেলে — নাহলে ব্যবহারকারী ভুল
        // সারিটা খুঁজে পেতেন না
        $this->assertSame([3, 4], array_column($result['failed'], 'line'));
    }

    public function test_a_duplicate_code_is_refused_before_it_is_saved(): void
    {
        $existing = Supplier::query()->first()
            ?? app(SupplierService::class)->create([
                'name_en' => 'Existing', 'credit_limit' => 0, 'credit_days' => 0,
            ]);

        $result = $this->runner()->check('supplier', $this->csv(
            $this->header()."\n{$existing->code},Clashing Name,,,,,,,,,,0,0,\n"
        ));

        $this->assertSame(1, $result['bad']);
        $this->assertNotEmpty($result['rows'][0]['errors']);
    }

    /**
     * দিন/মাস/বছর — মাস/দিন/বছর নয়।
     *
     * Carbon::parse() "05/03/2026" কে ৩ মে ধরে, অথচ বাংলাদেশে ওটা
     * ৫ মার্চ। ভুলটা কোনো ত্রুটি দেখাত না — শুধু বকেয়া ভুল মাসে বসত,
     * আর বয়সভিত্তিক রিপোর্ট দুই মাস মিথ্যা বলত।
     */
    public function test_dates_are_read_as_day_month_year(): void
    {
        $this->runner()->run('supplier', $this->csv(
            $this->header()."\n,Date Test,,,,,,,,,,0,1000,05/03/2026\n"
        ));

        $supplier = Supplier::query()->where('name_en', 'Date Test')->firstOrFail();

        $this->assertSame('2026-03-05', $supplier->opening_date->toDateString());
    }

    public function test_an_unreadable_date_is_reported_not_guessed(): void
    {
        $result = $this->runner()->check('supplier', $this->csv(
            $this->header()."\n,Bad Date,,,,,,,,,,0,0,tomorrow\n"
        ));

        $this->assertSame(1, $result['bad']);
    }

    /**
     * ধরন নাম বা কোড — দুইভাবেই মেলে।
     *
     * পুরনো খাতায় লেখা থাকে "সরবরাহকারী", CSV-তে কেউ লেখেন "VENDOR"।
     * একটাই মানলে অর্ধেক সারি বাদ পড়ত, আর ব্যবহারকারী বুঝতেন না কেন —
     * ঘরটা তো ভরাই আছে।
     */
    public function test_a_type_matches_by_code_or_by_name(): void
    {
        foreach (['VENDOR', 'Vendor', 'সরবরাহকারী'] as $i => $value) {
            $result = $this->runner()->check('supplier', $this->csv(
                $this->header()."\n,Party {$i},,,,,,,,{$value},,0,0,\n"
            ));

            $this->assertSame(1, $result['ok'], "'{$value}' চেনা গেল না।");
        }
    }

    public function test_an_unknown_type_is_reported(): void
    {
        $result = $this->runner()->check('supplier', $this->csv(
            $this->header()."\n,Unknown Type,,,,,,,,NOSUCHTYPE,,0,0,\n"
        ));

        $this->assertSame(1, $result['bad']);
    }

    // ── ফাইলের আকার ও আকৃতি ───────────────────────────────────────────

    public function test_blank_rows_at_the_end_are_ignored(): void
    {
        // Excel প্রায়ই ফাইলের শেষে কয়েকটা খালি সারি রেখে দেয়, আর
        // ওগুলো ভুল হিসেবে দেখালে ব্যবহারকারী ভাবতেন ফাইলটা নষ্ট
        $result = $this->runner()->check('supplier', $this->csv(
            $this->header()."\n,Only Row,,,,,,,,,,0,0,\n,,,,,,,,,,,,,\n,,,,,,,,,,,,,\n"
        ));

        $this->assertCount(1, $result['rows']);
    }

    public function test_a_file_beyond_the_limit_says_so_instead_of_cutting_silently(): void
    {
        $body = $this->header()."\n";

        foreach (range(1, ImportRunner::MAX_ROWS + 5) as $i) {
            $body .= ",Row {$i},,,,,,,,,,0,0,\n";
        }

        $result = $this->runner()->check('supplier', $this->csv($body));

        $this->assertTrue($result['truncated'], 'কেটে দেওয়া হলে সেটা বলতেই হবে।');
        $this->assertCount(ImportRunner::MAX_ROWS, $result['rows']);
    }

    /**
     * Excel-এর BOM কলামের নাম নষ্ট করে না।
     *
     * BOM প্রথম কলামের নামে লেগে থাকে, আর তখন "code" কলামটা "নেই" বলে
     * ধরা পড়ত — অর্থাৎ ব্যবহারকারীর নিজের নামানো নমুনা ফাইলটাই কাজ করত না।
     */
    public function test_a_file_saved_by_excel_still_reads(): void
    {
        $result = $this->runner()->check('supplier', $this->csv(
            "\u{FEFF}".$this->header()."\nSUP-BOM-1,BOM Test,,,,,,,,,,0,0,\n"
        ));

        $this->assertSame(1, $result['ok']);
        $this->assertSame('SUP-BOM-1', $result['rows'][0]['data']['code']);
    }

    // ── পর্দা ও অনুমতি ─────────────────────────────────────────────────

    public function test_the_screen_opens_and_lists_what_can_be_imported(): void
    {
        $this->actingAs($this->user)
            ->get(route('system_admin.import.index'))
            ->assertOk()
            ->assertSee(__('supplier::menu.suppliers'), false)
            ->assertSee(__('customer::menu.customers'), false);
    }

    public function test_a_user_without_the_permission_cannot_import(): void
    {
        $stranger = User::factory()->create();
        $stranger->companies()->attach($this->company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $this->company->id])->save();

        $this->actingAs($stranger)->get(route('system_admin.import.index'))->assertForbidden();

        $this->actingAs($stranger)
            ->post(route('system_admin.import.store'), [
                'kind' => 'supplier',
                'file' => $this->csv($this->header()."\n,Sneaky,,,,,,,,,,0,0,\n"),
            ])
            ->assertForbidden();

        $this->assertSame(0, Supplier::query()->where('name_en', 'Sneaky')->count());
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $this->actingAs($this->user)
            ->post(route('system_admin.import.check'), [
                'kind' => 'nonsense',
                'file' => $this->csv($this->header()."\n"),
            ])
            ->assertSessionHasErrors('kind');
    }

    public function test_the_template_downloads(): void
    {
        $this->actingAs($this->user)
            ->get(route('system_admin.import.template', ['kind' => 'supplier']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }
}
