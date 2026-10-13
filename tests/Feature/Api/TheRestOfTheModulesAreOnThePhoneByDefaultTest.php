<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Core\Services\PhoneModules;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Api\AuthController;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ অর্থ, ডকুমেন্টস, প্রমোশন আর HR ফোনে নিজে থেকেই চালু — মালিক, ১৩ অক্টোবর ২০২৬: *"ok tai koro"*।
 *
 * দাবি:
 *  - যে কোম্পানিতে কেউ সুইচ ছোঁয়নি, সেখানে চাবিওয়ালা চার মডিউলের মেনু আর দরজা পান; চাবি ছাড়া মানুষ মেনুতে কিছুই পান না আর
 *    দরজায় ৪০৩ — সুইচ চালু মানে কেবল "দেখানো যায়", দরজা চাবিতেই।
 *  - কোম্পানি সুইচ বন্ধ করলে চাবিওয়ালাও পান না (৪০৩ `module_off`)।
 *  - ⛔ এই চারটা চালু থাকায় কোম্পানির বন্ধ রাখা অন্য মডিউলের দরজা খোলে না ([[PhoneModules::OWN_DOORS_ONLY]]) — নির্ভরতা (হিসাব,
 *    মজুদ, গ্রাহক) ধরে পৌঁছানো যায় না।
 */
final class TheRestOfTheModulesAreOnThePhoneByDefaultTest extends TestCase
{
    use RefreshDatabase;

    private const FOUR = ['finance', 'documents', 'promotion', 'hr'];

    /** মডিউল → [চাবি, ফোনের দরজা] */
    private const DOORS = [
        'finance' => ['finance.deposit.view', '/api/v1/finance/deposits'],
        'documents' => ['documents.view', '/api/v1/documents/library'],
        'promotion' => ['promotion.view', '/api/v1/promotion/offers'],
        'hr' => ['hr.leave.self', '/api/v1/hr/leave'],
    ];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set((int) $this->company->id, $this->company->defaultBranch()?->id);
    }

    public function test_a_fresh_company_shows_the_four_to_the_keyholder_and_hides_them_from_anyone_else(): void
    {
        $this->assertSame(0, Setting::query()->withoutGlobalScopes()->where('company_id', $this->company->id)
            ->whereIn('key', array_map(PhoneModules::key(...), self::FOUR))->count(),
            'দাবির ভিত্তি নেই — কোম্পানিতে আগেই সুইচের সারি আছে, ডিফল্ট যাচাই হয় না।');

        // ⓘ HR-এর মেনু-সারি নিজের দাবির চাবিতে (ছুটির নিজের সারি মেনুতে নেই — ফোনের টাইল চাবি দেখে)
        $keyholder = $this->staff([...array_column(self::DOORS, 0), 'hr.claim.self']);
        $me = $this->phone($keyholder, '/api/v1/me')->assertOk()->json();

        foreach (self::FOUR as $code) {
            $this->assertContains($code, $me['phoneModules'], "⛔ নতুন কোম্পানিতে {$code} ফোনে বন্ধ।");
            $this->assertContains($code, array_column($me['menu'], 'code'), "⛔ চাবিওয়ালার মেনুতে {$code} নেই।");
            $this->assertContains(self::DOORS[$code][0], $me['user']['permissions'] ?? $me['permissions'] ?? [],
                'ফোনের টাইল এই চাবি দেখে — /me-তে চাবিটা আসতে হবে।');
        }
        foreach (self::DOORS as $code => [$key, $door]) {
            $this->phone($keyholder, $door)->assertOk();
        }

        $plain = $this->staff(['customer.view']);
        $menu = array_column($this->phone($plain, '/api/v1/me')->assertOk()->json('menu'), 'code');

        foreach (self::DOORS as $code => [$key, $door]) {
            $this->assertNotContains($code, $menu, "⛔ চাবি ছাড়াই {$code} মেনুতে।");
            $this->phone($plain, $door)->assertForbidden();
        }
    }

    public function test_the_company_can_still_switch_them_off(): void
    {
        foreach (self::FOUR as $code) {
            app(SettingsService::class)->set(PhoneModules::key($code), false);
        }

        $keyholder = $this->staff([...array_column(self::DOORS, 0), 'hr.claim.self']);
        $me = $this->phone($keyholder, '/api/v1/me')->assertOk()->json();

        foreach (self::DOORS as $code => [$key, $door]) {
            $this->assertNotContains($code, $me['phoneModules']);
            $this->assertNotContains($code, array_column($me['menu'], 'code'));
            $this->phone($keyholder, $door)->assertForbidden()->assertJsonPath('reason', PhoneModules::REASON);
        }
    }

    public function test_the_four_never_reopen_a_module_the_company_switched_off(): void
    {
        // ⓘ চারটা ছাড়া সব বন্ধ — চারটার নির্ভরতা (হিসাব, মজুদ, গ্রাহক, মাস্টার ডেটা) চালু কেউ নেই যে ধরে রাখবে
        foreach (PhoneModules::ON_BY_DEFAULT as $code) {
            if (! in_array($code, self::FOUR, true)) {
                app(SettingsService::class)->set(PhoneModules::key($code), false);
            }
        }
        app(SettingsService::class)->flush();
        app()->forgetInstance(PhoneModules::class);

        $reach = app(PhoneModules::class)->reachableCodes();
        foreach (['accounts', 'inventory', 'customer', 'master_data'] as $closed) {
            $this->assertNotContains($closed, $reach, "⛔ চার মডিউলের নির্ভরতা ধরে বন্ধ {$closed} আবার খুলল।");
        }
        foreach (self::FOUR as $code) {
            $this->assertContains($code, $reach);
        }

        // ⓘ অনুমোদন বন্ধ — ডকুমেন্টের সই ঐ দরজায় যায়, তবু ডকুমেন্টস চালু থাকায় সেটা খোলে না
        $keyholder = $this->staff(['approval.decide', 'finance.deposit.view']);
        $this->phone($keyholder, '/api/v1/finance/deposits')->assertOk();
        $this->phone($keyholder, '/api/v1/approvals/pending')->assertForbidden()->assertJsonPath('reason', PhoneModules::REASON);
    }

    /** @param list<string> $keys */
    private function staff(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company, ['is_active' => true]);
        CompanyContext::forCompany((int) $this->company->id, fn () => $user->givePermissionTo(
            array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), array_values(array_unique($keys)))));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function phone(User $user, string $url): TestResponse
    {
        app(SettingsService::class)->flush();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->fresh(), [AuthController::APP]);

        return $this->getJson($url);
    }
}
