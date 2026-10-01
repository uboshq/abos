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
