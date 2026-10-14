<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * না-ছোঁয়া মালিক-কেবল সুইচ প্রতিবার "বদলানো হয়নি" বলত — সিস্টেম-অ্যাডমিন লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ কন্ট্রোল প্যানেল মালিক নন এমন প্রশাসকের কাছে ঐ চেকবক্স বন্ধ (disabled) দেখায়; বন্ধ ঘর ব্রাউজার পাঠায় না, অথচ
 * `scope[]`-এ চাবিটা যেত — সার্ভার ভাবত "বন্ধ করতে চাওয়া হয়েছে"। ⓘ দাবি পাতাটাই দেখে: বন্ধ ঘর `scope[]`-এ নেই, খোলা ঘর আছে,
 * আর ব্রাউজার যা পাঠাত সেটা পাঠালে কোনো ভুল আসে না।
 */
final class AnUntouchedOwnerSwitchSaidRefusedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_locked_switch_is_not_carried_by_the_form(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        app(SettingsService::class)->set('accounts.voucher_maker_checker', true);

        $admin = User::query()->where('email', 'accounts@abos.test')->firstOrFail();
        CompanyContext::forCompany($company->id,
            fn () => $admin->givePermissionTo(Permission::findOrCreate('system_admin.settings.manage', 'web')));
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(app(SettingsService::class)->mayChange('accounts.voucher_maker_checker', $admin->fresh()),
            'প্রশাসক নিজেই মালিক — দাবিটা কিছু মাপছে না।');

        $page = $this->actingAs($admin->fresh())->get(route('system_admin.control-panel'))->assertOk();
        $page->assertDontSee('name="scope[]" value="accounts.voucher_maker_checker"', false);
        $page->assertSee('name="scope[]" value="accounts.require_narration"', false);

        // ⓘ ব্রাউজার যা পাঠাত: খোলা ঘর তার মান নিয়ে, বন্ধ ঘর কিছুই না
        $this->put(route('system_admin.control-panel.update'), [
            'scope' => ['accounts.require_narration'],
            'settings' => ['accounts.require_narration' => '1'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue((bool) tap(app(SettingsService::class))->flush()->get('accounts.voucher_maker_checker'),
            'বন্ধ ঘরটা বদলে গেল।');
    }
}
