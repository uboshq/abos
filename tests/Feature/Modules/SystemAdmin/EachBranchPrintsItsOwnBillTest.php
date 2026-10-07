<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\NumberSeries;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Support\InvoicePrintLook;
use App\Modules\Sales\Support\PaperDesigns;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * ⭐ প্রতিটা শাখার বিল নিজের — মালিক, ৬ অক্টোবর ২০২৬ (ছবিসহ, সমন্বয়কের মারফত): বিলের তথ্যের পাতায় "কার জন্য: কোম্পানি /
 * গোল্ড / জাবেদ / লায়ন / সুপার / হোলসেল" ট্যাব, অথচ এক শাখায় মাথা, লোগো বা পরের নম্বর বদলালে সব শাখায় বদলায়।
 *
 * ⓘ যা ভাঙা ছিল: পাতার "নমুনা দেখুন" শাখা জানত না (সুপারের ট্যাব থেকেও কোম্পানির নমুনা); বাংলা ঐতিহ্যের দুই নকশা নাম
 * কোম্পানির প্রোফাইল থেকে নিত; পরের বিক্রি নম্বর যেকোনো ট্যাবে প্রথম শাখার সিরিজ দেখাত।
 *
 * ⭐ দাবি — একই মালিক, একই পাতা, ট্যাব বদলে:
 *   · সুপারে বদলালে লায়নের বিল আগের মতো; সুপারের বিলে সুপারের মাথা; খালি শাখায় কোম্পানির মাথা — আসল ছাপায় আর নমুনায়
 *   · ছাপার প্রতিটা নকশায় (A4, A5, থার্মাল — ক্লাসিক, mono, বাংলা ঐতিহ্য …) শাখার নাম
 *   · লোগো একই নিয়মে
 *   · পরের বিক্রি নম্বর — শাখার নিজের সিরিজ থাকলে সেটা, না থাকলে সবারটা আর "এই নম্বর সব শাখার"
 */
final class EachBranchPrintsItsOwnBillTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $super;

    private Branch $lion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $this->super = $this->branch('SUPER', 'Super');
        $this->lion = $this->branch('LION', 'Lion');

        $this->save(null, ['sales.print.header.name' => 'Company Head']);
        $this->save($this->super->id, ['sales.print.header.name' => 'Super Store', 'sales.print.header.phone' => '01700-000001']);
    }

    public function test_a_real_bill_of_each_branch_carries_its_own_head(): void
    {
        $super = $this->bill($this->super);
        $lion = $this->bill($this->lion);

        $page = $this->printed($super);
        $this->assertStringContainsString('Super Store', $page, '⛔ সুপারের বিলে সুপারের নাম নেই।');
        $this->assertStringContainsString('01700-000001', $page);

        $page = $this->printed($lion);
        $this->assertStringContainsString('Company Head', $page, '⛔ খালি শাখার বিলে কোম্পানির মাথা নেই।');
        $this->assertStringNotContainsString('Super Store', $page, '⛔ সুপারে বদলাতেই লায়নের বিল বদলে গেল।');
        $this->assertStringNotContainsString('01700-000001', $page);
    }

    public function test_the_sample_from_a_branch_tab_is_that_branchs_bill(): void
    {
        $info = (string) $this->get(route('system_admin.print_control.invoice_info', ['branch' => $this->super->id]))->assertOk()->getContent();
        $this->assertStringContainsString(e(route('sales.invoice_sample', ['branch' => $this->super->id])), $info,
            '⛔ সুপারের ট্যাবের "নমুনা" শাখা ছাড়া খোলে।');

        $this->assertStringContainsString('Super Store', $this->sample($this->super), '⛔ সুপারের নমুনায় সুপারের নাম নেই।');
        $this->assertStringNotContainsString('Super Store', $this->sample($this->lion), '⛔ লায়নের নমুনায় সুপারের নাম।');
        $this->assertStringContainsString('Company Head', $this->sample($this->lion));
        $this->assertStringContainsString('Company Head', $this->sample(null));

        // ⓘ চালানের নমুনাও — ছাপার নিয়ন্ত্রণের পাতার শাখার ট্যাব থেকে, সেই শাখার মাথায়
        $challan = fn (?Branch $b) => (string) $this->get(route('sales.challan_sample', array_filter(['branch' => $b?->id])))->assertOk()->getContent();
        $this->assertStringContainsString('Super Store', $challan($this->super), '⛔ সুপারের চালানের নমুনায় সুপারের নাম নেই।');
        $this->assertStringNotContainsString('Super Store', $challan($this->lion));

        $cards = (string) $this->get(route('system_admin.print_control', ['paper' => 'challan', 'size' => 'a4', 'branch' => $this->super->id]))
            ->assertOk()->getContent();
        $design = PaperDesigns::codes('challan', 'a4')[0];
        $this->assertStringContainsString(e(route('sales.challan_sample', ['design' => $design, 'size' => 'a4', 'branch' => $this->super->id])), $cards,
            '⛔ ছাপার পাতার শাখার ট্যাবে নকশার নমুনা শাখা ছাড়া খোলে।');
    }

    public function test_every_design_prints_the_branchs_own_name(): void
    {
        $missing = [];

        foreach (PaperDesigns::SIZES as $size) {
            foreach (PaperDesigns::codes('invoice', $size) as $design) {
                $page = $this->sample($this->super, ['size' => $size, 'design' => $design]);

                if (mb_stripos($page, 'Super Store') === false || mb_stripos($page, 'Company Head') !== false) {
                    $missing[] = "{$size}/{$design}";
                }
            }
        }

        $this->assertSame([], $missing, '⛔ এই নকশাগুলো শাখার নাম ছাপে না (কোম্পানিরটা ছাপে বা কিছুই না)।');
    }

    public function test_the_logo_follows_the_branch_the_same_way(): void
    {
        Storage::fake('public');
        $logo = fn (string $colour) => UploadedFile::fake()->image("{$colour}.png", 60, 30);

        $this->put(route('system_admin.print_control.invoice_info.update'), ['branch' => $this->super->id, 'invoice_logo' => $logo('red')])
            ->assertSessionHasNoErrors();
        $this->put(route('system_admin.print_control.invoice_info.update'), ['invoice_logo' => $logo('blue')])
            ->assertSessionHasNoErrors();

        $superLogo = $this->logoOf($this->super->id);
        $companyLogo = $this->logoOf(null);
        $this->assertNotSame($superLogo, $companyLogo, 'দাবির ভিত্তি নেই — দুই লোগো একই।');

        $this->assertStringContainsString($superLogo, $this->printed($this->bill($this->super)), '⛔ সুপারের বিলে সুপারের লোগো নেই।');
        $lion = $this->printed($this->bill($this->lion));
        $this->assertStringContainsString($companyLogo, $lion, '⛔ খালি শাখার বিলে কোম্পানির বিলের লোগো নেই।');
        $this->assertStringNotContainsString($superLogo, $lion, '⛔ সুপারের লোগো লায়নের বিলে।');
    }

    public function test_the_next_sale_number_is_the_branchs_own_or_says_it_is_shared(): void
    {
        $year = FinancialYear::query()->where('company_id', $this->company->id)->value('id');
        NumberSeries::query()->where('doc_type', 'S')->update(['is_active' => false]);
        NumberSeries::query()->updateOrCreate(
            ['company_id' => $this->company->id, 'branch_id' => null, 'financial_year_id' => $year, 'doc_type' => 'S'],
            ['module' => 'sales', 'prefix' => 'S', 'format' => '{PREFIX}-{SEQ}', 'padding' => 4, 'next_number' => 41, 'start_number' => 1, 'is_active' => true]);
        NumberSeries::query()->create(['company_id' => $this->company->id, 'branch_id' => $this->super->id, 'financial_year_id' => $year, 'module' => 'sales',
            'doc_type' => 'S', 'prefix' => 'SUP', 'format' => '{PREFIX}-{SEQ}', 'padding' => 4, 'next_number' => 7, 'start_number' => 1, 'is_active' => true]);

        $super = (string) $this->get(route('system_admin.print_control.invoice_info', ['branch' => $this->super->id]))->getContent();
        $this->assertStringContainsString('SUP-0007', $super, '⛔ সুপারের ট্যাবে সুপারের নিজের নম্বর নেই।');
        $this->assertStringNotContainsString('data-next-shared', $super);

        $lion = (string) $this->get(route('system_admin.print_control.invoice_info', ['branch' => $this->lion->id]))->getContent();
        $this->assertStringContainsString('S-0041', $lion, '⛔ লায়নের ট্যাবে সবার নম্বর নয়।');
        $this->assertStringNotContainsString('SUP-0007', $lion, '⛔ লায়নের ট্যাবে সুপারের নম্বর।');
        $this->assertStringContainsString('data-next-shared', $lion, '⛔ "এই নম্বর সব শাখার" বলা নেই।');

        $this->assertSame(7, (int) NumberSeries::query()->where('branch_id', $this->super->id)->value('next_number'), '⛔ পাতা খুলতেই সিরিজ বদলাল।');
    }

    // ── যন্ত্রপাতি ──────────────────────────────────────────────────────

    /** @param  array<string, string>  $settings */
    private function save(?int $branch, array $settings): void
    {
        $this->put(route('system_admin.print_control.invoice_info.update'), array_filter([
            'branch' => $branch,
            'settings' => $settings,
        ], fn ($v) => $v !== null))->assertSessionHasNoErrors();
    }

    /** @param  array<string, string>  $more */
    private function sample(?Branch $branch, array $more = []): string
    {
        return (string) $this->get(route('sales.invoice_sample', array_filter(['branch' => $branch?->id, ...$more])))
            ->assertOk()->getContent();
    }

    /**
     * আসল ছাপা (PDF) — ছাঁচ আঁকার মুহূর্তে কাগজে যা পৌঁছায়: মাথার নাম আর ফোন, আর লোগোর পথ। ⓘ ধরা হয় ছাঁচের ভেতরেই,
     * শাখার ঘেরার মধ্যে — ছাঁচ যা দেখে, ঠিক তাই ([[ABranchPrintsAsItsOwnBusinessTest::companyOnPaper()]]-এর কৌশল)।
     */
    private function printed(SalesInvoice $invoice): string
    {
        $seen = null;
        View::composer('sales::print.invoice-*', function ($view) use (&$seen) {
            $company = $view->getData()['company'] ?? null;
            if ($seen === null && $company instanceof Company) {
                $head = app(InvoicePrintLook::class)->header($company);
                $seen = implode(' | ', [$head['name'], $head['phone'], (string) $company->logo_path]);
            }
        });

        $this->get(route('sales.print.invoice', $invoice))->assertOk();
        View::getFacadeRoot()->getDispatcher()->forget('composing: sales::print.invoice-*');
        $this->assertNotNull($seen, 'বিলের ছাঁচ আঁকা হয়নি।');

        return $seen;
    }

    private function bill(Branch $branch): SalesInvoice
    {
        $service = app(SalesInvoiceService::class);
        $invoice = $service->confirm($service->create(
            [
                'customer_id' => Customer::query()->orderBy('id')->value('id'),
                'warehouse_id' => Warehouse::query()->where('is_default', true)->value('id'),
                'trx_date' => Carbon::today()->toDateString(),
            ],
            [['product_id' => Product::query()->orderBy('id')->value('id'), 'qty' => '1', 'rate' => '100']],
        ));
        DB::table('sal_invoices')->where('id', $invoice->id)->update(['branch_id' => $branch->id]);

        return $invoice->fresh();
    }

    /** শাখার (বা কোম্পানির) বিলের লোগোর পথ */
    private function logoOf(?int $branch): string
    {
        $path = (string) app(\App\Core\Services\BranchSettings::class)->invoiceLogoPath($branch);
        $this->assertNotSame('', $path, 'লোগো জমা হয়নি।');

        return $path;
    }

    private function branch(string $code, string $name): Branch
    {
        return Branch::query()->firstOrCreate(
            ['company_id' => $this->company->id, 'code' => $code],
            ['name_en' => $name, 'name_bn' => $name, 'is_active' => true],
        );
    }
}
