<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * শাখার মডিউলের পর্দা বলে বন্ধ করলে কী হবে, বন্ধের আগে নিশ্চিত হয়, আর সংরক্ষণ ডানে স্থির পট্টিতে।
 *
 * ── ⭐ সিস্টেম পর্দার নকশা §৬, ১০ অক্টোবর ২০২৬ ─────────────────────────────────────────
 * *"পরিষ্কার, কিন্তু সংরক্ষণ বাঁয়ে নিচে, আর বন্ধ করলে কী হবে বলা নেই"*। ⓘ fe-র নিয়মে ছোট বদল: শাখা ধরে ট্যাব আর "সব বাছাই"
 * (মালিকের দেখানো পর্দা) অক্ষত — শাখা × মডিউলের পুরো ছক নয়। কন্ট্রোল প্যানেলের (B) একই প্রভাব-লেখা আর একই confirmOff।
 */
final class TheBranchModulesSaidNothingAboutOffTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        $this->branch = Branch::query()->where('company_id', $company->id)->orderBy('id')->firstOrFail();
        CompanyContext::set($company->id, $this->branch->id);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($owner->fresh());
    }

    /** ⭐ প্রতিটা বন্ধযোগ্য মডিউলের সারিতে প্রভাব — ঠিক কয়টা পর্দা; এখনো-না-বানানো (`planned`) সারি গোনায় নেই। */
    public function test_every_switchable_row_says_how_many_screens_it_holds(): void
    {
        foreach (['sales', 'restaurant'] as $code) {
            $screens = collect(app(ModuleRegistry::class)->get($code)->menu)->flatten(1)->reject(fn ($item) => (bool) ($item['planned'] ?? false))->count();
            $this->assertGreaterThan(0, $screens);

            $this->assertStringContainsString(__('system_admin::module_board.screens_count', ['n' => $screens], 'bn'), $this->rowOf($this->page(), $code),
                "⛔ {$code}-এর সারিতে পর্দার সংখ্যা ভুল।");
        }
    }

    /** ⭐ বন্ধের আগে দেখায় — কোন শাখা, কোন মডিউল; নিশ্চিত না করলে কিছু লেখা হয় না। */
    public function test_switching_off_shows_the_list_first_and_writes_only_after_the_confirm(): void
    {
        $send = ['company' => $this->branch->company_id, 'pick' => [$this->branch->id], 'scope' => ['sales'], 'modules' => [], 'was' => ['sales' => 'on']];

        $html = (string) $this->post(route('system_admin.module.branches.preview'), $send)->assertOk()->getContent();
        $this->assertStringContainsString($this->branch->name(), $html);
        $this->assertStringContainsString('data-module-confirm', $html);
        $this->assertStringContainsString('data-module-warning="sales"', $html, '⛔ বিক্রয়ের উপর দাঁড়ানো মডিউলের সতর্কতা নেই।');
        $this->assertSame(0, \App\Models\BranchModule::query()->count(), '⛔ কেবল দেখাতেই লেখা হয়ে গেছে।');

        preg_match('/name="stamp" value="([^"]+)"/', $html, $m);
        // ⛔ ভুল ছাপ — লেখা থামে, তালিকা আবার আসে
        $this->post(route('system_admin.module.branches.apply'), [...$send, 'stamp' => 'x'])->assertOk()->assertSee(__('system_admin::module_board.stale'));
        $this->assertSame(0, \App\Models\BranchModule::query()->count());

        $this->post(route('system_admin.module.branches.apply'), [...$send, 'stamp' => $m[1]])->assertRedirect();
        $row = \App\Models\BranchModule::query()->sole();
        $this->assertSame([(int) $this->branch->id, 'sales', false], [(int) $row->branch_id, $row->module, $row->is_enabled]);
        $this->assertSame(1, \App\Models\AuditTrail::query()->where('auditable_type', \App\Models\BranchModule::class)->count(), '⛔ বদলটা অডিটে নেই।');
    }

    private function page(): string
    {
        return (string) $this->get(route('system_admin.module.branches', ['pick' => [$this->branch->id]]))->assertOk()->getContent();
    }

    private function rowOf(string $html, string $code): string
    {
        $at = strpos($html, 'data-module-row="'.$code.'"');
        $this->assertNotFalse($at, "⛔ {$code}-এর সারি নেই।");

        return substr($html, $at, (int) strpos($html, '</div>', $at) - $at);
    }
}
