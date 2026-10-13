<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Customer;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ "সীমা নেই" তালিকায় চলতি অস্থায়ী সীমার গ্রাহকও উঠত (Customer নিরীক্ষা, ১৩ অক্টোবর ২০২৬; [[PartyReports::noCreditLimit()]])।
 *
 * ⚠️ তালিকা দেখত কেবল মূল সীমা ≤ ০; অস্থায়ী ৫০ হাজার চলা গ্রাহক "সীমা নেই" হয়ে বসত, অথচ দেয়াল তাঁকে ৫০ হাজার পর্যন্ত বাকি
 * দেয়। ⭐ এখন চলতি অস্থায়ী সীমা থাকলে বাদ; মেয়াদ পেরোলে আবার তালিকায়, আর সীমাহীন সাধারণ গ্রাহক আগের মতোই।
 */
final class ATemporaryLimitReadAsNoLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_live_temporary_limit_is_a_limit(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        $make = fn (string $code) => Customer::query()->create([
            'company_id' => $company->id, 'branch_id' => $company->defaultBranch()?->id, 'code' => $code,
            'name_en' => $code, 'credit_limit' => '0', 'status' => DocumentStatus::CONFIRMED, 'is_active' => true,
        ]);
        $live = $make('NOLIM-LIVE');
        $live->forceFill(['temp_credit_limit' => '50000', 'temp_credit_limit_until' => now()->addDays(5)->toDateString()])->save();
        $over = $make('NOLIM-OVER');
        $over->forceFill(['temp_credit_limit' => '50000', 'temp_credit_limit_until' => now()->subDay()->toDateString()])->save();
        $plain = $make('NOLIM-PLAIN');

        $result = app(ReportEngine::class)->run('customer.no_limit', ['to' => now()->toDateString()], 1, 1000);
        $ids = collect($result->rows)->map(fn ($r) => (int) ((array) $r)['id'])->all();

        $this->assertNotContains($live->id, $ids, '⛔ চলতি অস্থায়ী সীমার গ্রাহক "সীমা নেই" তালিকায়');
        $this->assertContains($over->id, $ids, 'মেয়াদ পেরোনো অস্থায়ী সীমার গ্রাহক তালিকায় নেই');
        $this->assertContains($plain->id, $ids, 'সাধারণ সীমাহীন গ্রাহক তালিকা থেকে পড়ে গেলেন');
    }
}
