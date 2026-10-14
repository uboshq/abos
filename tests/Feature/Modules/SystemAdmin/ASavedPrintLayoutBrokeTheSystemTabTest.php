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
 * ছাপার নকশা একবার সংরক্ষণ করলেই কন্ট্রোল প্যানেলের "সিস্টেম প্রশাসন" ট্যাব ৫০০ দিত — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬।
 *
 * ⛔ ছাপার পর্দা কাগজের অংশগুলো তালিকা হিসেবে বসায় (`print.invoice.parts`, ধরন `json`)। কন্ট্রোল প্যানেল ঘরটা লেখার ঘরে
 * আঁকত, আর `value="{{ তালিকা }}"`-এ `htmlspecialchars()` ভেঙে পুরো ট্যাব বন্ধ। ⓘ আর হাতে বানানো `scope[]` দিয়ে ঐ তালিকার
 * জায়গায় একটা লেখা বসানো যেত, যা ছাপার কোড তালিকা চেয়ে পেয়ে ভাঙত।
 */
final class ASavedPrintLayoutBrokeTheSystemTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_system_tab_opens_after_a_print_layout_is_saved_and_its_list_cannot_be_overwritten(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());
        $now = fn (string $key) => tap(app(SettingsService::class))->flush()->get($key);

        app(SettingsService::class)->set('print.invoice.parts', ['title']);
        $this->assertSame(['title'], $now('print.invoice.parts'), 'তালিকাটাই বসেনি — দাবিটা কিছু মাপছে না।');

        $this->followingRedirects()
            ->get(route('system_admin.control-panel', ['tab' => 'system_admin']))
            ->assertOk();

        $this->put(route('system_admin.control-panel.update'), [
            'scope' => ['print.invoice.parts'],
            'settings' => ['print.invoice.parts' => 'x'],
        ])->assertRedirect();

        $this->assertSame(['title'], $now('print.invoice.parts'), 'কন্ট্রোল প্যানেল ছাপার তালিকার জায়গায় লেখা বসাল।');
    }
}
