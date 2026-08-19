<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Services\CustomerService;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * One Toolbar Standard — সেকশন ১৫.২৪।
 *
 * এখানকার আসল পরীক্ষা একটাই: **টুলবারের প্রতিটা নিয়ন্ত্রণ সত্যিই কিছু
 * করে কি না।**
 *
 * আগে করত না। Filter · Columns · Density · Export · Print · Refresh
 * ছয়টাই ছিল খালি <button> — দেখে মনে হত কাজ করে, ক্লিক করলে কিছুই হত
 * না। কোনো টেস্ট ভাঙত না, কারণ পাতাটা ২০০ দিত আর বোতামগুলোও পাতায়
 * থাকত। মেনুর মৃত সারিগুলোর মতোই এটা সবচেয়ে খারাপ ধরনের স্টাব: কাজটা
 * আছে বলে দেখায়।
 */
class ToolbarTest extends TestCase
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

    // ── সাজানো সত্যিই সাজায় ────────────────────────────────────────────

    /**
     * ডিফল্ট বাছাই সবচেয়ে বেশি প্রদেয় আগে।
     *
     * এটাই তালিকাটা খোলার আসল কারণ — "কাকে টাকা দিতে হবে"। বর্ণানুক্রমে
     * সাজালে ব্যবহারকারীকে প্রতিবার নিজে বদলাতে হত, আর বেশিরভাগ মানুষ
     * সেটা করত না; তারা প্রথম পাতাটা দেখে ধরে নিত এটাই সব।
     */
    public function test_the_supplier_list_starts_with_the_biggest_payable(): void
    {
        $this->supplier('Small', '1000.0000');
        $this->supplier('Biggest', '90000.0000');
        $this->supplier('Middle', '5000.0000');

        $order = $this->supplierNames();

        $this->assertSame(['Biggest', 'Middle', 'Small'], $order);
    }

    /**
     * গ্রাহকের তালিকাতেও একই নিয়ম, উল্টো চিহ্নে।
     *
     * দুইটা পক্ষ দুই দিকে যায় (পাওনা বনাম দেনা), তাই সাজানোর কোডও
     * আলাদা — আর আলাদা কোড মানে একটায় ঠিক করে অন্যটায় ভুলে যাওয়া
     * সম্ভব। এজন্য দুইটাই পরীক্ষা করা হয়।
     */
    public function test_the_customer_list_starts_with_the_biggest_due(): void
    {
        $this->customer('Small', '1000.0000');
        $this->customer('Biggest', '90000.0000');
        $this->customer('Middle', '5000.0000');

        // সরবরাহকারীর মতোই — কেবল এই টেস্টের বানানো তিনটা সারি গোনা হয়,
        // কারণ ডেমো ডাটাতেও গ্রাহক আছে আর তাদের বকেয়া শূন্য
        $names = $this->actingAs($this->user)
            ->get(route('customer.index'))
            ->assertOk()
            ->viewData('customers')
            ->pluck('name_en')
            ->filter(fn (string $name) => in_array($name, ['Small', 'Middle', 'Biggest'], true))
            ->values()
            ->all();

        $this->assertSame(['Biggest', 'Middle', 'Small'], $names);
    }

    public function test_choosing_another_sort_really_reorders_the_rows(): void
    {
        $this->supplier('Small', '1000.0000');
        $this->supplier('Biggest', '90000.0000');
        $this->supplier('Middle', '5000.0000');

        $this->assertSame(['Small', 'Middle', 'Biggest'], $this->supplierNames('payable_asc'));
        $this->assertSame(['Biggest', 'Middle', 'Small'], $this->supplierNames('name'));
    }

    /**
     * সাজানোটা পুরো তালিকার উপর, চলতি পাতার উপর নয়।
     *
     * PHP-তে সাজালে শুধু যে ৫০টা সারি এসেছে সেগুলোই সাজত — ব্যবহারকারী
     * "সবচেয়ে বেশি প্রদেয় আগে" বেছেও দ্বিতীয় পাতায় আরও বড় অঙ্ক পেতেন,
     * আর সেটা ধরা পড়ত কেবল যখন কেউ পাতা উল্টাত।
     */
    public function test_sorting_happens_in_the_database_not_on_the_current_page(): void
    {
        $sql = Supplier::query()
            ->withPayable()
            ->orderByDesc('payable_net')
            ->toSql();

        $this->assertStringContainsString('order by', strtolower($sql));
    }

    /**
     * অজানা বাছাই পাতাটা ভাঙে না, আর কোয়েরিতেও পৌঁছায় না।
     *
     * ব্যবহারকারীর পাঠানো লেখা সরাসরি orderBy()-তে গেলে সেটা SQL
     * ইনজেকশনের দরজা। SortsLists সবসময় একটা ঘোষিত মানচিত্র থেকে বাছে,
     * তাই বাইরের লেখা কখনো কোয়েরিতে যায় না।
     */
    public function test_an_unknown_sort_falls_back_instead_of_breaking(): void
    {
        $this->supplier('Only One', '100.0000');

        foreach (['drop table suppliers', 'name); --', '', 'name_bn'] as $nonsense) {
            $this->actingAs($this->user)
                ->get(route('supplier.index', ['sort' => $nonsense]))
                ->assertOk()
                ->assertSee('Only One');
        }
    }

    // ── ভিউ ও ঘনত্ব সত্যিই পর্দা বদলায় ─────────────────────────────────

    public function test_the_grid_view_switches_the_table_to_cards(): void
    {
        $this->supplier('Card Me', '100.0000');

        $list = $this->actingAs($this->user)->get(route('supplier.index'))->getContent();
        $grid = $this->actingAs($this->user)->get(route('supplier.index', ['view' => 'grid']))->getContent();

        // as-cards ক্লাসটাই বড় পর্দায় কার্ড রূপ ধরে রাখে
        $this->assertStringNotContainsString('as-cards', $list);
        $this->assertStringContainsString('as-cards', $grid);
    }

    public function test_the_density_switch_really_tightens_the_rows(): void
    {
        $this->supplier('Dense', '100.0000');

        $roomy = $this->actingAs($this->user)->get(route('supplier.index'))->getContent();
        $tight = $this->actingAs($this->user)->get(route('supplier.index', ['compact' => 1]))->getContent();

        /*
         * মাপটা এখন টোকেন থেকে, প্যাডিং থেকে নয়।
         *
         * আগে এখানে `py-2.5` ও `py-1.5` খোঁজা হত। প্যাডিং দিয়ে
         * উচ্চতা ঠিক করলে যে ঘরে দুই লাইন লেখা (নাম + কোড) সেই সারিটা
         * লম্বা হয়ে যায়, আর ছকটা অসম দেখায় — এক সারি ৪০px, পাশেরটা ৫৬।
         *
         * পরীক্ষাটার কাজ বদলায়নি: ঘনত্বের সুইচ সত্যিই সারি ছোট
         * করে কি না — কেবল মাপটা এখন এক জায়গায় লেখা।
         */
        $this->assertStringContainsString('var(--row-height)', $roomy);
        $this->assertStringContainsString('var(--row-height-dense)', $tight);

        // উল্টোটাও সত্য হতে হবে — নাহলে দুইটাই ডিফল্ট দিলেও পাশ করত
        $this->assertStringNotContainsString('var(--row-height-dense)', $roomy);
    }

    // ── খোঁজার ঘর বলে দেয় কী দিয়ে খোঁজা যায় ───────────────────────────

    /**
     * placeholder-এ খোঁজার ঘরগুলোর নাম থাকতে হবে।
     *
     * শুধু "খুঁজুন" লিখলে ব্যবহারকারী নাম দিয়েই খোঁজে, আর মোবাইল নম্বর
     * দিয়েও যে খোঁজা যায় তা কখনো জানে না — অথচ হাতে বিল নিয়ে বসা মানুষ
     * প্রায়ই নামটা নয়, নম্বরটাই চেনে।
     */
    public function test_the_search_box_names_what_can_be_searched(): void
    {
        $this->actingAs($this->user)
            ->get(route('supplier.index'))
            ->assertSee(__('supplier::message.search_placeholder'), false);

        $this->actingAs($this->user)
            ->get(route('customer.index'))
            ->assertSee(__('customer::message.search_placeholder'), false);
    }

    // ── আর কোনো মৃত বোতাম নেই ──────────────────────────────────────────

    /**
     * টুলবারের প্রতিটা বোতামের হয় type=submit, নয় একটা onclick/@click।
     *
     * এই টেস্টটাই আসল পাহারাদার। কম্পোনেন্টের ভেতরে একটা সাজসজ্জার
     * বোতাম যোগ করা সবচেয়ে সহজ কাজ, আর সেটা ধরার আর কোনো উপায় নেই:
     * পাতাটা ২০০ দেয়, বোতামটা দেখা যায়, কিছুই ভাঙে না।
     */
    /**
     * Columns মেনু কলাম দেখায়, আর টিক তুললে কলামটা সত্যিই যায়।
     *
     * ── কেন এটা এতদিন অর্ধেক ছিল ───────────────────────────────────
     * টুলবারে মেনুটা লেখা ছিল আর সে `?hide=code` বসাত, কিন্তু টেবিল ওই
     * প্যারামিটারটা পড়তই না — টিক তুললে ঠিকানা বদলাত, পাতা নতুন করে
     * খুলত, আর কলামটা যেমন ছিল তেমনই থাকত। তার উপর কোনো পর্দা টুলবারকে
     * কলামের তালিকাই দিত না, তাই বোতামটা কোথাও দেখাই যেত না।
     *
     * দুই দিক এক সাথে না মিললে এটা আবার সেই "কাজটা আছে বলে দেখায়"
     * বোতাম হয়ে যায়, যেগুলো এই টুলবার থেকে একবার সরানো হয়েছিল।
     */
    public function test_the_columns_menu_really_hides_a_column(): void
    {
        $url = route('inventory.product.index');

        // মেনুটা দেখা যায়, আর তাতে কলামের নাম আছে
        $this->actingAs($this->user)->get($url)
            ->assertOk()
            ->assertSee(__('core.toolbar.columns'))
            ->assertSee(__('inventory::field.barcode'));

        // টিক তুললে ওই কলামের শিরোনামটা আর থাকে না
        $hidden = $this->actingAs($this->user)->get($url.'?hide=barcode')->assertOk();

        /*
         * টেবিলের শিরোনামের ভেতরে খোঁজা, পুরো পাতায় নয়।
         *
         * Columns মেনু নিজেই প্রতিটা কলামের নাম দেখায় — টিক দেওয়ার জন্য
         * নামটা ওখানে থাকতেই হবে। তাই পুরো HTML-এ "বারকোড" খুঁজলে সেটা
         * সবসময়ই পাওয়া যাবে, আর টেস্টটা কখনো কিছু প্রমাণ করত না।
         */
        $this->assertStringNotContainsString(
            __('inventory::field.barcode'),
            $this->tableHead($hidden->getContent()),
            'Columns মেনুতে টিক তোলার পরেও কলামটা টেবিলে রয়ে গেছে।',
        );

        // অন্য কলামগুলো অক্ষত — একটা লুকাতে গিয়ে টেবিল ভেঙে ফেলা নয়
        $this->assertStringContainsString(
            __('inventory::field.code'),
            $this->tableHead($hidden->getContent()),
        );
    }

    /**
     * সব কলাম লুকানোর চেষ্টা করলে টেবিল খালি হয় না।
     *
     * কলামহীন একটা টেবিল মানে সারি আছে অথচ কিছুই পড়া যায় না — তার চেয়ে
     * লুকানোর অনুরোধটা উপেক্ষা করাই ভালো।
     */
    public function test_hiding_every_column_is_ignored(): void
    {
        $all = 'code,name_en,barcode,unit_id,sale_price,is_active';

        $response = $this->actingAs($this->user)
            ->get(route('inventory.product.index').'?hide='.$all)
            ->assertOk();

        $this->assertStringContainsString(
            __('inventory::field.code'),
            $this->tableHead($response->getContent()),
            'সব কলাম লুকানোর অনুরোধে টেবিলটা কলামহীন হয়ে গেছে।',
        );
    }

    /** টেবিলের শিরোনামের অংশটুকু — বাকি পাতাটা বাদ। */
    private function tableHead(string $html): string
    {
        return preg_match('/<thead\b.*?<\/thead>/s', $html, $m) === 1 ? $m[0] : '';
    }

    public function test_no_button_in_the_toolbar_is_decoration(): void
    {
        $source = File::get(resource_path('views/components/ui/toolbar.blade.php'));

        // মন্তব্য আগে বাদ — কম্পোনেন্টের ব্যাখ্যায় পুরনো মৃত বোতামগুলোর
        // কথা লেখা আছে, আর সেই "<button>" শব্দটাও এই টেস্ট ধরে ফেলেছিল
        $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        preg_match_all('/<button\b[^>]*>/s', $source, $matches);

        $this->assertNotEmpty($matches[0], 'টুলবারে একটাও বোতাম নেই — কম্পোনেন্টটা কি সরে গেছে?');

        foreach ($matches[0] as $button) {
            $doesSomething = str_contains($button, 'type="submit"')
                || str_contains($button, 'onclick=')
                || str_contains($button, '@click');

            $this->assertTrue(
                $doesSomething,
                'টুলবারের একটা বোতাম কিছুই করে না — সাজসজ্জার বোতাম মানে '
                ."ব্যবহারকারী ক্লিক করবে আর কিছুই হবে না:\n{$button}"
            );
        }
    }

    /**
     * তালিকার প্রতিটা পর্দা যে টুলবার দেখায়, সেটা শেয়ার্ড কম্পোনেন্টই।
     *
     * কেউ নিজের টুলবার লিখলে একটায় Sort বাঁয়ে আর অন্যটায় ডানে চলে যেত,
     * আর ব্যবহারকারীকে প্রতিটা স্ক্রিন আলাদা করে শিখতে হত।
     */
    public function test_every_list_screen_uses_the_shared_toolbar(): void
    {
        $registry = app(ModuleRegistry::class);
        $offenders = [];

        foreach ($registry->all() as $module) {
            $views = $module->dir('Resources', 'views');

            if (! is_dir($views)) {
                continue;
            }

            foreach (File::allFiles($views) as $file) {
                if (! str_ends_with($file->getFilename(), 'index.blade.php')) {
                    continue;
                }

                $source = File::get($file->getPathname());

                // যে তালিকায় খোঁজার ঘর আছে অথচ শেয়ার্ড টুলবার নেই,
                // সেখানে কেউ হাতে টুলবার লিখেছে
                if (str_contains($source, 'name="q"') && ! str_contains($source, 'x-ui.toolbar')) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $offenders, 'এই তালিকাগুলো নিজেরা টুলবার লিখেছে।');
    }

    // ── সহায়ক ───────────────────────────────────────────────────────────

    private function supplier(string $name, string $opening): void
    {
        app(SupplierService::class)->create([
            'name_en' => $name,
            'credit_limit' => 0,
            'credit_days' => 0,
            'opening_balance' => $opening,
            'opening_date' => '2026-07-01',
        ]);
    }

    /** @return list<string> */
    /**
     * পর্দার সারিগুলো, কিন্তু কেবল এই টেস্টের নিজের বানানো তিনটা।
     *
     * ডেমো ডাটাতেও সরবরাহকারী আছে, আর তাদের প্রদেয় শূন্য। পুরো তালিকাটা
     * মিলিয়ে দেখলে ডেমোতে একজন সরবরাহকারী যোগ হলেই এই টেস্ট ভাঙত, অথচ
     * সাজানোর কোডে কিছুই বদলায়নি। যা পরীক্ষা করার কথা সেটা হলো তিনটা
     * সারির আপেক্ষিক ক্রম, তাই বাকিগুলো বাদ।
     *
     * @return list<string>
     */
    private function supplierNames(?string $sort = null): array
    {
        $response = $this->actingAs($this->user)
            ->get(route('supplier.index', array_filter(['sort' => $sort])))
            ->assertOk();

        return $response->viewData('suppliers')
            ->pluck('name_en')
            ->filter(fn (string $name) => in_array($name, ['Small', 'Middle', 'Biggest'], true))
            ->values()
            ->all();
    }

    private function customer(string $name, string $opening): void
    {
        app(CustomerService::class)->create([
            'name_en' => $name,
            'credit_limit' => 0,
            'credit_days' => 0,
            'opening_balance' => $opening,
            'opening_date' => '2026-07-01',
        ]);
    }
}
