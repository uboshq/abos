<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Engines\Report\ReportEngine;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\User;
use App\Modules\Accounts\Services\StandardChart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ⭐ মেনু যে চাবিতে সারি দেখায়, দরজাও সেই চাবিতে খোলে — রেওয়ামিল, খাতওয়ারি আয় আর খরচ, শাখা পাশাপাশি (fe hotfix, ১৯ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে মেনু কেবল `accounts.report.final` দেখে এই চার সারি দেখাত, অথচ ওয়েবের দরজা আর রিপোর্টের সংজ্ঞা `accounts.report`-ও
 * চাইত — কেবল চূড়ান্ত চাবির মানুষ সারি দেখতেন, চাপলে ৪০৩ (ওয়েবে আর ফোনে)।
 *
 * দাবি: কেবল চূড়ান্ত চাবিতে চারটাই ওয়েবে আর ফোনে খোলে; কেবল `accounts.report`-এ চারটাই বন্ধ থাকে (মুনাফার অঙ্ক চূড়ান্ত চাবির পেছনেই)।
 */
final class TheFinalAccountsRowOpenedToA403Test extends TestCase
{
    use RefreshDatabase;

    private const ROWS = [
        'trial-balance' => 'accounts.trial_balance',
        'expense-by-head' => 'accounts.expense_by_head',
        'income-by-head' => 'accounts.income_by_head',
        'branches' => 'accounts.branches_side_by_side',
    ];

    public function test_the_final_accounts_key_alone_opens_what_the_menu_shows_and_the_plain_report_key_does_not(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(StandardChart::class)->install();

        $finalOnly = $this->staff($company, ['accounts.view', 'accounts.report.final']);
        $plainOnly = $this->staff($company, ['accounts.view', 'accounts.report']);

        foreach (self::ROWS as $slug => $key) {
            $this->assertSame(['accounts.report.final'], app(ReportEngine::class)->get($key)->permissions(),
                "⛔ {$key}-এর সংজ্ঞা মেনুর চাবির বাইরে কিছু চায়।");

            $this->actingAs($finalOnly);
            $this->get(route('accounts.report.show', ['slug' => $slug]))->assertOk();
            $this->phone($finalOnly, '/api/v1/reports/'.$key)->assertOk();

            $this->app['auth']->forgetGuards();
            $this->actingAs($plainOnly);
            $this->get(route('accounts.report.show', ['slug' => $slug]))->assertForbidden();
            $this->phone($plainOnly, '/api/v1/reports/'.$key)->assertForbidden();
            $this->app['auth']->forgetGuards();
        }
    }

    /** @param list<string> $keys */
    private function staff(Company $company, array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $user->companies()->attach($company->id, ['is_active' => true]);
        $user->givePermissionTo($keys);

        return $user->fresh();
    }

    private function phone(User $user, string $url): \Illuminate\Testing\TestResponse
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson($url);
    }
}
