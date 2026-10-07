<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Inventory;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\SerialNumber;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\SerialNumberService;
use App\Modules\MasterData\Models\Unit;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ওয়ারেন্টির দাবি এল, আর দেখানোর মতো কোনো পিস ছিল না।
 *
 * ── ⓘ লট থাকতে সিরিয়াল কেন ──────────────────────────────────────────
 * লট একটা **দল** — একসাথে আসা পঞ্চাশ বস্তা। ⚠️ ওতে রিকলের উত্তর মেলে,
 * ⛔ কিন্তু ওয়ারেন্টির নয়: *"এই একটা পিস কবে কার কাছে গেল"*। লট দিয়ে
 * উত্তর দিতে গেলে বলতে হত *"এই পঞ্চাশটার কোনো একটা"*, আর ওটা কোনো
 * উত্তর নয়।
 *
 * ── ⚠️ এই ফাইল যা পাহারা দেয় ────────────────────────────────────────
 *   ১. একই নম্বর দুইবার খাতায় বসে না
 *   ২. হরফ বদলালেও সেটা একই নম্বর
 *   ৩. যে পণ্যে টিক নেই তাতে নম্বর বসে না
 *   ৪. ওয়ারেন্টি শুরু হয় **বেরোনোর** দিনে, ঢোকার দিনে নয়
 *   ৫. মাস শূন্য মানে ওয়ারেন্টি নেই, "আজই শেষ" নয়
 *   ৬. যে পিস বেরিয়ে গেছে সেটা আবার বেরোতে পারে না
 *
 * ⓘ (৪) সবচেয়ে দামি। ⛔ ভুল হলে গুদামে ছয় মাস পড়ে থাকা জিনিসের
 * ওয়ারেন্টি ছয় মাস খেয়ে ফেলত, আর ক্রেতা তাঁর প্রাপ্যটুকু পেতেন না —
 * ⚠️ আর ওটা ধরা পড়ত কেবল দাবির দিনে, যেদিন আর কিছু করার থাকে না।
 */
