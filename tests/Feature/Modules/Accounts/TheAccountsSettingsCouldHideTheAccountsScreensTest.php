<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * হিসাবের সেটিংস থেকে `accounts.manage` চাবিতেই হিসাবের পর্দা বন্ধ করা যেত, আর প্রতিটা সংরক্ষণে সব সেটিং লেখা হত —
 * ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬।
 *
 * ⛔ পাতাটা মেনুর সুইচগুলোও আঁকত (মডিউল, দল, প্রতিটা পর্দা), যা কন্ট্রোল প্যানেলে `system_admin.settings.manage` চায়। আর
 * চেকবক্স না পাঠালে "বন্ধ" — তাই ঐ সুইচ না দেখানো একজন হিসাবরক্ষক পুরো ফর্ম সংরক্ষণ করলেও পর্দা বন্ধ হত। ⓘ আর না-বদলানো
 * প্রতিটা মানেও একটা সারি বসত, তাই কোম্পানিটা পণ্যের ডিফল্ট অনুসরণ করা থামাত।
 */
final class TheAccountsSettingsCouldHideTheAccountsScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_accountant_cannot_switch_screens_off_and_an_unchanged_save_writes_nothing(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $clerk = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        CompanyContext::forCompany((int) $company->id, fn () => $clerk->givePermissionTo('accounts.manage'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $clerk = $clerk->fresh();
        $this->assertFalse($clerk->can('system_admin.settings.manage'), 'হিসাবরক্ষকের কন্ট্রোল প্যানেলের চাবি আছে — দাবিটা কিছু মাপছে না।');

        $settings = app(SettingsService::class);
        $definitions = collect($settings->definitions())->filter(fn (array $d) => ($d['module'] ?? null) === 'accounts');
        $menuKey = (string) $definitions->filter(fn (array $d) => $d['menu'] ?? false)->keys()->first();
        $this->assertNotSame('', $menuKey, 'হিসাবের কোনো মেনু সুইচই নেই — দাবিটা কিছু মাপছে না।');
        $this->assertTrue((bool) $settings->get($menuKey, true));

        $this->actingAs($clerk)->get(route('accounts.settings'))->assertOk()
            ->assertDontSee("settings[{$menuKey}]", false)
            ->assertSee('settings[accounts.backdate_days]', false);

        // ⓘ ব্রাউজার যা পাঠাত: প্রতিটা দেখানো ঘর তার এখনকার মান নিয়ে, বন্ধ চেকবক্স কিছুই না
        $form = [];

        foreach ($definitions->reject(fn (array $d) => $d['menu'] ?? false) as $key => $definition) {
            $value = $settings->get($key);

            if ($definition['type'] === 'boolean') {
                if ($value) {
                    $form[$key] = '1';
                }
            } elseif ($value !== null && is_scalar($value)) {
                $form[$key] = (string) $value;
            }
        }

        $rows = DB::table('settings')->count();

        $this->actingAs($clerk)->put(route('accounts.settings.update'), ['settings' => $form])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue((bool) tap($settings)->flush()->get($menuKey, true), 'হিসাবরক্ষকের সংরক্ষণে হিসাবের পর্দা বন্ধ হয়ে গেল।');
        $this->assertSame($rows, DB::table('settings')->count(), 'কিছু না বদলে সংরক্ষণ করতেই সেটিংসের সারি বসল।');
    }
}
