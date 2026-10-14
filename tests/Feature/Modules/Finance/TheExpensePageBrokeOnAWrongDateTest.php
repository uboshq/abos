<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Finance;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⛔ খরচ আর আয়ের পাতা ঠিকানার তারিখ যাচাই না করে খাতার প্রশ্নে পাঠাত — "from=abc" বা "2026-02-31" দিলে ৫০০ (লাইন-ধরে নিরীক্ষা,
 * ১৩ অক্টোবর ২০২৬)। ⭐ দাবি: ভুল তারিখে পাতা খোলে, নিজের ডিফল্ট মাসে।
 */
final class TheExpensePageBrokeOnAWrongDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_wrong_date_in_the_address_opens_the_page_on_its_default_month(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        app(StandardChart::class)->install();

        foreach (['finance.expense.index', 'finance.income.index'] as $route) {
            $this->get(route($route, ['from' => 'abc', 'to' => '2026-02-31']))->assertOk();
        }
    }
}
