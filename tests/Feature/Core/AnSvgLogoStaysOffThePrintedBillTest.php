<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\BranchSettings;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\DirectSaleService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ⛔ SVG লোগো ছাপায় যায় না (মালিক, fe-র মাধ্যমে, ২১ অক্টোবর ২০২৬; [[Company::logoData()]])।
 *
 * ⓘ কোম্পানির লোগো এখন SVG হতে পারে (বারের জন্য, [[SafeSvg]])। ছাপার পথ সেই একই `logo_path` পড়ে, আর mPDF-এর SVG-পড়া
 * আংশিক — বিলের মতো আইনি কাগজে ঝুঁকি নেওয়া হয় না।
 *
 * দাবি, আসল ছাপার দরজা দিয়ে (`sales.print.invoice`):
 *  - কোম্পানির লোগো SVG হলে ছাপার লোগো নেই (`logoData()` null), আর বিলটা তবু ঠিকঠাক PDF — ২০০, `%PDF`, ভেতরে SVG নেই,
 *    কোনো ছবিই বসেনি;
 *  - শাখার নিজের বিলের লোগো (PNG) থাকলে বিলে সেটাই বসে — PDF-এ একটা ছবি।
 */
final class AnSvgLogoStaysOffThePrintedBillTest extends TestCase
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

        Storage::fake('public');
        Storage::disk('public')->put('logos/TDEPOT-mark.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="40"><rect width="40" height="40" fill="teal"/></svg>');
        $this->company->forceFill(['logo_path' => 'logos/TDEPOT-mark.svg'])->save();
    }

    public function test_a_company_with_an_svg_logo_still_prints_a_clean_bill_without_it(): void
    {
        $this->assertNotNull($this->company->fresh()->logoUrl(), 'প্রস্তুতিটাই ভুল — SVG লোগো বারের জন্যও নেই।');
        $this->assertNull($this->company->fresh()->logoData(), '⛔ SVG লোগো ছাপার পথে গেল।');

        $pdf = $this->bill();

        $this->assertStringNotContainsStringIgnoringCase('<svg', $pdf, '⛔ বিলের PDF-এ SVG বসেছে।');
        $this->assertStringNotContainsString('svg+xml', $pdf, '⛔ বিলের PDF-এ SVG বসেছে।');
        $this->assertSame(0, $this->images($pdf), '⛔ SVG লোগোর কোম্পানির বিলে তবু একটা ছবি — লোগো ছাড়া, কেবল নাম হওয়ার কথা।');
    }

    public function test_the_branchs_own_invoice_logo_prints_instead(): void
    {
        $png = imagecreatetruecolor(240, 80);
        imagefilledrectangle($png, 0, 0, 239, 79, imagecolorallocate($png, 20, 120, 110));
        ob_start();
        imagepng($png);
        Storage::disk('public')->put('invoice-logos/branch.png', (string) ob_get_clean());

        $branch = (int) $this->company->defaultBranch()?->id;
        app(BranchSettings::class)->set(BranchSettings::INVOICE_LOGO, $branch, 'invoice-logos/branch.png');

        $pdf = $this->bill();

        $this->assertStringNotContainsStringIgnoringCase('<svg', $pdf);
        $this->assertGreaterThanOrEqual(1, $this->images($pdf), '⛔ শাখার নিজের বিলের লোগো ছাপায় বসেনি।');
    }

    /** বিক্রি করে তার বিল ছাপা — আসল দরজা দিয়ে; PDF-এর বাইট */
    private function bill(): string
    {
        $invoice = $this->aSale();
        $response = $this->get(route('sales.print.invoice', $invoice).'?paper=a4')->assertOk();
        $pdf = (string) $response->getContent();

        $this->assertStringStartsWith('%PDF', $pdf, '⛔ বিলের উত্তর PDF নয়।');

        return $pdf;
    }

    /** PDF-এ বসা ছবির সংখ্যা — mPDF প্রতিটা ছবিকে `/Subtype /Image` বস্তু হিসেবে লেখে */
    private function images(string $pdf): int
    {
        return preg_match_all('#/Subtype\s*/Image#', $pdf);
    }

    private function aSale(): SalesInvoice
    {
        return app(DirectSaleService::class)->complete(
            ['customer_id' => Customer::query()->where('name_en', 'Rahim Traders')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'), 'own_transport' => '1'],
            [['product_id' => Product::query()->where('name_en', 'Cosmos Biscuit 40gm')->value('id'), 'qty' => '2', 'rate' => '10', 'free_qty' => '0']],
        )['invoice']->fresh();
    }
}
