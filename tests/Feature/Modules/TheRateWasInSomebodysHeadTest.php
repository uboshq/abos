<?php

declare(strict_types=1);

namespace Tests\Feature\Modules;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\MasterData\Models\Brand;
use App\Modules\Sales\Models\CommissionRule;
use App\Modules\Sales\Models\Scheme;
use App\Modules\Sales\Services\CommissionEngine;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SchemeService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * হারটা কারও মাথায় ছিল, খাতায় ছিল না।
 *
 * ── কেন এটাই সবচেয়ে দামি ফাঁক ────────────────────────────────────────
 * ABOS-এ কমিশনের **দাবি** আগে থেকেই আছে — কাকে কত দেওয়া হলো, কোম্পানির
 * কাছে কত পাওনা। কিন্তু **কত দেওয়ার কথা ছিল** সেটা কোথাও লেখা ছিল না,
 * তাই প্রতিবার কেউ হাতে হার বসিয়েছেন।
 *
 * মাস-শেষে কোম্পানির কাছে দাবি করার সময় প্রশ্নটা ওঠে "এই হারটা কে ঠিক
 * করল" — আর উত্তরটা ছিল একজন মানুষের স্মৃতি। পরিবেশনের ব্যবসা এই
 * নিয়মের উপরেই চলে।
 *
 * ── এই ফাইলটা যা পাহারা দেয় ─────────────────────────────────────────
 * অঙ্কটা নয় শুধু — **অঙ্কটা কোথা থেকে এল**। প্রতিটা পরীক্ষা একটা করে
 * ভুল ধরে যা টাকার অঙ্কে সরাসরি দেখা যায় না: ছাড়ের উপর কমিশন, মেয়াদ
 * পেরোনো স্কিম, ব্র্যান্ডের স্কিমে গোটা বিলের টাকা, সিঁড়ির উপরের ধাপ
 * থেকে পড়ে যাওয়া।
 */
