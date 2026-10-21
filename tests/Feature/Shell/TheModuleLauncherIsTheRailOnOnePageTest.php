<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * ⭐ ⊞ সব মডিউল — বারে ফিরল, Odoo-র হোমের মতো পুরো পর্দা (মালিক, fe-র মাধ্যমে, ২১ অক্টোবর ২০২৬; [[shell.launcher]])।
 *
 * দাবি, আসল পাতার দরজা দিয়ে (ABOS রূপ):
 *  - লঞ্চার আর রেল একই মডিউল, একই ক্রমে, একই ঠিকানায় — উৎস একটাই ($menu), তাই অনুমতি আর মডিউলের সুইচ দুই জায়গায় এক;
 *  - যাঁর কোনো মডিউলের অনুমতি নেই, তাঁর লঞ্চারে সেই টাইল নেই; বন্ধ মডিউলের টাইল কারও নেই;
 *  - প্রতিটা টাইলের ঠিকানা খোলে (২০০) — মরা টাইল নেই;
 *  - খোঁজা ব্রাউজারে (shell.js-এর `launcherMatches`, vitest: resources/js/components/launcher.test.js) — এখানে তার চাবি:
 *    প্রতিটা ঘরে `data-launcher-keys` বাংলা আর ইংরেজি দুই নামসহ, আর "বিক্রয়" কেবল বিক্রয়ের চাবিতে;
 *  - টাইলের কোণে ঐ মডিউলের সইয়ের অপেক্ষা — ইনবক্সের হুবহু হিসাব; শূন্যে কিছু নেই;
 *  - বোতাম বারে, "≡"-এর পরে আর লোগোর আগে; নাম "সব মডিউল", চাবি Alt+M; পাতাটা dialog, ভেতরে grid আর খোঁজার ঘর।
 */
