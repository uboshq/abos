<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Events\ApprovalDecided;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Services\ApprovalFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Throwable;

/**
 * ⭐ প্রতিটা ঘোষিত অনুমোদন-কাজ — ছক চালু থাকলে কাগজ থামে, বন্ধ থাকলে যায় (২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⓘ মালিকের নির্দেশ ───────────────────────────────────────────────
 * *"সব জায়গায় সই, আর প্রতিটা চালু-বন্ধ করে দেখো।"*
 *
 * ── ⛔ যা এখানে বাঁধা, প্রতিটা কাজে ────────────────────────────────
 *   ১ · চালু ছক (এক স্তর, এই কোম্পানির একটা রোল) → `request()` একটা
 *       অপেক্ষমাণ অনুরোধ খোলে — কাগজ থামে।
 *   ২ · সেই অনুরোধে বানানেওয়ালা নন এমন একজন সইকারী সই দিলে → অনুমোদিত।
 *   ৩ · একই ছক `is_active = false` → `request()` `null` — কাগজ সোজা যায়।
 *
 * ⓘ কাজের তালিকা হাতে লেখা নয় — ছকের পর্দা যেখান থেকে পড়ে
 * ([[ApprovalFlowService::labels()]], অর্থাৎ মডিউলগুলোর `approvals`
 * ঘোষণা), এখানেও সেখান থেকেই ([[never-supply-the-name-yourself]])।
 *
 * ⚠️ ইঞ্জিন-স্তরের পরীক্ষা: কাগজ হিসেবে একটা শাখা-সারি যায় (পাশের
 * পরীক্ষাগুলোর মতো), আর ছক মডিউল-ব্যাপী (`document_type` খালি)।
 * সিদ্ধান্তের খবর (`ApprovalDecided`) নকল করা হয়, যাতে মডিউলের
 * শ্রোতারা শাখা-সারিকে নিজের কাগজ ভেবে না ধরে — এখানে মাপা হচ্ছে
 * কেবল ইঞ্জিনের চালু-বন্ধ।
 */
final class EveryApprovableActionStopsWhenOnAndPassesWhenOffTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Role $role;

    private User $maker;

    private User $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'ONOFF', 'name_en' => 'On Off Co']);
        CompanyContext::set($this->company->id);

        $this->role = Role::findOrCreate('OnOff Signer', 'web');

        $this->maker = $this->person('maker');
        $this->signer = $this->person('signer');

        $this->signer->assignRole($this->role);
        $this->signer->unsetRelation('roles');
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_the_declared_list_is_not_empty(): void
    {
        $this->assertGreaterThanOrEqual(25, count($this->declared()),
            'ঘোষিত অনুমোদন-কাজ ২৫-এর কম — খোঁজা ভাঙলে নিচের পরীক্ষা শূন্যের উপর সবুজ হত।');
    }

    public function test_every_declared_action_stops_when_on_signs_off_and_passes_when_off(): void
    {
        Event::fake([ApprovalDecided::class]);

        $declared = $this->declared();
        $this->assertGreaterThanOrEqual(25, count($declared));

        $broken = [];
        $passed = 0;

        foreach ($declared as $key) {
            try {
                $problem = $this->checkOne($key);
            } catch (Throwable $e) {
                $problem = 'threw '.class_basename($e).': '.$e->getMessage();
            }

            if ($problem === null) {
                $passed++;
            } else {
                $broken[] = "  {$key} — {$problem}";
            }
        }

        $this->assertSame([], $broken, implode("\n", array_merge(
            ['এই অনুমোদন-কাজগুলো চালু-বন্ধে ঠিক আচরণ করেনি ('.count($broken).' / '.count($declared).') —'],
            $broken,
        )));

        $this->assertSame(count($declared), $passed);
    }

    /** ⓘ `null` মানে তিন দাবিই টিকেছে; নইলে কোনটা ভাঙল তার বর্ণনা। */
    private function checkOne(string $key): ?string
    {
        [$module, $action] = explode('.', $key, 2);

        $flow = ApprovalFlow::create([
            'module' => $module,
            'action' => $action,
            'document_type' => '',
            'is_active' => true,
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $flow->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_ROLE,
            'approver_id' => $this->role->id,
        ]);

        // ── ১ · চালু → থামে ──────────────────────────────────────────
        $asked = $this->engine()->request($this->paper(), $module, $action, '1000', null, 'on/off test', $this->maker->id);

        if ($asked === null) {
            return 'flow ON, but request() returned null — the paper would post with no signature';
        }

        if ($asked->status !== Approval::PENDING) {
            return "flow ON, request opened with status {$asked->status}, not pending";
        }

        // ── ২ · দ্বিতীয় মানুষের সই → অনুমোদিত ─────────────────────────
        if ($this->engine()->canDecide($asked, $this->maker)) {
            return 'the maker can sign their own paper';
        }

        $signed = $this->engine()->approve($asked, $this->signer, 'signed');

        if ($signed->fresh()->status !== Approval::APPROVED) {
            return 'signer approved, status is '.$signed->fresh()->status;
        }

        // ── ৩ · বন্ধ → যায় ──────────────────────────────────────────
        $flow->update(['is_active' => false]);

        $off = $this->engine()->request($this->paper(), $module, $action, '1000', null, 'on/off test', $this->maker->id);

        if ($off !== null) {
            return 'flow OFF (is_active=false), but request() still opened approval #'.$off->id;
        }

        return null;
    }

    /**
     * ছকের পর্দা যে কাজগুলো বসাতে দেয় — মডিউলের ঘোষণা থেকে।
     *
     * @return list<string>
     */
    private function declared(): array
    {
        return array_keys(app(ApprovalFlowService::class)->labels());
    }

    private function paper(): Branch
    {
        return Branch::create(['code' => 'OF'.uniqid(), 'name_en' => 'Doc']);
    }

    private function person(string $who): User
    {
        $user = User::create([
            'name' => ucfirst($who),
            'email' => $who.'-'.uniqid().'@onoff.test',
            'password' => 'x',
        ]);

        $user->companies()->attach($this->company->id);

        return $user;
    }

    /**
     * ⚠️ প্রতিবার নতুন ইঞ্জিন — সে `scoped` আর ছকগুলো একবারই তোলে;
     * পুরনোটা রাখলে "বন্ধ" করাটা সে দেখতই না, আর দাবি ৩ মিথ্যা হত।
     */
    private function engine(): ApprovalEngine
    {
        $this->app->forgetInstance(ApprovalEngine::class);

        return $this->app->make(ApprovalEngine::class);
    }
}