final class AWarrantyClaimHadNoPieceToPointAtTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private User $owner;

    private Product $tv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        $this->tv = $this->tracked('টিভি');
    }

    // ── ১ ও ২ · নম্বরটা একটাই ────────────────────────────────────────

    public function test_the_same_number_cannot_be_put_on_the_books_twice(): void
    {
        $this->service()->receive($this->tv, ['SN-1001']);

        $this->expectException(ValidationException::class);
        $this->service()->receive($this->tv, ['SN-1002', 'SN-1001']);
    }

    public function test_a_number_in_another_case_is_the_same_number(): void
    {
        /*
         * ⛔ হুবহু মেলানো চাইলে `sn-1001` আর `SN-1001` দুইটা আলাদা
         * পিস হত, আর ওয়ারেন্টির দাবিতে দুইটা কাগজ বেরোত।
         */
        $this->service()->receive($this->tv, ['SN-1001']);

        try {
            $this->service()->receive($this->tv, ['sn-1001']);
            $this->fail('ছোট হরফে একই নম্বর আবার বসে গেছে — অর্থাৎ মানুষের চোখে '
                .'একই নম্বর খাতায় দুইটা পিস।');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_the_counter_finds_a_piece_whatever_case_it_is_typed_in(): void
    {
        $this->service()->receive($this->tv, ['SN-1001']);

        $this->assertNotNull(SerialNumber::query()->numbered('  sn-1001 ')->first(),
            'কাউন্টারে ছোট হরফে টাইপ করা নম্বরে পিসটা পাওয়া যাচ্ছে না — অথচ '
            .'ওয়ারেন্টি নিয়ে আসা মানুষটা কাগজ দেখেই টাইপ করেন।');
    }

    // ── ৩ · টিক ছাড়া নম্বর নয় ────────────────────────────────────────

    public function test_a_product_without_the_tick_takes_no_numbers(): void
    {
        /*
         * ⛔ মালিকের সিদ্ধান্ত: পণ্যে একটা টিক, বৈশ্বিক মোড নয়।
         * ⚠️ টিক ছাড়া নম্বর বসতে দিলে চাল-ডালের বস্তাতেও নম্বর বসত।
         */
        $rice = $this->tracked('চাল', tracked: false);

        $this->expectException(ValidationException::class);
        $this->service()->receive($rice, ['SN-9001']);
    }

    // ── ৪ ও ৫ · ওয়ারেন্টির ঘড়ি ───────────────────────────────────────

    public function test_the_warranty_clock_starts_when_the_piece_goes_out(): void
    {
        $this->service()->receive($this->tv, ['SN-1001'], [
            'warehouse_id' => $this->warehouse->id,
            'received_on' => '2026-01-01',
        ]);

        $out = $this->service()->issue(['SN-1001'], [
            'issued_on' => '2026-07-01',
            'warranty_months' => 12,
            'sold_to' => 'একজন ক্রেতা',
        ])[0];

        $this->assertSame('2026-07-01', $out->warranty_from->toDateString(),
            'ওয়ারেন্টি ঢোকার দিন থেকে গোনা হচ্ছে — তাহলে গুদামে ছয় মাস পড়ে '
            .'থাকা জিনিসের ছয় মাস ওয়ারেন্টি এমনিতেই চলে যেত।');

        $this->assertSame('2027-07-01', $out->warranty_to->toDateString());

        $this->assertTrue($out->underWarranty(Carbon::parse('2027-06-01')));
        $this->assertFalse($out->underWarranty(Carbon::parse('2027-08-01')));
    }

    public function test_no_months_means_no_warranty_not_a_warranty_that_ends_today(): void
    {
        /*
         * ⛔ শূন্য মাসের একটা তারিখ বসালে ওটা *"আজই শেষ"* বলত, যা
         * মিথ্যা — ⚠️ জিনিসটার ওয়ারেন্টি কোনোদিন ছিলই না।
         */
        $this->service()->receive($this->tv, ['SN-1001']);

        $out = $this->service()->issue(['SN-1001'], ['warranty_months' => 0])[0];

        $this->assertNull($out->warranty_to,
            'ওয়ারেন্টি নেই এমন পিসেও একটা শেষ-তারিখ বসেছে।');

        $this->assertFalse($out->underWarranty());
    }

    public function test_a_piece_with_no_warranty_written_down_is_not_under_warranty(): void
    {
        /*
         * ⛔ উল্টোটা ধরলে প্রতিটা অলিখিত পিস আজীবন ওয়ারেন্টিতে থাকত,
         * আর প্রতিটা দাবি মেনে নিতে হত।
         */
        $this->service()->receive($this->tv, ['SN-1001']);

        $piece = SerialNumber::query()->numbered('SN-1001')->firstOrFail();

        $this->assertFalse($piece->underWarranty());
    }

    // ── ৬ · একটা পিস একবারই বেরোয় ────────────────────────────────────

    public function test_a_piece_that_has_gone_out_cannot_go_out_again(): void
    {
        /*
         * ⛔ পারলে একই নম্বর দুইজন ক্রেতার কাছে যেত, আর ওয়ারেন্টির
         * দাবিতে দুইজনের কাগজেই ঐ এক নম্বর থাকত।
         */
        $this->service()->receive($this->tv, ['SN-1001']);
        $this->service()->issue(['SN-1001'], ['warranty_months' => 12]);

        $this->expectException(ValidationException::class);
        $this->service()->issue(['SN-1001'], ['warranty_months' => 12]);
    }

    // ── দরজা ──────────────────────────────────────────────────────────

    public function test_the_search_screen_opens_and_finds_the_piece(): void
    {
        $this->service()->receive($this->tv, ['SN-1001'], [
            'warehouse_id' => $this->warehouse->id,
        ]);

        $this->actingAs($this->owner)
            ->get(route('inventory.serial.index', ['serial' => 'sn-1001']))
            ->assertOk()
            ->assertSee('SN-1001');
    }

    public function test_the_screen_is_closed_without_the_key(): void
    {
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $this->actingAs($salesman)
            ->get(route('inventory.serial.index'))
            ->assertForbidden();
    }

    // ── সহায়ক ─────────────────────────────────────────────────────────

    private function service(): SerialNumberService
    {
        return app(SerialNumberService::class);
    }

    private function tracked(string $name, bool $tracked = true): Product
    {
        return Product::query()->create([
            'code' => 'SRL-'.mb_substr(md5($name.microtime()), 0, 8),
            'name_en' => $name,
            'name_bn' => $name,
            'unit_id' => Unit::query()->orderBy('id')->firstOrFail()->id,
            'track_serial' => $tracked,
            'is_active' => true,
        ]);
    }
}