final class TheModuleLauncherIsTheRailOnOnePageTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->owner->forceFill(['ui' => 'navy', 'locale' => 'bn'])->save();
    }

    public function test_the_launcher_lists_exactly_the_rails_modules(): void
    {
        $html = $this->page($this->owner);
        $tiles = $this->tiles($html);

        $this->assertGreaterThan(5, count($tiles), 'প্রস্তুতিটাই ভুল — মালিকের লঞ্চারে প্রায় কিছুই নেই।');
        $this->assertSame(array_values($this->rail($html)), array_values($tiles), '⛔ লঞ্চার আর রেল আলাদা মডিউল দেখায় (বা আলাদা ক্রমে, আলাদা ঠিকানায়)।');
    }

    public function test_every_tile_opens(): void
    {
        $dead = [];
        foreach ($this->tiles($this->page($this->owner)) as $code => $url) {
            $status = $this->actingAs($this->owner->fresh())->get($url)->getStatusCode();
            if ($status !== 200) {
                $dead[] = "{$code} → {$url} ({$status})";
            }
            $this->app['auth']->forgetGuards();
        }

        $this->assertSame([], $dead, "⛔ মরা টাইল:\n".implode("\n", $dead));
    }

    public function test_no_tile_for_a_module_the_person_may_not_open(): void
    {
        $seller = $this->person(['sales.invoice.view']);
        $html = $this->page($seller);
        $tiles = $this->tiles($html);

        $this->assertArrayHasKey('sales', $tiles, 'প্রস্তুতিটাই ভুল — বিক্রয়ের অনুমতি আছে, অথচ টাইল নেই।');
        foreach (['purchase', 'accounts', 'hr', 'system_admin'] as $code) {
            $this->assertArrayNotHasKey($code, $tiles, "⛔ অনুমতি ছাড়াই {$code}-এর টাইল।");
        }
        $this->assertSame(array_values($this->rail($html)), array_values($tiles), '⛔ এই মানুষের লঞ্চার আর রেল আলাদা।');
    }

    public function test_no_tile_for_a_switched_off_module(): void
    {
        $this->assertArrayHasKey('purchase', $this->tiles($this->page($this->owner)), 'প্রস্তুতিটাই ভুল — চালু অবস্থায় ক্রয়ের টাইল নেই।');

        app(SettingsService::class)->set('purchase.enabled', false);
        $html = $this->page($this->owner);

        $this->assertArrayNotHasKey('purchase', $this->tiles($html), '⛔ বন্ধ মডিউলের টাইল লঞ্চারে।');
        $this->assertSame(array_values($this->rail($html)), array_values($this->tiles($html)), '⛔ মডিউল বন্ধের পরে লঞ্চার আর রেল আলাদা।');
    }

    public function test_the_search_keys_carry_both_names_and_bikroy_finds_only_sales(): void
    {
        preg_match_all('/data-launcher-keys="([^"]*)">\s*<a href="[^"]*" data-launcher-tile="([^"]+)"/u', $this->page($this->owner), $m, PREG_SET_ORDER);
        $keys = [];
        foreach ($m as [, $words, $code]) {
            $keys[$code] = $this->fold(html_entity_decode($words));
        }

        $this->assertArrayHasKey('sales', $keys, 'প্রস্তুতিটাই ভুল — বিক্রয়ের টাইলের চাবি নেই।');
        $this->assertStringContainsString($this->fold('বিক্রয়'), $keys['sales'], '⛔ বিক্রয়ের চাবিতে বাংলা নাম নেই।');
        $this->assertStringContainsString('sales', $keys['sales'], '⛔ বিক্রয়ের চাবিতে ইংরেজি নাম নেই।');
        $this->assertStringContainsString('purchase', $keys['purchase'] ?? '', '⛔ ক্রয়ের চাবিতে ইংরেজি নাম নেই।');

        $found = array_keys(array_filter($keys, fn (string $k): bool => str_contains($k, $this->fold('বিক্রয়'))));
        $this->assertSame(['sales'], $found, '⛔ "বিক্রয়" খুঁজলে কেবল বিক্রয় থাকার কথা।');
    }

    public function test_a_tile_shows_its_modules_waiting_signatures_and_nothing_at_zero(): void
    {
        $clerk = $this->person([]);
        $flow = ApprovalFlow::create(['module' => 'purchase', 'action' => 'order', 'is_active' => true]);
        ApprovalFlowStep::create(['approval_flow_id' => $flow->id, 'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER, 'approver_id' => $this->owner->id]);
        foreach ([1, 2] as $n) {
            Approval::create(['company_id' => $this->company->id, 'approvable_type' => User::class, 'approvable_id' => $clerk->id + $n,
                'module' => 'purchase', 'action' => 'order', 'amount' => '1000', 'status' => Approval::PENDING, 'current_level' => 1,
                'requested_by' => $clerk->id, 'requested_at' => now()]);
        }
        $this->app->forgetInstance(ApprovalEngine::class);

        $html = $this->page($this->owner);
        $engine = app(ApprovalEngine::class);
        $owner = $this->owner->fresh();
        $this->assertGreaterThan(0, $engine->pendingQueryFor($owner)->where('module', 'purchase')->count(), 'প্রস্তুতিটাই ভুল — ক্রয়ের সই অপেক্ষায় নেই।');

        foreach (array_keys($this->tiles($html)) as $code) {
            $tile = $this->between($html, 'data-launcher-tile="'.$code.'"', '</a>');
            $waiting = $engine->pendingQueryFor($owner)->where('module', $code)->count();

            if ($waiting === 0) {
                $this->assertStringNotContainsString('data-launcher-waiting', $tile, "⛔ {$code}: কিছু অপেক্ষায় নেই, তবু ব্যাজ।");
            } else {
                $this->assertStringContainsString('data-launcher-waiting="'.$waiting.'"', $tile, "⛔ {$code}: ব্যাজের সংখ্যা ইনবক্সের {$waiting} নয়।");
            }
        }
    }

    public function test_the_button_sits_in_the_bar_and_opens_a_searchable_dialog_grid(): void
    {
        $html = $this->page($this->owner);
        $bar = $this->between($html, '<header data-topbar="full"', '</header>');

        $this->assertSame(1, preg_match('/<button\b[^>]*\sdata-module-launcher\s[^>]*>/', $bar, $found, PREG_OFFSET_CAPTURE), '⛔ বারে ⊞ নেই।');
        [$tag, $button] = $found[0];
        $menu = strpos($bar, 'data-menu-toggle');
        $logo = strpos($bar, 'data-shell-logo');
        $this->assertTrue($menu !== false && $logo !== false && $menu < $button && $button < $logo, '⛔ ⊞ "≡"-এর পরে আর লোগোর আগে নয়।');

        $this->assertStringContainsString('aria-label="'.__('core.ui.all_modules').'"', $tag, '⛔ ⊞-এর নাম "সব মডিউল" নয়।');
        $this->assertStringContainsString('aria-keyshortcuts="Alt+M"', $tag, '⛔ ⊞-এর চাবি Alt+M ঘোষিত নয়।');

        $sheet = $this->between($bar, 'data-module-launcher-sheet', '</header>');
        $this->assertMatchesRegularExpression('/^[^>]*role="dialog" aria-modal="true"/', $sheet, '⛔ পাতাটা dialog নয়।');
        $this->assertStringContainsString('role="grid"', $sheet, '⛔ টাইলগুলো grid-এ নেই।');
        $this->assertStringContainsString('data-launcher-search', $sheet, '⛔ পাতায় খোঁজার ঘর নেই।');
        $this->assertStringContainsString('pops-onto-page', $this->between($bar, 'data-module-launcher-sheet', '>'),
            '⛔ পাতাটা বারের ৩৬px নিয়মের ভেতরে — টাইলগুলো চ্যাপ্টা হবে।');

        // ⓘ ফোনে বারে ⊞ নেই (জায়গা নেই) — নিচের বারের "আরও" একই পাতা খোলে
        $bottom = $this->between($html, '<nav class="bottom-nav', '</nav>');
        $this->assertMatchesRegularExpression('/data-bottom-launcher\s+@click="\$dispatch\(\'open-module-launcher\'\)"/', $bottom,
            '⛔ ফোনের "আরও" সব-মডিউলের পাতা খোলে না — ফোনে লঞ্চারে পৌঁছানোর পথ নেই।');
        $this->assertStringContainsString('@open-module-launcher.window="show()"', $bar, '⛔ লঞ্চার "আরও"-র ডাক শোনে না।');
    }

    /** @return array<string, string> লঞ্চারের টাইল, ক্রমে: কোড => ঠিকানা */
    private function tiles(string $html): array
    {
        $sheet = $this->between($html, 'data-module-launcher-sheet', '</header>');
        preg_match_all('/<a href="([^"]+)" data-launcher-tile="([^"]+)"/', $sheet, $m, PREG_SET_ORDER);

        $tiles = [];
        foreach ($m as [, $url, $code]) {
            $tiles[$code] = html_entity_decode($url);
        }

        return $tiles;
    }

    /** @return list<string> রেলের মডিউল-টাইলের ঠিকানা, ক্রমে (হোমের চিহ্ন বাদ, পাতা নেই এমন টাইল বাদ) */
    private function rail(string $html): array
    {
        $nav = $this->between($this->between($html, '<aside', '</aside>'), '<nav', '</nav>');
        preg_match_all('/<a\s+href="([^"]+)"(?![^>]*data-rail-home)[^>]*\saria-label="[^"]*"/', $nav, $m);

        return array_map(fn (string $u): string => html_entity_decode($u), $m[1]);
    }

    private function fold(string $text): string
    {
        $text = class_exists(\Normalizer::class) ? (string) \Normalizer::normalize($text, \Normalizer::FORM_C) : $text;

        return mb_strtolower($text);
    }

    private function page(User $who): string
    {
        $this->app['auth']->forgetGuards();

        return (string) $this->actingAs($who->fresh())->get(route('dashboard'))->assertOk()->getContent();
    }

    /** @param  list<string>  $keys */
    private function person(array $keys): User
    {
        $user = User::factory()->create(['is_active' => true, 'ui' => 'navy']);
        $user->companies()->attach($this->company, ['is_active' => true]);
        $user->forceFill(['current_company_id' => $this->company->id, 'current_branch_id' => $this->company->defaultBranch()?->id])->save();
        if ($keys !== []) {
            CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(
                array_map(fn (string $k) => Permission::findOrCreate($k, 'web'), $keys)));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "প্রস্তুতিটাই ভুল — পাতায় {$from} নেই।");
        $end = strpos($html, $to, $start + strlen($from));

        return substr($html, $start, $end === false ? null : $end - $start);
    }
}
