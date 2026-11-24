<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\BranchModule;
use App\Models\Company;
use App\Models\User;
use App\Modules\SystemAdmin\Support\ModuleBoard;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ কোম্পানি আর শাখার মডিউল — দশ কোম্পানির একশো শাখায়ও চলে (মালিক, ২৪ নভেম্বর ২০২৬, নমুনা system-plan-v4)।
 *
 * দাবি: শাখা বাড়লে প্রশ্ন বাড়ে না · চাবি ছাড়া ৪০৩, অন্য কোম্পানিতে চাবি ছাড়া ৪০৪ · কোম্পানিতে বন্ধ মডিউল শাখায় চালু হয় না · তালাবন্ধ
 *   মডিউল নড়ে না · মিশ্র ঘর না ছুঁলে কিছু বদলায় না · অন্য কোম্পানির শাখা মিশলে কিছুই লেখা হয় না · সতর্কতা শিকল নয়
 */
final class AHundredBranchesOnOneModulePageTest extends TestCase
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
        $this->actingAs($this->owner);
    }

    public function test_a_hundred_branches_ask_no_more_questions_than_a_few(): void
    {
        $ask = function (array $query): int {
            $n = 0;
            DB::listen(function () use (&$n): void { $n++; });
            $this->get(route('system_admin.module.branches', $query))->assertOk();

            return $n;
        };

        $this->get(route('system_admin.module.branches'))->assertOk();   // ⓘ প্রথম খোলার উষ্ণতা মাপে পড়ে না
        $few = $ask(['pick' => 'all']);

        for ($i = 0; $i < 100; $i++) {
            $branch = Branch::query()->create(['company_id' => $this->company->id, 'code' => 'Z'.$i, 'name_en' => 'Branch '.$i, 'is_active' => true]);
            BranchModule::query()->create(['branch_id' => $branch->id, 'module' => 'hr', 'is_enabled' => false]);
        }

        // ⓘ DB::listen জমে — আগের শ্রোতা আলাদা গোনা রাখে, তাই তুলনা ঠিক থাকে
        $many = $ask(['pick' => 'all']);
        $this->assertLessThanOrEqual($few + 5, $many, "⛔ শাখা ধরে আলাদা প্রশ্ন: {$few} → {$many}");
    }

    public function test_the_pages_stand_behind_the_settings_key(): void
    {
        $stranger = User::query()->where('email', '!=', 'owner@abos.test')
            ->whereHas('companies', fn ($q) => $q->whereKey($this->company->id))->get()
            ->first(fn (User $u) => ! $u->can('system_admin.settings.manage'));
        $this->assertNotNull($stranger, 'যার চাবি নেই এমন কেউ নেই — পরীক্ষাটা কিছু মাপছে না।');
        $this->actingAs($stranger);

        foreach (['companies', 'branches', 'screens', 'app'] as $page) {
            $this->get(route('system_admin.module.'.$page))->assertForbidden();
        }
        foreach (['companies', 'branches'] as $page) {
            $this->post(route('system_admin.module.'.$page.'.preview'))->assertForbidden();
            $this->post(route('system_admin.module.'.$page.'.apply'))->assertForbidden();
        }
    }

    public function test_a_company_the_person_may_not_manage_is_neither_shown_nor_written(): void
    {
        $other = Company::query()->create(['code' => 'NOTMINE', 'name_en' => 'Not mine', 'is_active' => true]);

        $this->get(route('system_admin.module.companies'))->assertOk()->assertDontSee('Not mine');
        $this->get(route('system_admin.module.branches', ['company' => $other->id]))->assertNotFound();
        $this->post(route('system_admin.module.companies.apply'), ['pick' => [$other->id], 'scope' => ['hr'], 'was' => ['hr' => 'on'], 'stamp' => 'x'])->assertNotFound();
    }

    public function test_a_branch_of_another_company_in_the_pick_stops_the_whole_write(): void
    {
        $mine = Branch::query()->where('is_active', true)->firstOrFail();
        $theirs = Branch::acrossAllCompanies()->where('company_id', '!=', $this->company->id)->firstOrFail();

        $this->post(route('system_admin.module.branches.preview'), ['pick' => [$mine->id, $theirs->id], 'scope' => ['hr'], 'was' => ['hr' => 'on']])->assertNotFound();
        $this->assertSame(0, BranchModule::acrossAllCompanies()->count());
    }

    public function test_a_branch_cannot_turn_on_what_the_company_turned_off(): void
    {
        $branch = Branch::query()->where('is_active', true)->firstOrFail();
        BranchModule::query()->create(['branch_id' => $branch->id, 'module' => 'hr', 'is_enabled' => false]);
        app(SettingsService::class)->set('hr.enabled', false);

        $plan = app(ModuleBoard::class)->plan([$branch->id => 'x'], [$branch->id => ['hr']], ['hr' => true], ['hr']);
        $this->assertSame([], $plan['changes']);
        $this->assertSame(['hr'], $plan['blocked']);

        $html = (string) $this->get(route('system_admin.module.branches', ['pick' => [$branch->id]]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="modules\[hr\]"[^>]*disabled/s', $html, '⛔ কোম্পানিতে বন্ধ মডিউলের ঘর খোলা।');
    }

    public function test_locked_modules_never_move_and_a_mixed_row_left_alone_changes_nothing(): void
    {
        $board = app(ModuleBoard::class);

        foreach (['system_admin', ...ModuleBoard::LOCKED] as $code) {
            $this->assertTrue($board->locked($code), "⛔ {$code} তালাবন্ধ নয়");
            $this->assertSame([], $board->plan([1 => 'x'], [1 => []], [$code => false])['changes'], "⛔ {$code} বন্ধ করা গেল");
        }

        $this->assertSame([], $board->wanted(['hr'], ['hr' => '1'], ['hr' => 'mixed'], []), '⛔ না ছোঁয়া মিশ্র ঘর বদলে গেল');
        $this->assertSame(['hr' => true], $board->wanted(['hr'], ['hr' => '1'], ['hr' => 'mixed'], ['hr' => '1']));
        $this->assertSame(['hr' => false], $board->wanted(['hr'], [], ['hr' => 'mixed'], []));
    }

    public function test_the_warning_names_what_stays_on_and_turns_nothing_off(): void
    {
        $plan = app(ModuleBoard::class)->plan([1 => 'x'], [1 => []], ['inventory' => false]);

        $this->assertSame([['id' => 1, 'name' => 'x', 'module' => 'inventory', 'on' => false]], $plan['changes'], '⛔ নীরব শিকল — অন্য মডিউলও বদলাল।');
        $this->assertContains('sales', $plan['warnings'][0]['needs']);
    }

    public function test_companies_are_written_into_the_same_setting_with_an_audit_row(): void
    {
        $send = ['pick' => [$this->company->id], 'scope' => ['hr'], 'modules' => [], 'was' => ['hr' => 'on']];
        $html = (string) $this->post(route('system_admin.module.companies.preview'), $send)->assertOk()->getContent();
        preg_match('/name="stamp" value="([^"]+)"/', $html, $m);
        $this->post(route('system_admin.module.companies.apply'), [...$send, 'stamp' => $m[1]])->assertRedirect();

        app(SettingsService::class)->flush();
        $this->assertFalse((bool) app(SettingsService::class)->get('hr.enabled', true), '⛔ সেই পুরনো সেটিংয়ে লেখা হয়নি।');
        $this->assertGreaterThan(0, \App\Models\AuditTrail::query()->where('auditable_type', \App\Models\Setting::class)->count());
    }

    public function test_copying_shows_the_source_and_writes_nothing_by_itself(): void
    {
        [$a, $b] = Branch::query()->where('is_active', true)->orderBy('id')->limit(2)->get()->all();
        BranchModule::query()->create(['branch_id' => $a->id, 'module' => 'hr', 'is_enabled' => false]);

        $html = (string) $this->get(route('system_admin.module.branches', ['pick' => [$b->id], 'copy_from' => $a->id]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-module-row="hr" data-state="off"/', $html);
        $this->assertSame(1, BranchModule::query()->count());
    }

    public function test_the_download_is_the_matrix(): void
    {
        $csv = (string) $this->get(route('system_admin.module.branches', ['export' => 'csv']))->assertOk()->getContent();

        foreach (['sales', 'hr', 'inventory'] as $code) {
            $this->assertStringContainsString(app(ModuleRegistry::class)->get($code)->label(), $csv, "⛔ নামানো ফাইলে {$code}-এর কলাম নেই।");
        }
    }
}
