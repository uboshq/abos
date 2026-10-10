<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * রোলের পর্দা অনুমতিগুলো বাংলায় বলে — "Coa", "Loan", "Till" নয়।
 *
 * ── ⛔ কী ভাঙা ছিল, সিস্টেম পর্দার নকশা §৩, ১০ অক্টোবর ২০২৬ ─────────────────────────────
 * ভাষা ফাইলে ৮০টার বেশি বিষয়ের বাংলা নাম লেখা ছিল (`accounts.coa` → "হিসাবের ছক"), অথচ পর্দায় বসত "Coa"। ⓘ চাবিটা নিজেই
 * বিন্দুওয়ালা, আর `__('…subjects.accounts.coa')` বিন্দু ধরে ভেঙে `subjects['accounts']['coa']` খোঁজে — পায় না, তখন
 * Str::headline। ⓘ এক অংশের চাবি (`accounts`) কাজ করত বলে কেউ টের পায়নি। তার উপর ২৭টা বিষয় আর ১৮টা ক্রিয়ার নামই ছিল না।
 *
 * ⭐ মালিকের ২২–২৪ সেপ্টেম্বরের গড়ন অক্ষত (fe, ১০ অক্টোবর: ছোট বদল) — কেবল নাম, "কেবল দেওয়াগুলো" আর নিচের স্থির পট্টি।
 */
final class TheRoleScreenSaidCoaAndTillTest extends TestCase
{
    use RefreshDatabase;

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['locale' => 'bn'])->save();
        $this->actingAs($owner->fresh());

        $this->role = Role::query()->where('company_id', $company->id)->where('name', 'accountant')->firstOrFail();
    }

    /** ⭐ পর্দায় বাংলা নাম, ইংরেজি headline নয় — ঠিক যে তিনটা নকশা দেখেছিল। */
    public function test_the_grid_reads_the_bengali_names_it_always_had(): void
    {
        $html = $this->page();

        foreach (['হিসাবের ছক', 'ঋণ', 'ক্যাশ টিল'] as $name) {
            $this->assertStringContainsString('>'.$name.'</th>', $html, "⛔ ছকে «{$name}» নেই — বাংলা নামটা পড়া হচ্ছে না।");
        }

        foreach (['Coa', 'Loan', 'Till'] as $english) {
            $this->assertStringNotContainsString('>'.$english.'</th>', $html, "⛔ ছকে এখনো ইংরেজি «{$english}»।");
        }
    }

    /**
     * ⭐ প্রতিটা ঘোষিত অনুমতির বিষয় আর ক্রিয়ার নাম দুই ভাষাতেই আছে।
     *
     * ⓘ নাম মডিউলের ঘোষণা থেকে, ডাটাবেস থেকে নয় — নতুন মডিউল একটা অনুমতি আনলে আর নাম না দিলে এই দাবিই প্রথম লাল হয়।
     */
    public function test_every_declared_subject_and_verb_has_a_name_in_both_languages(): void
    {
        $subjects = [];
        $verbs = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->permissions as $name) {
                $parts = explode('.', $name);
                $verbs[count($parts) > 1 ? array_pop($parts) : 'manage'] = true;
                $subjects[implode('.', $parts)] = true;
            }
        }

        foreach (['bn', 'en'] as $locale) {
            $names = trans('system_admin::permission.subjects', [], $locale);
            $verbNames = trans('system_admin::permission.verbs', [], $locale);

            $missing = array_values(array_filter(array_keys($subjects), fn (string $s) => ! is_string($names[$s] ?? null)));
            $this->assertSame([], $missing, "⛔ {$locale}: এই অনুমতির বিষয়ের নাম নেই — ".implode(', ', $missing));

            $missing = array_values(array_filter(array_keys($verbs), fn (string $v) => ! is_string($verbNames[$v] ?? null)));
            $this->assertSame([], $missing, "⛔ {$locale}: এই ক্রিয়ার নাম নেই — ".implode(', ', $missing));
        }
    }

    /** ⭐ "কেবল দেওয়াগুলো" ছকের সরঞ্জামে, লেখাসহ। */
    public function test_the_granted_only_switch_sits_with_the_grid_tools(): void
    {
        $tools = $this->between($this->page(), 'data-permission-grid', '</section>');

        $this->assertStringContainsString('data-permission-granted-only', $tools, '⛔ "কেবল দেওয়াগুলো" টিকটা নেই।');
        $this->assertStringContainsString('কেবল দেওয়াগুলো', $tools, '⛔ টিকটার পাশে লেখা নেই।');
    }

    /** ⭐ নিচের বাতিল · সংরক্ষণ স্থির পট্টিতে, বাতিল রোলের তালিকায় ফেরে; মাথার বোতাম দুটো থাকে। */
    public function test_cancel_and_save_ride_the_sticky_bar(): void
    {
        $html = $this->page();

        $bar = $this->between($html, 'data-form-actions', '</form>');

        $this->assertStringContainsString('href="'.route('system_admin.role.index').'"', $bar, '⛔ বাতিল পট্টিতে নেই।');
        $this->assertStringContainsString('type="submit"', $bar, '⛔ সংরক্ষণ পট্টিতে নেই।');

        $form = $this->between($html, 'action="'.route('system_admin.role.update', $this->role).'"', '</form>');
        $this->assertSame(2, substr_count($form, 'type="submit"'), '⛔ সংরক্ষণ মাথায় একটা আর পট্টিতে একটা থাকার কথা।');
    }

    private function page(): string
    {
        return (string) $this->get(route('system_admin.role.edit', $this->role))->assertOk()->getContent();
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
