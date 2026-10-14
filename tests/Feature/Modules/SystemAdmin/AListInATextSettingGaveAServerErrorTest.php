<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * লেখার সেটিংয়ে তালিকা এলে ৫০০, আর হিসাবের সেটিংসে "১০,০০০" জমা হত ১০ — সেটিং লাইন-ধরে নিরীক্ষা, ১৪ অক্টোবর ২০২৬।
 *
 * ⛔ তিন সেটিং-দরজাই লেখার ঘরে `default => $raw` রাখত — `settings[key][]=x` এলে [[SettingsService::set()]]-এ
 * `(string) array` ভেঙে ৫০০। আর হিসাবের সেটিংস পূর্ণসংখ্যা কাটত `(int)` দিয়ে, যা কন্ট্রোল প্যানেলে আগেই সারানো ছিল।
 */
final class AListInATextSettingGaveAServerErrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_list_in_a_text_box_keeps_the_old_value_and_commas_are_read_on_the_accounts_page(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $now = fn (string $key) => tap(app(SettingsService::class))->flush()->get($key);

        $key = 'notification.quiet_start';
        $before = $now($key);

        $this->put(route('system_admin.control-panel.update'), ['scope' => [$key], 'settings' => [$key => ['23:00']]])
            ->assertRedirect();
        $this->assertSame($before, $now($key), 'লেখার ঘরে তালিকা এসে কিছু একটা বসে গেল।');

        $this->put(route('accounts.settings.update'), ['settings' => ['accounts.print.note_footnote' => ['x']]])
            ->assertRedirect();

        $this->put(route('accounts.settings.update'), ['settings' => ['accounts.backdate_days' => '১০,০০০']])
            ->assertRedirect();
        $this->assertSame(10000, (int) $now('accounts.backdate_days'), '"১০,০০০" হিসাবের সেটিংসে অন্য সংখ্যা হয়ে বসল।');
    }
}
