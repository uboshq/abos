<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\MenuSwitches;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * এক গ্রুপ বন্ধ করলে গোটা মডিউল বন্ধ হয় না, আর নিজের পর্দাগুলো খোলাও থাকে না।
 *
 * ── ⛔ কী ভেঙেছিল (ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬) ──────────
 * দরজা ([[RefuseSwitchedOffScreens]]) গ্রুপ আর সারি চিনত কেবল রুটের
 * **উপসর্গ** দিয়ে, আর একই উপসর্গ দুইজন দাবি করলে শেষজন জিতত:
 *
 * ১. `accounts.settings`-এর উপসর্গ `accounts.` — হিসাবের "সেটিংস" গ্রুপ
 *    বন্ধ করলে ভাউচার, লেজার, সব রিপোর্ট ৪০৪, অথচ মেনু সেগুলো দেখাত।
 * ২. `inventory.stock.` বসত "রিপোর্ট" গ্রুপের নামে — "লেনদেন" বন্ধ করলে
 *    মেনু থেকে সমন্বয় সরত, কিন্তু ঠিকানা দিলে খুলত।
 * ৩. "টাকার জিম্মা" সারি বন্ধ করলে দরজা দেখত `accounts.settings`-এর সুইচ,
 *    তাই পর্দাটা খোলাই থাকত।
 */
final class OneMenuGroupClosedTheWholeModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
    }

    private function switchOff(string $key): void
    {
        app(SettingsService::class)->set($key, false);
    }

    public function test_switching_off_the_settings_group_keeps_the_vouchers_open(): void
    {
        $this->get(route('accounts.voucher.index', ['type' => 'receipt']))->assertOk();

        $this->switchOff(app(MenuSwitches::class)->forGroup('accounts', 'settings'));

        $this->get(route('accounts.voucher.index', ['type' => 'receipt']))->assertOk();

        /* ⓘ আর গ্রুপের নিজের পর্দাটা সত্যিই বন্ধ */
        $this->get(route('accounts.settings'))->assertNotFound();
    }

    public function test_switching_off_inventory_transactions_closes_stock_adjust(): void
    {
        $this->switchOff(app(MenuSwitches::class)->forGroup('inventory', 'transactions'));

        $this->get(route('inventory.stock.adjust'))->assertNotFound();
        $this->post(route('inventory.stock.adjust.store'), [])->assertNotFound();

        /* ⓘ রিপোর্ট গ্রুপের পর্দা একই উপসর্গে, তবু খোলা */
        $this->get(route('inventory.stock.movement'))->assertOk();
    }

    public function test_switching_off_the_custody_row_closes_its_door(): void
    {
        $this->switchOff('menu.accounts.custody');

        $this->get(route('accounts.custody'))->assertNotFound();
    }
}
