<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Module\ModuleRegistry;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Core\Support\Money;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * রোল ও অনুমতি — এক পর্দা, আর তাতে যা যা থাকার কথা।
 *
 * ── ⭐ মালিকের দুইটা কথা, ২৩–২৪ সেপ্টেম্বর ২০২৬ ───────────────────────
 * *"ekoi jinis dui porda keno?"* — তালিকা আর সম্পাদনা এক করা।
 * *"ami plane ze layout diyechi tik oi vabei kaj doro"* — স্পেক §২ হুবহু।
 *
 * ── ⚠️ কেন এই ফাইলটা লাগে ────────────────────────────────────────────
 * ⓘ এই কাজের প্রায় সবটাই **পর্দার**, আর পর্দার বদল সবচেয়ে নীরবে ফিরে
 * যায়: একটা ব্লেড ভুলে পুরনো রূপে ফিরলে কোনো টেস্ট লাল হত না, আর
 * মালিক তিন দিন পরে নিজে দেখে বলতেন।
 */
final class TheRoleScreenWasTwoScreensTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    /**
     * ⭐ তালিকার পর্দাটা এখন সম্পাদনার পর্দাই।
     *
     * ⓘ দুইটা দাবি একসাথে: বাঁ কলামের তালিকা আছে, **আর** মাঝখানে
     * *"একটা রোল বাছুন"* — ⛔ কেবল প্রথমটা মাপলে পুরনো তালিকার পর্দাও
     * সবুজ থাকত, কারণ ওখানেও রোলের নাম ছাপা হত।
     */
    public function test_the_list_screen_is_now_the_editor(): void
    {
        $this->get(route('system_admin.role.index'))
            ->assertOk()
            ->assertViewIs('system_admin::role.form')
            ->assertSee(__('system_admin::permission.pick_a_role'))
            ->assertSee(__('system_admin::permission.group_business'));
    }

    /**
     * ⛔ আর মিথ্যা নিয়মটা উঠে গেছে — মালিকের ধরা দ্বিতীয় জিনিস।
     *
     * ── ⚠️ ছাঁচটা আমার বসানো নয়, মডিউলের ──────────────────────────
     * ⓘ নামটা নেওয়া হয় একটা **সত্যিকারের ঘোষিত রোল টেমপ্লেট** থেকে
     * (`Manager`, `Field Sales`, `HR`…), তারপর তার সাথে একটা আলাদা
     * শব্দ জোড়া — কারণ ঐ নামটা ইতিমধ্যেই বসে আছে।
     *
     * ⛔ পুরনো নিয়মটা (`^[a-z][a-z0-9_]*$`) ঠিক ঐ ছাঁচটাই আটকাত —
     * অর্থাৎ পণ্য নিজের ডেটাই নিজের নিয়ম ভাঙত। ⚠️ আর ফলটা ছিল নীরব:
     * নাম না ছুঁয়ে একটা অনুমতি বদলালে সংরক্ষণ হত, কিন্তু নামের একটা
     * অক্ষর বদলালেই পর্দা বলত ছাঁচ ভুল।
     *
     * ⚠️ নামটা হাতে লেখা হয় না: ⛔ আমি নিজে `'Depot Manager'` টাইপ
     * করলে দাবিটা প্রমাণ করত কেবল *"আমার বাছা নামটা চলে"*, আর মডিউলের
     * ছাঁচ বদলে গেলেও চুপ থাকত।
     */
    public function test_a_role_may_be_named_the_way_the_modules_name_theirs(): void
    {
        $declared = null;

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach (array_keys($module->roleTemplates) as $name) {
                if (preg_match('/[^a-z0-9_]/', (string) $name)) {
                    $declared = (string) $name;
                    break 2;
                }
            }
        }

        $this->assertNotNull($declared,
            '⛔ কোনো মডিউলই ছোট-হাতের বাইরে কোনো রোল ঘোষণা করে না — দাবিটার ভিত্তিই নেই।');

        $name = $declared.' II';

        $this->post(route('system_admin.role.store'), [
            'name' => $name,
            'permissions' => ['sales.invoice.view'],
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(
            Role::query()->where('name', $name)->first(),
            "⛔ মডিউলের নিজের ছাঁচের নাম ({$name}) নেওয়া হয়নি।",
        );
    }

    /**
     * ⭐ আর পাল্টা-দাবি: নিয়মটা **উঠেছে**, সব নিয়ম নয়।
     *
     * ⛔ যাচাই পুরোপুরি খুলে দিলে উপরের দাবিটাও সবুজ থাকত, আর তখন একই
     * নামে দুইটা রোল বসত — কোন অনুমতি কার, তার কোনো উত্তর থাকত না।
     */
    public function test_a_duplicate_name_is_still_refused(): void
    {
        $existing = Role::query()
            ->where('company_id', CompanyContext::id())
            ->firstOrFail();

        $this->post(route('system_admin.role.store'), ['name' => $existing->name])
            ->assertSessionHasErrors('name');
    }

    /**
     * ⭐ `∅` — *"এই পর্দায় এই কাজটাই নেই"*, স্পেক §৩।
     *
     * ── ⛔ কেন এটা `✕`-এর থেকে আলাদা হতেই হবে ───────────────────────
     * ⚠️ স্পেকে লেখা: *"`∅` আর `✕` এক দেখালে ব্যবহারকারী টিক দিতেন এমন
     * একটা কাজে যা অস্তিত্বেই নেই, আর ভাবতেন সিস্টেম তাঁর সিদ্ধান্ত
     * মানছে না।"*
     *
     * ⓘ আগে ঘরটায় একটা `—` বসত `aria-hidden` সহ — অর্থাৎ পর্দা-পাঠকের
     * কাছে ঘরটা **নীরব** ছিল। ⛔ যিনি চোখে দেখেন না, তিনি "দেওয়া নেই"
     * আর "এমন কাজই নেই" আলাদা করতে পারতেন না।
     */
    public function test_an_action_a_screen_does_not_have_says_so_in_words(): void
    {
        $html = (string) $this->get(route('system_admin.role.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('∅', $html,
            '⛔ ফাঁকা ঘরের চিহ্নটাই নেই — স্পেক §৩-এর ছয় রকম ঘরের একটা।');

        /*
         * ⚠️ চিহ্নটা থাকাই যথেষ্ট নয় — কথাটাও থাকতে হবে। ⛔ নাহলে
         * দাবিটা সবুজ থাকত এমন একটা পর্দাতেও যেখানে `∅` কেবল আঁকা,
         * আর পর্দা-পাঠকের কাছে আগের মতোই নীরব।
         */
        $this->assertStringContainsString(__('system_admin::permission.state_absent'), $html,
            '⛔ ঘরটা কেন ফাঁকা, সেটা কোথাও লেখা নেই — পর্দা-পাঠকের কাছে ঘরটা নীরব।');
    }

    /**
     * ⭐ ছক বাছার সরঞ্জাম — স্পেক §২.৫।
     *
     * ⓘ তিনটা বোতাম আর খোঁজার ঘরটা পর্দায় আছে কি না। ⚠️ আচরণটা JS-এ,
     * আর তার নিজের পাহারা আলাদা (`permissions.test.js`) — এখানে দাবিটা
     * কেবল এই যে **ঘরগুলো পর্দায় পৌঁছেছে**। ⛔ ব্লেড থেকে বাদ পড়লে
     * JS-এর ঐ উনিশটা দাবি সবুজই থাকত, কারণ ওরা নিজের ছাঁচ নিজে বানায়।
     */
    public function test_the_matrix_has_its_bulk_controls(): void
    {
        $html = (string) $this->get(route('system_admin.role.create'))->assertOk()->getContent();

        foreach (['data-permission-search', 'data-permission-bulk="all"',
            'data-permission-bulk="none"', 'data-permission-bulk="view"'] as $hook) {
            $this->assertStringContainsString($hook, $html,
                "⛔ ছকের সরঞ্জামটা পর্দায় নেই: {$hook}");
        }
    }

    /**
     * ⭐ অনুমোদনের ক্ষমতা ডান কলামে — স্পেক §২.৬।
     *
     * ── ⛔ কেন এটা ছাড়া পর্দাটা মিথ্যা বলত ──────────────────────────
     * ⓘ অনুমোদনের ক্ষমতা অনুমতির ছকে আসে না — ওটা [[ApprovalFlowStep]]-এ।
     * ⚠️ ফলে রোলের পর্দা দেখে কেউ বুঝতেই পারতেন না যে এই রোলটা পাঁচ লাখ
     * টাকার কাগজ ছাড়তে পারে, অথচ পর্দাটা *"এই রোল কী পারে"* প্রশ্নেরই
     * উত্তর দেয় বলে দেখায়।
     */
    public function test_the_side_panel_shows_what_this_role_may_approve(): void
    {
        $role = $this->anOrdinaryRole();

        /*
         * ⚠️ মডিউল আর কাজটা রেজিস্ট্রি থেকে নেওয়া, টাইপ করা নয় — ⛔ আমি
         * নিজে `'sales' / 'discount'` লিখলে ঘোষণাটা সরে গেলেও দাবিটা
         * সবুজ থাকত, কারণ পর্দা তখন কাঁচা নামটাই ছাপে।
         */
        [$moduleCode, $action] = $this->anApprovalAction();

        $flow = ApprovalFlow::query()->create([
            'company_id' => CompanyContext::id(),
            'module' => $moduleCode,
            'action' => $action,
            'threshold_amount' => '500000',
            'is_active' => true,
        ]);

        ApprovalFlowStep::query()->create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'step_name' => 'first',
            'approver_type' => ApprovalFlowStep::BY_ROLE,
            'approver_id' => $role->id,
        ]);

        /*
         * ⚠️ সংখ্যাটার ছাঁচও হাতে লেখা হয় না: ⛔ `'৫,০০,০০০'` টাইপ
         * করলে দাবিটা লাল হত যেদিন কমার নিয়ম বা অঙ্কের ভাষা বদলায় —
         * অথচ ক্ষমতাটা দেখানোয় কোনো ভুল হয়নি। ⓘ পর্দা যা দিয়ে লেখে,
         * দাবিও তাই দিয়ে পড়ে।
         */
        $this->get(route('system_admin.role.edit', $role))
            ->assertOk()
            ->assertSee(__('system_admin::permission.approval_power'))
            ->assertSee(Money::format('500000'), false);
    }

    /** ⓘ মালিকের রোলটা বাদ — ওটা এই পর্দা থেকে খোলাই যায় না। */
    private function anOrdinaryRole(): Role
    {
        return Role::query()
            ->where('company_id', CompanyContext::id())
            ->where('name', '!=', PermissionSyncer::SUPER_ADMIN_ROLE)
            ->firstOrFail();
    }

    /**
     * ⓘ যে মডিউল অনুমোদনের কাজ ঘোষণা করে, তার প্রথমটা।
     *
     * @return array{0: string, 1: string}
     */
    private function anApprovalAction(): array
    {
        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach (array_keys($module->approvals) as $action) {
                return [$module->code, (string) $action];
            }
        }

        $this->fail('⛔ কোনো মডিউলই অনুমোদনের কাজ ঘোষণা করে না — দাবিটার ভিত্তিই নেই।');
    }

    /**
     * ⛔ আর পাল্টা-দাবি: যে রোল কোথাও ধাপ নয়, তার ঘরটা আঁকাই হয় না।
     *
     * ⚠️ এটা না মাপলে ঘরটা সবার জন্য বসত — কারো কারো ক্ষেত্রে খালি —
     * আর একটা খালি ছক দেখে মনে হত ব্যবস্থাটা আছে অথচ কিছু বসানো হয়নি।
     * ⓘ আসল কথাটা আলাদা: এই রোল অনুমোদনের কোনো ধাপই নয়।
     */
    public function test_a_role_that_approves_nothing_shows_no_such_box(): void
    {
        $role = $this->anOrdinaryRole();

        ApprovalFlowStep::query()
            ->where('approver_type', ApprovalFlowStep::BY_ROLE)
            ->where('approver_id', $role->id)
            ->delete();

        $this->get(route('system_admin.role.edit', $role))
            ->assertOk()
            ->assertDontSee(__('system_admin::permission.approval_power'));
    }
}
