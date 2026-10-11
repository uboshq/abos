<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Pos;

use App\Core\Services\MenuBuilder;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * কাউন্টার আর শিফট বিক্রয়ের ভাঁজ থেকে নিজের ভাঁজে — পিওএস ও খুচরা বিক্রি, ধাপ ১ (মালিক, ১১ অক্টোবর ২০২৬;
 * docs/cloud-tasks/pos-module.md)।
 *
 * ── ⭐ কী বদলেছে ─────────────────────────────────────────────────────
 * কেবল মেনুর **দরজা**: সারি দুইটা এখন "পিওএস ও খুচরা বিক্রি" ভাঁজে, বিক্রয়ে নয়।
 *
 * ── ⛔ কী বদলায়নি, আর এই ফাইলটা সেটাই পাহারা দেয় ──────────────────
 * রুটের নাম ও ঠিকানা (`sales.pos.*` → `/sales/pos`, `sales.shift.*` → `/sales/shifts`), চাবি
 * `sales.pos`, সুইচ `sales.screen_pos`। ⓘ ফোনের অ্যাপ আর মানুষের বুকমার্ক এগুলোর উপর দাঁড়িয়ে —
 * একটা নাম বদলালে কাউন্টার সকালে খুলত না, আর ভুলটা ধরা পড়ত ক্রেতার সামনে।
 */
class TheCounterMovedIntoItsOwnFoldTest extends TestCase
{
    use RefreshDatabase;

    private const ROUTES = ['sales.pos.index', 'sales.shift.index'];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /** @return array<int, array<string, mixed>> */
    private function menuOf(User $user): array
    {
        return app(MenuBuilder::class)->forUser($user);
    }

    /**
     * একটা টাইলের সব সারির রুট।
     *
     * @param  array<string, mixed>|null  $tile
     * @return list<string>
     */
    private function routesIn(?array $tile): array
    {
        if ($tile === null) {
            return [];
        }

        return collect($tile['groups'])->flatten(1)->pluck('route')->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function tile(string $code, User $user): ?array
    {
        return collect($this->menuOf($user))->firstWhere('code', $code);
    }

    public function test_with_the_switch_on_the_counter_and_shift_sit_in_the_pos_fold_and_not_in_sales(): void
    {
        app(SettingsService::class)->set('sales.screen_pos', true);

        $pos = $this->tile('pos', $this->owner());

        $this->assertNotNull($pos, 'সুইচ খোলা, অথচ পিওএস-এর ভাঁজটাই মেনুতে নেই।');
        $this->assertSame(self::ROUTES, $this->routesIn($pos),
            'পিওএস-এর ভাঁজে ঠিক কাউন্টার আর শিফট থাকার কথা, এই ক্রমে।');

        // ⛔ দুই জায়গায় একই সারি থাকলে মানুষ ভাবতেন দুইটা আলাদা পর্দা
        $this->assertSame([], array_values(array_intersect(self::ROUTES, $this->routesIn($this->tile('sales', $this->owner())))),
            'কাউন্টার বা শিফট এখনো বিক্রয়ের ভাঁজেও বসে আছে।');
    }

    public function test_with_the_switch_off_they_sit_nowhere(): void
    {
        app(SettingsService::class)->set('sales.screen_pos', false);

        $menu = $this->menuOf($this->owner());

        // ⓘ ভাঁজে আর কোনো সারি নেই, তাই ফাঁকা টাইলটাও বসে না — ডিপোর রেলে POS নেই
        $this->assertNotContains('pos', array_column($menu, 'code'), 'সুইচ বন্ধ, অথচ পিওএস-এর ফাঁকা ভাঁজটা রেলে বসেছে।');

        foreach ($menu as $tile) {
            $this->assertSame([], array_values(array_intersect(self::ROUTES, $this->routesIn($tile))),
                "সুইচ বন্ধ, অথচ কাউন্টার বা শিফট {$tile['code']}-এর ভাঁজে দেখা যাচ্ছে।");
        }
    }

    public function test_switching_sales_off_takes_the_borrowed_rows_with_it(): void
    {
        app(SettingsService::class)->set('sales.screen_pos', true);
        app(SettingsService::class)->set('sales.enabled', false);

        // ⓘ সারিগুলো বিক্রয়ের ঘর ধার করে; বিক্রয় বন্ধ থাকলে ক্লিকে ৪০৪ হত
        $this->assertNull($this->tile('pos', $this->owner()), 'বিক্রয় বন্ধ, অথচ তার ধার করা সারি পিওএস-এর ভাঁজে রয়ে গেছে।');
    }

    public function test_the_old_addresses_names_and_switch_still_work(): void
    {
        // ⛔ ঠিকানা বদলালে ফোনের অ্যাপ আর বুকমার্ক ভাঙত
        $this->assertSame(url('/sales/pos'), route('sales.pos.index'));
        $this->assertSame(url('/sales/shifts'), route('sales.shift.index'));

        $this->actingAs($this->owner());

        // সুইচটা এখনো বিক্রয়েরটাই — বন্ধ মানে দরজাও বন্ধ
        app(SettingsService::class)->set('sales.screen_pos', false);
        $this->get('/sales/pos')->assertNotFound();
        $this->get('/sales/shifts')->assertNotFound();

        app(SettingsService::class)->set('sales.screen_pos', true);
        $this->get('/sales/pos')->assertOk();
        $this->get('/sales/shifts')->assertOk();
    }

    public function test_the_key_is_still_sales_pos(): void
    {
        app(SettingsService::class)->set('sales.screen_pos', true);

        // ⓘ কোম্পানিতে আছেন, কিন্তু কোনো চাবি নেই
        $stranger = User::factory()->create();
        $stranger->companies()->attach($this->company, ['is_active' => true]);
        $stranger->forceFill(['current_company_id' => $this->company->id])->save();
        $stranger = $stranger->fresh();

        $this->assertNull($this->tile('pos', $stranger), 'চাবি নেই, অথচ পিওএস-এর ভাঁজ দেখা যাচ্ছে।');
        $this->actingAs($stranger)->get('/sales/pos')->assertForbidden();

        // ⭐ ঠিক `sales.pos` চাবিটা দিলেই দুইটা সারি আর দরজা খোলে — নতুন কোনো চাবি লাগে না
        CompanyContext::forCompany($this->company->id, fn () => $stranger->givePermissionTo(Permission::findOrCreate('sales.pos', 'web')));
        $stranger = $stranger->fresh();

        $this->assertSame(self::ROUTES, $this->routesIn($this->tile('pos', $stranger)));
        $this->actingAs($stranger)->get('/sales/pos')->assertOk();
    }
}
