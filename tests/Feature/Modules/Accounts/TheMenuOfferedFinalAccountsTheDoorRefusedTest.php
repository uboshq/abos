<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\MenuBuilder;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * মেনু লাভ-ক্ষতি আর নগদ প্রবাহ দেখাত, দরজা ৪০৩ দিত — ১ অক্টোবর ২০২৬, নিরীক্ষা §৮।
 *
 * ⓘ মেনুর সারি চাইত `accounts.report.final`, পাতা চাইত `accounts.report` + `final`।
 * ⭐ একই মানুষ, কেবল চূড়ান্ত হিসাবের চাবি: আগে মেনুতে সারি, ক্লিকে ৪০৩; এখন সারি আর পাতা দুটোই।
 * চাবি তুলে নিলে দুটোই নেই — দেয়াল ঢিলে হয়নি।
 */
final class TheMenuOfferedFinalAccountsTheDoorRefusedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_final_accounts_key_alone_opens_what_the_menu_offers(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $reader = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey($company->id))
            ->orderBy('id')->get()
            ->first(fn (User $u) => ! $u->can('accounts.report') && ! $u->can('accounts.report.final'));

        $this->assertNotNull($reader, 'প্রস্তুতিটাই ভুল — হিসাবের চাবি নেই এমন কেউ নেই।');

        $pages = ['লাভ-ক্ষতি' => 'profit-loss', 'নগদ প্রবাহ' => 'cash-flow'];

        foreach ($pages as $name => $slug) {
            $this->actingAs($reader)->get(route('accounts.report.show', ['slug' => $slug]))->assertForbidden();
        }

        $reader->givePermissionTo('accounts.report.final');
        $reader->forgetCachedPermissions();
        $reader = $reader->fresh();

        $routes = $this->menuRoutes($reader);

        foreach ($pages as $name => $slug) {
            $this->assertContains('accounts.report.final.'.str_replace('-', '_', $slug), $routes, "প্রস্তুতিটাই ভুল — মেনুতে {$name} নেই।");
            $this->actingAs($reader)->get(route('accounts.report.show', ['slug' => $slug]))
                ->assertOk();
        }

        // ⚠️ সাধারণ রিপোর্ট আগের মতোই সাধারণ চাবি চায়
        $this->actingAs($reader)->get(route('accounts.report.show', ['slug' => 'day-book']))->assertForbidden();
    }

    /**
     * ⭐ সংজ্ঞাও দরজার চাবিই চায় — সমন্বয়কের সিদ্ধান্ত, ৬ অক্টোবর ২০২৬। ⓘ আগে সংজ্ঞা `accounts.report`-ও চাইত, তাই কেবল
     * চূড়ান্ত হিসাবের চাবিওয়ালা পাতা খুলতেন কিন্তু সূচি, ফোন আর ডাউনলোড ([[ReportDefinition::allows()]] পড়ে) তাঁকে ফেরাত।
     *
     * ⛔ দুই দিকেই: কেবল চূড়ান্ত চাবি — পাতা আর সংজ্ঞা দুটোই খোলে; কেবল সাধারণ হিসাবের চাবি — দুটোই বন্ধ।
     */
    public function test_the_definitions_ask_exactly_the_key_the_door_asks(): void
    {
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $engine = app(\App\Core\Engines\Report\ReportEngine::class);
        $reports = ['profit-loss' => 'accounts.profit_loss', 'cash-flow' => 'accounts.cash_flow', 'balance-sheet' => 'accounts.balance_sheet'];

        $finalOnly = $this->member($company, ['accounts.report.final']);
        $booksOnly = $this->member($company, ['accounts.report']);

        foreach ($reports as $slug => $key) {
            $this->assertTrue($engine->get($key)->allows($finalOnly), "⛔ {$key}: কেবল চূড়ান্ত চাবিতে সূচি/ফোন/ডাউনলোড ফেরায়");
            $this->assertFalse($engine->get($key)->allows($booksOnly), "⛔ {$key}: চূড়ান্ত চাবি ছাড়াই সংজ্ঞা খোলে");
        }

        foreach (['profit-loss', 'cash-flow'] as $slug) {
            $this->actingAs($finalOnly)->get(route('accounts.report.show', ['slug' => $slug]))->assertOk();
            $this->actingAs($booksOnly)->get(route('accounts.report.show', ['slug' => $slug]))->assertForbidden();
        }
    }

    /** @param  list<string>  $keys */
    private function member(Company $company, array $keys): User
    {
        $user = User::factory()->create(['current_company_id' => $company->id, 'is_active' => true]);
        $user->companies()->attach($company->id, ['is_active' => true]);

        foreach ($keys as $key) {
            CompanyContext::forCompany($company->id, fn () => $user->givePermissionTo(\Spatie\Permission\Models\Permission::findOrCreate($key, 'web')));
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** @return list<string> */
    private function menuRoutes(User $user): array
    {
        $routes = [];

        foreach (app(MenuBuilder::class)->forUser($user) as $module) {
            foreach ($module['groups'] ?? [] as $rows) {
                foreach ($rows as $row) {
                    $routes[] = (string) ($row['route'] ?? '');
                }
            }
        }

        return $routes;
    }
}
