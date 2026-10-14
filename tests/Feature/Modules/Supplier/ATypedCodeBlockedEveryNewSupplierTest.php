<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Supplier;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Supplier\Services\SupplierService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ হাতে লেখা একটা কোড সিরিজের পরের নম্বরে পড়লে আর কোনো সরবরাহকারী বানানো যেত না (Supplier নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
 * [[SupplierService::create()]])।
 *
 * ⚠️ সিরিজ যে নম্বর দিল সেটা দখলে → "কোড দখলে" → পুরো লেনদেন ফিরল, সিরিজের গুনতিও। পরের বার আবার সেই নম্বর, আবার ব্যর্থ —
 * চিরকাল। ⭐ এখন দখলে থাকলে পরের নম্বর।
 */
final class ATypedCodeBlockedEveryNewSupplierTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_typed_code_on_the_next_series_number_does_not_block_new_suppliers(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $next = app(NumberSeriesEngine::class)->upcoming('SUP');
        $this->assertNotNull($next, 'দৃশ্যটাই বানানো যায়নি — সরবরাহকারীর সিরিজ নেই');

        $service = app(SupplierService::class);
        $service->create(['name_en' => 'Typed Code Mills', 'code' => $next]);

        $auto = $service->create(['name_en' => 'Auto Code Mills']);

        $this->assertNotSame($next, $auto->code, '⛔ দখলে থাকা কোডই আবার দেওয়া হল');
        $this->assertSame('Auto Code Mills', $auto->fresh()->name_en);
    }
}