class TheRateWasInSomebodysHeadTest extends TestCase
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
    }

    private function engine(): CommissionEngine
    {
        return app(CommissionEngine::class);
    }

    /** @param  array<string, mixed>  $extra */
    private function scheme(array $extra = []): Scheme
    {
        return Scheme::query()->create(array_merge([
            'company_id' => $this->company->id,
            'code' => 'SCH-'.str_pad((string) (Scheme::query()->count() + 1), 3, '0', STR_PAD_LEFT),
            'name' => 'পরীক্ষার স্কিম',
            'basis' => Scheme::VALUE,
            'applies_to' => Scheme::ALL,
            'valid_from' => '2026-08-01',
            'valid_to' => '2026-08-31',
            'status' => Scheme::ACTIVE,
        ], $extra));
    }

    /** @param  array<string, mixed>  $extra */
    private function rule(Scheme $scheme, array $extra = []): CommissionRule
    {
        return CommissionRule::query()->create(array_merge([
            'company_id' => $this->company->id,
            'scheme_id' => $scheme->id,
            'earner_role' => 'SR',
            'rate_percent' => '2',
            'slab_from' => '0',
            'slab_to' => null,
            'level_order' => 1,
        ], $extra));
    }

    /**
     * একটা বিল — নির্দিষ্ট দর, নির্দিষ্ট ছাড়।
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function invoice(array $lines, string $date = '2026-08-10')
    {
        $service = app(SalesInvoiceService::class);

        $invoice = $service->create([
            'customer_id' => Customer::query()->value('id'),
            'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
            'trx_date' => $date,
        ], $lines);

        return $service->confirm($invoice)->fresh(['lines', 'customer']);
    }

    private function product(): Product
    {
        return Product::query()->orderBy('id')->firstOrFail();
    }

    /* ── ভিত ─────────────────────────────────────────────────────── */

    /**
     * সবচেয়ে সাধারণ ঘটনা: এক স্কিম, এক হার, এক জন।
     */
    public function test_a_flat_scheme_pays_its_rate(): void
    {
        $this->rule($this->scheme());

        $result = $this->engine()->preview(
            $this->invoice([['product_id' => $this->product()->id, 'qty' => '10', 'rate' => '1000']]),
        );

        $this->assertSame(0, bccomp($result['base'], '10000', 4), 'ভিত্তিটাই ভুল।');
        $this->assertCount(1, $result['lines']);
        $this->assertSame(0, bccomp($result['total'], '200', 4), '১০,০০০-এর ২% = ২০০ হওয়ার কথা।');
    }

    /**
     * কমিশন ছাড়ের উপর নয়, যা সত্যিই নেওয়া হলো তার উপর।
     *
     * ── কেন এটা টাকার প্রশ্ন, হিসাবের নয় ────────────────────────────
     * মোটের উপর দিলে বিক্রয়কর্মী **নিজের দেওয়া ছাড়ের উপরেও** কমিশন
     * পেতেন — অর্থাৎ ছাড় দেওয়াটাই তাঁর জন্য লাভজনক হত। ডিপো তখন দুইবার
     * হারত: একবার ছাড়ে, আরেকবার কমিশনে।
     */
    public function test_commission_is_not_paid_on_the_discount_given_away(): void
    {
        $this->rule($this->scheme());

        $result = $this->engine()->preview(
            /*
             * ছাড়টা সারিতে, বিলের মাথায় নয় — ABOS-এ ওখানেই বসে, আর
             * বিলের `total` ওই সারিগুলোর যোগফল।
             */
            $this->invoice([[
                'product_id' => $this->product()->id,
                /*
                 * ছাড়টা ছোট রাখা — ২০% ছাড়ে দাম-অনুমোদনের পাহারা
                 * বিলটাকে খসড়ায় আটকে রাখে, আর তখন এই পরীক্ষাটা
                 * কমিশন নয়, ওই পাহারাটা মাপত।
                 */
                'qty' => '10', 'rate' => '1000', 'discount' => '500',
            ]]),
        );

        $this->assertSame(0, bccomp($result['base'], '9500', 4),
            'ছাড় বাদ যায়নি — ভিত্তিটা মোটের উপরেই হিসাব হচ্ছে।');

        $this->assertSame(0, bccomp($result['total'], '190', 4),
            '৯,৫০০-এর ২% = ১৯০; ছাড়ের উপরেও কমিশন দেওয়া হচ্ছে।');
    }

    /* ── মেয়াদ ───────────────────────────────────────────────────── */

    /**
     * মেয়াদ পেরোনো স্কিম টাকা দেয় না।
     *
     * দুই সপ্তাহের ঈদের অফার যেন পরের বছরও টাকা দিতে না থাকে।
     */
    public function test_a_scheme_outside_its_dates_pays_nothing(): void
    {
        $this->rule($this->scheme(['valid_from' => '2026-06-01', 'valid_to' => '2026-06-30']));

        $result = $this->engine()->preview(
            $this->invoice([['product_id' => $this->product()->id, 'qty' => '10', 'rate' => '1000']]),
        );

        $this->assertSame([], $result['lines'],
            'মেয়াদের বাইরের স্কিমও টাকা দিচ্ছে।');

        $this->assertNotNull($result['reason'], 'কেন কিছু হলো না, সেটা বলা হয়নি।');
    }

    /**
     * খসড়া স্কিমও টাকা দেয় না।
     *
     * অর্ধেক লেখা স্কিম চালু হয়ে গেলে হার বসানোর মাঝপথেই বিল কাটা
     * শুরু হত, আর কোন সারিগুলো ওই অবস্থায় কাটা হয়েছিল তা পরে বলা
     * যেত না।
     */
    public function test_a_draft_scheme_pays_nothing(): void
    {
        $this->rule($this->scheme(['status' => Scheme::DRAFT]));

        $this->assertSame([], $this->engine()->preview(
            $this->invoice([['product_id' => $this->product()->id, 'qty' => '10', 'rate' => '1000']]),
        )['lines'], 'খসড়া স্কিমও টাকা দিচ্ছে।');
    }

    /* ── সিঁড়ি ───────────────────────────────────────────────────── */

    /**
     * যত বেশি বিক্রি, তত বেশি হার — আর ঠিক ধাপটাই।
     */
    public function test_a_slab_scheme_uses_the_band_the_sale_falls_in(): void
    {
        $scheme = $this->scheme(['basis' => Scheme::SLAB]);

        $this->rule($scheme, ['rate_percent' => '2', 'slab_from' => '0', 'slab_to' => '500000']);
        $this->rule($scheme, ['rate_percent' => '3', 'slab_from' => '500000.0001', 'slab_to' => null]);

        $small = $this->engine()->preview(
            $this->invoice([['product_id' => $this->product()->id, 'qty' => '10', 'rate' => '10000']]),
        );

        $this->assertSame(0, bccomp($small['total'], '2000', 4),
            '১ লাখে ২% = ২,০০০ হওয়ার কথা।');

        $big = $this->engine()->preview(
            $this->invoice([['product_id' => $this->product()->id, 'qty' => '60', 'rate' => '10000']]),
        );

        $this->assertSame(0, bccomp($big['total'], '18000', 4),
            '৬ লাখে ৩% = ১৮,০০০ হওয়ার কথা।');
    }

    /**
     * সিঁড়ির শেষ ধাপটা খোলা — নাহলে সেরা মাসটা কিছুই পায় না।
     *
     * ── কেন এটার নিজের পরীক্ষা ──────────────────────────────────────
     * উপরের পরীক্ষাটা খোলা ধাপ **থাকা** অবস্থায় দেখে। এটা দেখে ধাপটা
     * বন্ধ থাকলে কী হয়: বছরের সবচেয়ে বড় বিলটা ছকের উপর দিয়ে বেরিয়ে
     * যায় আর শূন্য পায় — যা ধাপে-ধাপে স্কিমের ঠিক উল্টো।
     *
     * ভুলটা নীরব: কেউ অভিযোগ করে না যে "আমার সবচেয়ে ভালো মাসে কমিশন
     * আসেনি", কারণ ধরে নেওয়া হয় হিসাব ঠিকই আছে।
     */
    public function test_a_sale_above_every_closed_band_earns_nothing_and_says_why(): void
    {
        $scheme = $this->scheme(['basis' => Scheme::SLAB]);

        $this->rule($scheme, ['rate_percent' => '2', 'slab_from' => '0', 'slab_to' => '100000']);

        $result = $this->engine()->preview(
            $this->invoice([['product_id' => $this->product()->id, 'qty' => '60', 'rate' => '10000']]),
        );

        $this->assertSame([], $result['lines']);

        $this->assertNotNull($result['reason'],
            'সিঁড়ির উপর দিয়ে বেরিয়ে গেল অথচ পর্দা চুপ — কেউ কোনোদিন জানত না।');
    }

    /* ── কে পায় ──────────────────────────────────────────────────── */

    /**
     * একই স্কিম তিন ভূমিকাকে তিন হারে দেয়।
     */
    public function test_one_scheme_pays_three_roles_at_three_rates(): void
    {
        $scheme = $this->scheme();

        $this->rule($scheme, ['earner_role' => 'SR', 'rate_percent' => '2', 'level_order' => 1]);
        $this->rule($scheme, ['earner_role' => 'ASM', 'rate_percent' => '1', 'level_order' => 2]);
        $this->rule($scheme, ['earner_role' => 'DSM', 'rate_percent' => '0.5', 'level_order' => 3]);

        $result = $this->engine()->preview(
            $this->invoice([['product_id' => $this->product()->id, 'qty' => '10', 'rate' => '1000']]),
        );

        $this->assertCount(3, $result['lines']);

        $this->assertSame(0, bccomp($result['total'], '350', 4),
            '২% + ১% + ০.৫% = ৩.৫% of ১০,০০০ = ৩৫০।');

        $this->assertSame(
            ['SR', 'ASM', 'DSM'],
            array_column($result['lines'], 'role'),
            'শৃঙ্খলের ক্রম হারিয়ে গেছে — রিপোর্টে DSM-এর ভাগ দ্বিতীয় SR-এর মতো দেখাবে।',
        );
    }

    /**
     * ঢাকাঢাকি ধাপ লেখা থাকলেও একই ভূমিকা দুইবার পায় না।
     *
     * ── কেন এই পাহারা ───────────────────────────────────────────────
     * ধাপ লেখা মানুষের কাজ, আর ০–৫ লাখ ও ৩–৮ লাখ পাশাপাশি লিখে ফেলা
     * সহজ। ইঞ্জিন যদি সব মেলা নিয়ম যোগ করত, চার লাখের বিলে SR দুইবার
     * টাকা পেতেন — আর ভুলটা ধরা পড়ত কেবল কেউ যোগফল মিলিয়ে দেখলে।
     */
    public function test_overlapping_bands_never_pay_the_same_role_twice(): void
    {
        $scheme = $this->scheme(['basis' => Scheme::SLAB]);

        $this->rule($scheme, ['rate_percent' => '2', 'slab_from' => '0', 'slab_to' => '500000']);
        $this->rule($scheme, ['rate_percent' => '3', 'slab_from' => '300000', 'slab_to' => '800000']);

        $result = $this->engine()->preview(
            $this->invoice([['product_id' => $this->product()->id, 'qty' => '40', 'rate' => '10000']]),
        );

        $this->assertCount(1, $result['lines'],
            'একই ভূমিকা দুইটা ধাপ থেকে দুইবার টাকা পেয়েছে।');
    }

    /* ── কীসের উপর ───────────────────────────────────────────────── */

    /**
     * থোক টাকার চুক্তিতে হার লাগে না।
     */
    public function test_a_fixed_agreement_pays_the_fixed_amount(): void
    {
        $this->rule($this->scheme(), ['rate_percent' => null, 'fixed_amount' => '2000']);

        $result = $this->engine()->preview(
            $this->invoice([['product_id' => $this->product()->id, 'qty' => '10', 'rate' => '1000']]),
        );

        $this->assertSame(0, bccomp($result['total'], '2000', 4));
    }

    /**
     * ব্র্যান্ডের স্কিম গোটা বিলের উপর টাকা দেয় না।
     *
     * ── কেন এটাই DMS-এর ধরা ভুলটা ───────────────────────────────────
     * একটা স্কিম যদি একটা ব্র্যান্ডের দিকে তাক করা হয় আর বিলে তিনটা
     * ব্র্যান্ড থাকে, গোটা বিলের উপর টাকা দেওয়া মানে বাকি দুইটার
     * জন্যও দেওয়া। DMS-এ ছয়টা লক্ষ্যের তিনটা কোনোদিন কাজই করেনি —
     * পর্দায় বাছাই ছিল, প্রভাব ছিল না।
     */
    public function test_a_brand_scheme_pays_only_on_that_brands_lines(): void
    {
        /*
         * ব্র্যান্ড দুইটা এখানেই বানানো — ডেমো ডেটার উপর ভরসা করে নয়।
         *
         * ---- কেন, ৩০ আগস্ট ২০২৬ ----
         * প্রথমে লেখা ছিল "ডেমোতে দুই ব্র্যান্ডের পণ্য না থাকলে স্কিপ"।
         * চালিয়ে দেখা গেল ডেমোর **কোনো** পণ্যেই ব্র্যান্ড বসানো নেই,
         * তাই পরীক্ষাটা প্রতিবার স্কিপ হত।
         *
         * স্কিপ করা পরীক্ষা সবুজ তালিকায় থাকে অথচ কিছুই পাহারা দেয় না --
         * আর এটাই সেই ভুলটা যেটা DMS-এ ছয়টা লক্ষ্যের তিনটাকে নীরবে
         * অকেজো করে রেখেছিল।
         */
        $mine = $this->product();

        $other = Product::query()->where('id', '!=', $mine->id)->orderBy('id')->firstOrFail();

        $mineBrand = Brand::query()->create([
            'company_id' => $this->company->id,
            'code' => 'BR-MINE', 'name_en' => 'Scheme Brand', 'name_bn' => 'স্কিমের ব্র্যান্ড',
        ]);

        $otherBrand = Brand::query()->create([
            'company_id' => $this->company->id,
            'code' => 'BR-OTHER', 'name_en' => 'Other Brand', 'name_bn' => 'অন্য ব্র্যান্ড',
        ]);

        $mine->forceFill(['brand_id' => $mineBrand->id])->save();
        $other->forceFill(['brand_id' => $otherBrand->id])->save();

        $this->rule($this->scheme([
            'applies_to' => Scheme::BRAND,
            'target_id' => $mineBrand->id,
        ]));

        $result = $this->engine()->preview($this->invoice([
            ['product_id' => $mine->id, 'qty' => '10', 'rate' => '1000'],
            ['product_id' => $other->id, 'qty' => '10', 'rate' => '1000'],
        ]));

        $this->assertSame(0, bccomp($result['base'], '20000', 4), 'বিলের ভিত্তিই বদলে গেছে।');

        $this->assertSame(0, bccomp($result['lines'][0]['base'], '10000', 4),
            'স্কিমটা নিজের ব্র্যান্ডের বাইরের সারির উপরেও টাকা দিচ্ছে।');

        $this->assertSame(0, bccomp($result['total'], '200', 4));
    }

    /**
     * পরিমাণের স্কিমে ধাপও পরিমাণে গোনা হয়।
     *
     * ── কেন এটা আলাদা করে দেখা দরকার ────────────────────────────────
     * "পাঁচশো বস্তার উপরে" ধাপটা যদি টাকার অঙ্কের সাথে মেলানো হত,
     * তাহলে দশ বস্তার একটা বিলও (দাম ১০,০০০) সবসময় সবচেয়ে উঁচু ধাপে
     * পড়ত — আর কেউ বুঝত না কেন ছোট বিলে সবচেয়ে বেশি কমিশন।
     */
    public function test_a_volume_scheme_counts_bags_not_taka(): void
    {
        $scheme = $this->scheme(['basis' => Scheme::VOLUME]);

        $this->rule($scheme, [
            'rate_percent' => null, 'fixed_amount' => '20',
            'slab_from' => '0', 'slab_to' => '500',
        ]);
        $this->rule($scheme, [
            'rate_percent' => null, 'fixed_amount' => '30',
            'slab_from' => '500.0001', 'slab_to' => null,
        ]);

        $result = $this->engine()->preview(
            $this->invoice([['product_id' => $this->product()->id, 'qty' => '10', 'rate' => '1000']]),
        );

        $this->assertSame(0, bccomp($result['lines'][0]['base'], '10', 4),
            'ভিত্তিটা পরিমাণে নয়, টাকায় গোনা হয়েছে।');

        $this->assertSame(0, bccomp($result['total'], '20', 4),
            'দশ বস্তা নিচের ধাপে পড়ার কথা — উপরের ধাপে পড়েছে।');
    }

    /**
     * কোনো স্কিম না থাকা একটা উত্তর, ব্যর্থতা নয়।
     *
     * বেশিরভাগ বিলে কিছুই আসে না। সেটাকে ভুল হিসেবে দেখালে পর্দা
     * প্রতিদিন লাল থাকত, আর মানুষ লাল দেখা বন্ধ করে দিত।
     */
    public function test_no_scheme_is_an_answer_with_a_reason(): void
    {
        $result = $this->engine()->preview(
            $this->invoice([['product_id' => $this->product()->id, 'qty' => '1', 'rate' => '100']]),
        );

        $this->assertSame([], $result['lines']);
        $this->assertSame(0, bccomp($result['total'], '0', 4));
        $this->assertNotEmpty($result['reason']);
    }

    /* ── চালু করার আগের পাহারা ───────────────────────────────────── */

    /**
     * হার ছাড়া স্কিম চালু হয় না।
     *
     * চালু হয়ে গেলে ওটা প্রতিটা বিলে "কিছুই দেয় না" — অথচ তালিকায়
     * চালু লেখা থাকে, তাই কেউ ধরে নেন স্কিমটা কাজ করছে।
     */
    public function test_a_scheme_with_no_rate_cannot_go_live(): void
    {
        $scheme = $this->scheme(['status' => Scheme::DRAFT]);

        $this->expectException(ValidationException::class);

        app(SchemeService::class)->activate($scheme);
    }

    /**
     * সিঁড়ির উপরের ধাপ বন্ধ রেখে চালু করা যায় না।
     *
     * ── কেন চালুর সময়ই আটকানো ───────────────────────────────────────
     * চালু হওয়ার পর ভুলটা আর পর্দার ভুল নয়, টাকার ভুল — আর টাকা
     * দেওয়ার পর ফেরত আনতে হয়। ভুলটা নীরবও: কেউ অভিযোগ করে না যে
     * "আমার সেরা মাসে কমিশন আসেনি", কারণ ধরে নেওয়া হয় হিসাব ঠিকই আছে।
     */
    public function test_a_ladder_with_a_closed_top_cannot_go_live(): void
    {
        $scheme = $this->scheme(['status' => Scheme::DRAFT, 'basis' => Scheme::SLAB]);

        $this->rule($scheme, ['slab_from' => '0', 'slab_to' => '100000']);
        $this->rule($scheme, ['slab_from' => '100000.0001', 'slab_to' => '500000']);

        try {
            app(SchemeService::class)->activate($scheme);
            $this->fail('উপরের ধাপ বন্ধ থাকা সত্ত্বেও স্কিমটা চালু হয়ে গেল।');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('SR', $e->errors()['status'][0],
                'কোন ভূমিকার সিঁড়ি বন্ধ, সেটা বলা হয়নি।');
        }

        /* খোলা ধাপটা দিলেই চালু হয় */
        $this->rule($scheme, ['slab_from' => '500000.0001', 'slab_to' => null]);

        $this->assertSame(Scheme::ACTIVE, app(SchemeService::class)->activate($scheme->fresh())->status);
    }

    /**
     * চালু স্কিমের হার বদলানো যায় না।
     *
     * ── কেন ─────────────────────────────────────────────────────────
     * ইঞ্জিন হিসাব করে **বর্তমান** নিয়ম দেখে। চালু স্কিমের হার বদলালে
     * গত মাসে যা দেওয়া হয়েছে আর আজ যা হিসাব হচ্ছে দুইটা আলাদা হয়ে
     * যেত, আর কোনটা ঠিক তা বলার উপায় থাকত না।
     */
    public function test_a_live_schemes_rate_cannot_be_changed(): void
    {
        $scheme = $this->scheme();
        $this->rule($scheme);

        $this->expectException(ValidationException::class);

        app(SchemeService::class)->addRule($scheme, [
            'earner_role' => 'ASM', 'rate_percent' => '5',
            'slab_from' => '0', 'level_order' => 2,
        ]);
    }

    /* ── পর্দা ────────────────────────────────────────────────────── */

    /**
     * দুইটা পাতাই খোলে, আর স্কিমটা নিজের ধাপগুলো দেখায়।
     *
     * ── কেন এটার আলাদা পরীক্ষা ──────────────────────────────────────
     * উপরের সবগুলো সেবা ও ইঞ্জিন ধরে। ওগুলো সবুজ থাকা অবস্থায়ও পাতাটা
     * ৫০০ দিতে পারে — আর একবার দিয়েছেও: টুলবারকে বাছাইয়ের **মান**
     * পাঠানো হয়েছিল, বিকল্পের তালিকার বদলে।
     */
    public function test_both_screens_open_and_show_the_bands(): void
    {
        $scheme = $this->scheme();
        $this->rule($scheme, ['earner_role' => 'SR', 'rate_percent' => '2.5']);

        $this->get(route('sales.scheme.index'))
            ->assertOk()
            ->assertSee($scheme->code);

        $this->get(route('sales.scheme.show', $scheme))
            ->assertOk()
            ->assertSee('SR')
            ->assertSee('2.5%', false);
    }

    /**
     * চালু স্কিমের পাতায় ধাপ যোগ করার ফর্মটাই থাকে না।
     *
     * নিয়মটা সেবায় আটকানো আছে, কিন্তু ফর্মটা দেখিয়ে তারপর ভুল-বার্তা
     * দেওয়া মানে পর্দা এমন একটা কাজ করতে বলছে যেটা সে নিজেই নেয় না —
     * ঠিক যে ভুলটা জাবেদার ছাপায় ছিল।
     */
    public function test_a_live_scheme_offers_no_form_to_change_it(): void
    {
        $scheme = $this->scheme();
        $this->rule($scheme);

        $this->get(route('sales.scheme.show', $scheme))
            ->assertOk()
            ->assertDontSee('name="earner_role"', false);
    }

    /**
     * মেয়াদ পেরিয়ে গেছে অথচ তালিকায় "চালু" — সেটা বলা হয়।
     *
     * হিসাবে ভুল হয় না ([[Scheme::isLiveOn()]] তারিখ দেখে), কিন্তু
     * তালিকায় সারিটা চালু লেখা থাকলে কেউ ধরে নেন স্কিমটা চলছে —
     * তারপর গ্রাহককে সেই কথা দিয়ে বসেন।
     */
    public function test_a_lapsed_scheme_is_visibly_lapsed(): void
    {
        $lapsed = $this->scheme(['valid_from' => '2026-06-01', 'valid_to' => '2026-06-30']);
        $live = $this->scheme(['valid_from' => now()->subDay(), 'valid_to' => now()->addMonth()]);

        $this->assertTrue($lapsed->hasLapsed());
        $this->assertFalse($live->hasLapsed());
    }
}
