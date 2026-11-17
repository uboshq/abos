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

        // ⭐ পরিকল্পনা §৩.১ (মালিক, ১৪ নভেম্বর ২০২৬) — বারে: কলাম, নামান ▾ (ভেতরে প্রিন্ট); লিংক কপি দৃশ্যের মেনুতে
        foreach (['core.toolbar.columns', 'core.toolbar.download', 'core.action.print'] as $key) {
            $this->assertStringContainsString('aria-label="'.e(__($key)).'"', $html, "⛔ \"{$key}\" টুলবারে নেই।");
        }
        $this->assertStringContainsString('data-copy-link', $html, '⛔ দৃশ্যের মেনুতে "লিংক কপি" নেই।');

        // ⛔ পরিকল্পনা থেকে বাদ — ঘনত্ব (নিজের সেটিংসে), রিফ্রেশ (পাতা নিজে নতুন তথ্য আনে), আলাদা শেয়ার, টেবিল-কার্ডের সুইচ
        foreach (['core.toolbar.density', 'core.toolbar.refresh', 'core.toolbar.share'] as $key) {
            $this->assertStringNotContainsString('aria-label="'.e(__($key)).'"', $html, "⛔ \"{$key}\" বারে ফিরে এসেছে — পরিকল্পনায় নেই।");
        }
        $this->assertStringNotContainsString('data-view-switch', $html, '⛔ টেবিল-কার্ডের সুইচ বারে ফিরে এসেছে।');

        $this->assertStringContainsString('name="show[]"', $html, '⛔ কলামের টিকগুলো নেই।');
        $this->assertStringContainsString('data-quick-find', $html, 'খোঁজার ঘর নেই।');
    }
}
