<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * তালিকার টুলবারের সব বোতাম বারেই — মালিক, ৩ অক্টোবর ২০২৬: *"colam bad dila keno?"* … *"টুলবার puranota dibe"*।
 *
 * ⓘ ১ অক্টোবরের নকশায় ঘনত্ব, কলাম, শেয়ার আর নতুন করে আনা "…"-এর ভিতরে গিয়েছিল (52977509); মালিকের চোখে
 * কলামটা হারিয়ে গিয়েছিল। পুরনো টুলবারই ফিরল — প্রতিটা বোতাম নিজের জায়গায়, কোনো "…" নেই।
 */
final class EveryToolButtonSitsOnTheBarTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_tool_button_is_on_the_bar_and_nothing_hides_behind_more(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        $html = (string) $this->get(route('customer.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-toolbar-more', $html, '⛔ "…" মেনু ফিরে এসেছে — মালিক পুরনো টুলবার চেয়েছেন।');

        foreach (['core.toolbar.density', 'core.toolbar.columns', 'core.toolbar.share_copy', 'core.toolbar.refresh',
            'core.toolbar.export', 'core.action.print'] as $key) {
            $this->assertStringContainsString('aria-label="'.e(__($key)).'"', $html, "⛔ \"{$key}\" টুলবারে নেই।");
        }

        $this->assertStringContainsString('name="show[]"', $html, '⛔ কলামের টিকগুলো নেই।');
        $this->assertStringContainsString('data-quick-find', $html, 'খোঁজার ঘর নেই।');
    }
}
