<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Services\CustomerService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ হাতে লেখা একটা কোড সিরিজের পরের নম্বরে পড়লে আর কোনো গ্রাহক বানানো যেত না (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬;
 * [[CustomerService::create()]])।
 *
 * ⚠️ সিরিজ যে নম্বর দিল সেটা দখলে → "কোড দখলে" → পুরো লেনদেন ফিরল, সিরিজের গুনতিও। পরের বার আবার সেই নম্বর, আবার ব্যর্থ —
 * চিরকাল। ⭐ এখন দখলে থাকলে পরের নম্বর।
 */
final class ATypedCodeBlockedEveryNewCustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_typed_code_on_the_next_series_number_does_not_block_new_customers(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $next = app(NumberSeriesEngine::class)->upcoming('CUS');
        $this->assertNotNull($next, 'দৃশ্যটাই বানানো যায়নি — গ্রাহকের সিরিজ নেই');

        $service = app(CustomerService::class);
        $service->create(['name_en' => 'Typed Code Shop', 'code' => $next]);

        $auto = $service->create(['name_en' => 'Auto Code Shop']);

        $this->assertNotSame($next, $auto->code, '⛔ দখলে থাকা কোডই আবার দেওয়া হল');
        $this->assertSame('Auto Code Shop', $auto->fresh()->name_en);
    }
}
