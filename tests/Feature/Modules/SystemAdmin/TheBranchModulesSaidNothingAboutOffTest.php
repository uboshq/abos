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

    /** ⭐ প্রতিটা বন্ধযোগ্য মডিউলের সারিতে প্রভাব — ঠিক কয়টা পর্দা, কোন শাখা থেকে, আর "তথ্য মুছবে না"। */
    public function test_every_switchable_row_says_what_turning_it_off_does(): void
    {
        $html = $this->page();

        $sales = app(ModuleRegistry::class)->get('sales');
        $screens = collect($sales->menu)->flatten(1)->reject(fn ($item) => (bool) ($item['planned'] ?? false))->count();

        $this->assertGreaterThan(0, $screens);

        $row = $this->rowOf($html, 'sales');

        $this->assertStringContainsString('data-module-impact', $row, '⛔ বিক্রয়ের সারিতে বন্ধের প্রভাব নেই।');
        $this->assertStringContainsString(
            __('system_admin::branch_module.off_impact', ['screens' => $screens, 'branch' => $this->branch->name()], 'bn'),
            $row,
            '⛔ প্রভাবের লেখায় পর্দার সংখ্যা বা শাখার নাম ভুল।',
        );
    }

    /**
     * ⛔ এখনো-না-বানানো (`planned`) সারি গোনায় পড়ে না — ওগুলো মেনুতে নিভে থাকে, বন্ধ করলে "সরে" না।
     *
     * ⓘ রেস্টুরেন্টই একমাত্র মডিউল যার planned সারি আছে; বিক্রয়ে নেই, তাই উপরের দাবি এই পার্থক্যটা কখনো দেখত না (মিউট্যান্ট
     * বেঁচে গিয়েছিল)। ⚠️ planned সারি আছে কি না আগে মাপা হয় — না থাকলে দাবিটা কিছুই মাপত না।
     */
    public function test_planned_rows_are_not_counted_as_screens(): void
    {
        $menu = collect(app(ModuleRegistry::class)->get('restaurant')->menu)->flatten(1);
        $planned = $menu->filter(fn ($item) => (bool) ($item['planned'] ?? false))->count();

        $this->assertGreaterThan(0, $planned, 'ⓘ রেস্টুরেন্টে আর planned সারি নেই — দাবিটার জন্য অন্য মডিউল খুঁজুন।');

        $row = $this->rowOf($this->page(), 'restaurant');

        $this->assertStringContainsString(
            __('system_admin::branch_module.off_impact', ['screens' => $menu->count() - $planned, 'branch' => $this->branch->name()], 'bn'),
            $row,
            '⛔ রেস্টুরেন্টের পর্দা-গোনায় এখনো-না-বানানো সারিও ধরা হয়েছে।',
        );
    }

    /** ⭐ বন্ধের আগে নিশ্চিত — ফর্মে confirmOff, আর প্রতিটা টিক নিজের নাম বয়। */
    public function test_switching_off_asks_first(): void
    {
        $html = $this->page();

        $form = $this->between($html, 'action="'.route('system_admin.branch-module.update').'"', '</form>');
        $head = substr($form, 0, (int) strpos($form, '>'));

        $this->assertStringContainsString('@submit="confirmOff($event)"', $head, '⛔ সংরক্ষণের আগে কিছু জিজ্ঞেস করা হয় না।');
        $this->assertStringContainsString('data-confirm-off=', $head, '⛔ জিজ্ঞাসার লেখা নেই।');
        $this->assertStringContainsString($this->branch->name(), $head, '⛔ জিজ্ঞাসায় শাখার নাম নেই।');

        $this->assertMatchesRegularExpression('/name="modules\[sales\]"[^>]*data-module-label="[^"]+"/s', $form,
            '⛔ টিকে মডিউলের নাম নেই — confirmOff কাকে বন্ধ বলবে জানে না।');
    }

    /** ⭐ সংরক্ষণ ডানে স্থির পট্টিতে — বাঁয়ে একা পড়ে থাকা পুরনো বোতামটা নেই। */
    public function test_save_rides_the_sticky_bar(): void
    {
        $form = $this->between($this->page(), 'action="'.route('system_admin.branch-module.update').'"', '</form>');

        $this->assertStringContainsString('data-form-actions', $form, '⛔ স্থির পট্টি নেই।');

        /* ⓘ দুটো সংরক্ষণ: বদল জমলে ভাসা পটির একটা, আর পট্টির একটা — বাঁয়ে একা তৃতীয়টা নয় */
        $this->assertSame(2, substr_count($form, 'type="submit"'), '⛔ পুরনো বাঁয়ের সংরক্ষণ রয়ে গেছে।');
    }

    private function page(): string
    {
        return (string) $this->get(route('system_admin.branch-module', ['branch' => $this->branch->id]))->assertOk()->getContent();
    }

    private function rowOf(string $html, string $code): string
    {
        $at = strpos($html, 'name="modules['.$code.']"');
        $this->assertNotFalse($at, "⛔ {$code}-এর সারি নেই।");
        $end = strpos($html, '</tr>', $at);

        return substr($html, $at, (int) $end - $at);
    }

    private function between(string $html, string $from, string $to): string
    {
        $at = strpos($html, $from);
        $this->assertNotFalse($at, "⛔ পাতায় «{$from}» নেই।");
        $end = strpos($html, $to, $at);
        $this->assertNotFalse($end);

        return substr($html, $at, $end - $at);
    }
}
