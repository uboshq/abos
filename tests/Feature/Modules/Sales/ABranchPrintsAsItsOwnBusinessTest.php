<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Sales;

use App\Core\Services\BranchSettings;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\DeliveryChallanLine;
use App\Modules\Sales\Support\PaperDesigns;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * প্রতিটা শাখা নিজের ব্যবসার মতো ছাপে — মালিক, ৩০ সেপ্টেম্বর ২০২৬।
 *
 * *"inv info, INVOICE LOGO protiti branch ER JONNO ALADA ALADA HOBE, PRINT TAMPLATE
 * ALADA HOBE … KARON ALADA ALADA BRANCH E ALADA TYPE BUSINESS HOTEPARE"* —
 * *"INVOICE LOGO ALADA UPLOAD MUST"*।
 *
 * ⓘ একই কোম্পানি, একই মালিক, দুইটা শাখা — কেবল কাগজটা কোন শাখার, সেটাই আলাদা।
 * শাখা কিছু না বসালে কোম্পানির সেটিং; আর অন্য কোম্পানির শাখায় কিছু বসানো যায় না।
 */
final class ABranchPrintsAsItsOwnBusinessTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Branch $home;

    private Branch $other;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->home = $this->company->defaultBranch();
        CompanyContext::set($this->company->id, $this->home->id);

        $this->other = Branch::create(['company_id' => $this->company->id, 'code' => 'RESTO', 'name_en' => 'Restaurant Branch']);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    public function test_each_branch_prints_its_own_design_and_falls_back_to_the_company(): void
    {
        $key = PaperDesigns::key('challan', 'a4');
        app(SettingsService::class)->set($key, 'aurora');
        app(BranchSettings::class)->set($key, $this->other->id, 'bento');

        $atHome = $this->challanIn($this->home, 'CHL-HOME-1');
        $atOther = $this->challanIn($this->other, 'CHL-RESTO-1');

        $this->assertDrawn('sales::print.challan-aurora', $atHome, 'প্রধান শাখা কোম্পানির নকশাই পাবে।');
        $this->assertDrawn('sales::print.challan-bento', $atOther, 'রেস্তোরাঁ শাখা নিজের বাছা নকশা পায়নি।');

        /* ⓘ শাখার বদল মুছলে আবার কোম্পানির নকশা */
        app(BranchSettings::class)->reset($key, $this->other->id);
        $this->assertDrawn('sales::print.challan-aurora', $atOther, 'বদল মোছার পরেও শাখা কোম্পানির নকশায় ফেরেনি।');
    }

    public function test_each_branch_prints_its_own_invoice_logo(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('invoice-logos/resto.png', 'png');
        app(BranchSettings::class)->set(BranchSettings::INVOICE_LOGO, $this->other->id, 'invoice-logos/resto.png');

        $this->assertSame('invoice-logos/resto.png', $this->companyOnPaper($this->challanIn($this->other, 'CHL-RESTO-2'))->logo_path,
            'রেস্তোরাঁ শাখার কাগজে তার নিজের লোগো ওঠেনি।');
        $this->assertNotSame('invoice-logos/resto.png', $this->companyOnPaper($this->challanIn($this->home, 'CHL-HOME-2'))->logo_path,
            'অন্য শাখার লোগো প্রধান শাখার কাগজে চলে গেছে।');
    }

    public function test_a_branch_of_another_company_cannot_be_given_a_setting(): void
    {
        $foreign = Company::query()->where('id', '!=', $this->company->id)->firstOrFail();
        $theirs = Branch::withoutGlobalScopes()->where('company_id', $foreign->id)->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        app(BranchSettings::class)->set(PaperDesigns::key('challan', 'a4'), $theirs->id, 'bento');
    }

    // ── হাতিয়ার ────────────────────────────────────────────────────────

    private function challanIn(Branch $branch, string $no): string
    {
        $challan = DeliveryChallan::query()->create([
            'branch_id' => $branch->id,
            'document_no' => $no,
            'customer_id' => Customer::query()->firstOrFail()->id,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
            'trx_date' => now()->toDateString(),
            'total' => '100.0000',
            'status' => DocumentStatus::CONFIRMED,
            // ⓘ মাল কীভাবে যাবে — ছাপার আগে লাগে ([[RequireTransportBeforePrint]])
            'own_transport' => true,
        ]);

        DeliveryChallanLine::query()->create([
            'delivery_challan_id' => $challan->id,
            'product_id' => Product::query()->firstOrFail()->id,
            'line_no' => 1,
            'delivered_qty' => '1.0000',
            'rate' => '100.0000',
            'amount' => '100.0000',
        ]);

        return route('sales.print.challan', $challan).'?paper=a4';
    }

    private function assertDrawn(string $view, string $url, string $why): void
    {
        $drawn = false;
        View::composer($view, function () use (&$drawn) {
            $drawn = true;
        });

        $this->actingAs($this->owner)->get($url)->assertOk();
        View::getFacadeRoot()->getDispatcher()->forget('composing: '.$view);

        $this->assertTrue($drawn, "{$why} ({$view} আঁকা হয়নি)");
    }

    private function companyOnPaper(string $url): Company
    {
        $company = null;
        View::composer('*', function ($view) use (&$company) {
            $company ??= $view->getData()['company'] ?? null;
        });

        $this->actingAs($this->owner)->get($url)->assertOk();
        View::getFacadeRoot()->getDispatcher()->forget('composing: *');

        $this->assertInstanceOf(Company::class, $company, 'কাগজে কোম্পানিই পৌঁছায়নি।');

        return $company;
    }
}
