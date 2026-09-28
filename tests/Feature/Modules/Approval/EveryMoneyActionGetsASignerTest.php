<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Approval;

use App\Core\Module\ModuleRegistry;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Services\MoneyFlowDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\SignsMoneyOff;
use Tests\TestCase;

/**
 * ⭐ প্রতিটা টাকার কাজে হিসাবরক্ষকের ছক — [[MoneyFlowDefaults]] আর
 * `abos:sync-money-flows` (নকশা §৬, দাবি ৯)।
 *
 * ── ⚠️ কেন এটা আজই চলে ──────────────────────────────────────────────
 * ছক বসানো ইঞ্জিনের বদলের উপর নির্ভর করে না — আজকের ইঞ্জিনও এই ছকগুলো
 * পড়ে আর মানে। ⓘ তাই দ্বিতীয় ধাপের আগেই সব কোম্পানিতে ছক বসিয়ে রাখা
 * যায়, আর দ্বিতীয় ধাপের দিনে কোনো কোম্পানির টাকা আটকায় না।
 *
 * ── ⛔ যে দাবিগুলো এখানে বাঁধা ──────────────────────────────────────
 *   · প্রতিটা `moves_money` কাজে ঠিক একটা ছক, স্তর ১-এ ঐ কোম্পানির
 *     Accountant, সীমা নেই
 *   · দুইবার চালালে কিছু দ্বিগুণ হয় না
 *   · চলতি ছক (অন্য সইকারী, সীমা, বন্ধ) অক্ষত
 *   · রোল না পেলে কিছু বসে না, রোলও বানানো হয় না — আর কমান্ড
 *     কোম্পানির নাম ধরে লাল হয়
 *   · অন্য কোম্পানির Accountant কখনো ধার করা হয় না
 */
final class EveryMoneyActionGetsASignerTest extends TestCase
{
    use RefreshDatabase;
    use SignsMoneyOff;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create(['code' => 'MF', 'name_en' => 'Money Flow Co']);
        CompanyContext::set($this->company->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_every_money_action_gets_one_flow_signed_by_this_companys_accountant(): void
    {
        $expected = $this->moneyKeysFromTheRegistry();
        $role = $this->accountantRoleIn($this->company);

        $report = app(MoneyFlowDefaults::class)->ensure($this->company);

        $this->assertFalse($report['missing_role']);
        $this->assertSame((int) $role->id, $report['role_id']);
        $this->assertEqualsCanonicalizing($expected, $report['created']);

        $flows = $this->flowsIn($this->company);

        $this->assertCount(count($expected), $flows);

        foreach ($flows as $flow) {
            $key = $flow->module.'.'.$flow->action;

            $this->assertContains($key, $expected, "A flow was made for {$key}, which moves no money.");
            $this->assertTrue((bool) $flow->is_active, $key);
            $this->assertSame('', (string) $flow->document_type, $key);
            $this->assertNull($flow->threshold_amount, "{$key} got a threshold — small money papers would slip past.");
            $this->assertCount(1, $flow->steps, $key);

            $step = $flow->steps->first();

            $this->assertSame(1, (int) $step->level, $key);
            $this->assertSame(ApprovalFlowStep::BY_ROLE, $step->approver_type, $key);
            $this->assertSame((int) $role->id, (int) $step->approver_id, $key);
        }
    }

    public function test_running_it_twice_adds_nothing(): void
    {
        $this->accountantRoleIn($this->company);

        $first = app(MoneyFlowDefaults::class)->ensure($this->company);
        $countAfterFirst = $this->flowsIn($this->company)->count();

        $second = app(MoneyFlowDefaults::class)->ensure($this->company);

        $this->assertNotEmpty($first['created']);
        $this->assertSame([], $second['created']);
        $this->assertEqualsCanonicalizing($first['created'], $second['skipped']);
        $this->assertSame($countAfterFirst, $this->flowsIn($this->company)->count());
    }

    public function test_a_flow_the_company_already_set_is_left_exactly_as_it_was(): void
    {
        $this->accountantRoleIn($this->company);

        [$module, $action] = explode('.', $this->moneyKeysFromTheRegistry()[0], 2);

        $boss = User::create(['name' => 'Boss', 'email' => 'boss-'.uniqid().'@t.test', 'password' => 'x']);

        $own = ApprovalFlow::create([
            'module' => $module,
            'action' => $action,
            'document_type' => '',
            'threshold_amount' => '5000',
        ]);

        ApprovalFlowStep::create([
            'approval_flow_id' => $own->id,
            'level' => 1,
            'approver_type' => ApprovalFlowStep::BY_USER,
            'approver_id' => $boss->id,
        ]);

        $report = app(MoneyFlowDefaults::class)->ensure($this->company);

        $this->assertContains($module.'.'.$action, $report['skipped']);
        $this->assertNotContains($module.'.'.$action, $report['created']);

        $mine = $this->flowsIn($this->company)->where('module', $module)->where('action', $action);

        $this->assertCount(1, $mine, 'A second flow was laid over the company\'s own.');

        $kept = $mine->first();

        $this->assertSame((int) $own->id, (int) $kept->id);
        $this->assertSame('5000.0000', (string) $kept->threshold_amount);
        $this->assertCount(1, $kept->steps);
        $this->assertSame(ApprovalFlowStep::BY_USER, $kept->steps->first()->approver_type);
        $this->assertSame((int) $boss->id, (int) $kept->steps->first()->approver_id);
    }

    public function test_a_switched_off_flow_is_left_off_but_named(): void
    {
        $this->accountantRoleIn($this->company);

        $key = $this->moneyKeysFromTheRegistry()[0];
        [$module, $action] = explode('.', $key, 2);

        ApprovalFlow::create(['module' => $module, 'action' => $action, 'document_type' => '', 'is_active' => false]);

        $report = app(MoneyFlowDefaults::class)->ensure($this->company);

        $this->assertContains($key, $report['inactive']);
        $this->assertCount(1, $this->flowsIn($this->company)->where('module', $module)->where('action', $action));
        $this->assertFalse((bool) $this->flowsIn($this->company)->where('module', $module)->where('action', $action)->first()->is_active);
    }

    public function test_without_an_accountant_nothing_is_made_and_no_role_is_invented(): void
    {
        $report = app(MoneyFlowDefaults::class)->ensure($this->company);

        $this->assertTrue($report['missing_role']);
        $this->assertSame('missing', $report['role_problem']);
        $this->assertSame([], $report['created']);
        $this->assertEqualsCanonicalizing($this->moneyKeysFromTheRegistry(), $report['wanting']);
        $this->assertCount(0, $this->flowsIn($this->company));
        $this->assertSame(0, Role::query()->where('company_id', $this->company->id)->count(),
            'The service made a role up — it must only ever find one.');
    }

    /** ⓘ ডেমো ছোট হাতে `accountant` বসায় — সেটাও একই রোল। */
    public function test_the_role_is_found_whatever_its_case(): void
    {
        $lower = CompanyContext::forCompany((int) $this->company->id, fn () => Role::findOrCreate('accountant', 'web'));

        $report = app(MoneyFlowDefaults::class)->ensure($this->company);

        $this->assertFalse($report['missing_role']);
        $this->assertSame((int) $lower->id, $report['role_id']);
    }

    public function test_another_companys_accountant_is_never_borrowed(): void
    {
        $other = Company::create(['code' => 'MO', 'name_en' => 'Other Co']);
        $this->accountantRoleIn($other);

        $report = app(MoneyFlowDefaults::class)->ensure($this->company);

        $this->assertTrue($report['missing_role']);
        $this->assertCount(0, $this->flowsIn($this->company));
        $this->assertCount(0, $this->flowsIn($other), 'Ensuring one company wrote flows into another.');
    }

    /**
     * ⭐ অন্য কোম্পানির ছক এই কোম্পানির "আছে" নয়।
     *
     * ⛔ খোঁজাটা কোম্পানি না ছাঁকলে পাশের কোম্পানিতে বসা ছক দেখে এখানে "আগেই
     * আছে" বলত, আর এই কোম্পানির টাকার কাজ নীরবে সইকারী-ছাড়া থেকে যেত —
     * কনসোলে প্রসঙ্গ ভুল থাকলেই ঠিক সেটা ঘটে ([[MoneyFlowDefaults::ensureHere()]])।
     */
    public function test_a_flow_in_another_company_does_not_count_as_ours(): void
    {
        $other = Company::create(['code' => 'MO', 'name_en' => 'Other Co']);
        $this->accountantRoleIn($other);
        app(MoneyFlowDefaults::class)->ensure($other);

        $this->assertNotEmpty($this->flowsIn($other), 'দৃশ্যটাই বানানো যায়নি — পাশের কোম্পানিতে ছক বসেনি।');

        $this->accountantRoleIn($this->company);
        $report = app(MoneyFlowDefaults::class)->ensure($this->company);

        $this->assertEqualsCanonicalizing($this->moneyKeysFromTheRegistry(), $report['created'],
            'পাশের কোম্পানির ছক দেখে এই কোম্পানির টাকার কাজ বাদ পড়েছে।');
        $this->assertCount(count($this->moneyKeysFromTheRegistry()), $this->flowsIn($this->company));
    }

    public function test_the_command_lays_the_flows_and_says_so(): void
    {
        $this->accountantRoleIn($this->company);

        $this->artisan('abos:sync-money-flows', ['--company' => 'MF'])->assertSuccessful();

        $this->assertCount(count($this->moneyKeysFromTheRegistry()), $this->flowsIn($this->company));

        // ⓘ দ্বিতীয়বারও সবুজ, আর কিছুই যোগ হয় না
        $this->artisan('abos:sync-money-flows', ['--company' => 'MF'])->assertSuccessful();
        $this->assertCount(count($this->moneyKeysFromTheRegistry()), $this->flowsIn($this->company));
    }

    public function test_the_command_names_a_company_with_no_accountant_and_fails(): void
    {
        $this->artisan('abos:sync-money-flows', ['--company' => 'MF'])
            ->expectsOutputToContain('MF')
            ->assertFailed();

        $this->assertCount(0, $this->flowsIn($this->company));
    }

    public function test_the_command_refuses_a_company_code_that_does_not_exist(): void
    {
        $this->artisan('abos:sync-money-flows', ['--company' => 'NO-SUCH'])->assertFailed();
    }

    /**
     * ⭐ trait-টা নিজেও পাহারায় — সই সত্যিই দ্বিতীয় মানুষের, আর ইঞ্জিন দিয়ে।
     */
    public function test_the_test_helper_signs_through_the_engine_as_a_second_person(): void
    {
        $this->moneyFlowsFor($this->company);

        [$module, $action] = explode('.', $this->moneyKeysFromTheRegistry()[0], 2);

        $maker = User::create(['name' => 'Maker', 'email' => 'maker-'.uniqid().'@t.test', 'password' => 'x']);
        $paper = \App\Models\Branch::create(['code' => 'D'.uniqid(), 'name_en' => 'Doc']);

        $asked = app(\App\Core\Engines\Approval\ApprovalEngine::class)
            ->request($paper, $module, $action, '250', userId: $maker->id);

        $this->assertNotNull($asked, 'The default flow did not catch a money paper.');

        $signed = $this->signOffAsSecondPerson($paper, $action);

        $this->assertSame(\App\Models\Approval::APPROVED, $signed->status);
        $this->assertNotSame((int) $maker->id, (int) $signed->decisions()->first()->user_id);
    }

    // ── সহায়ক ────────────────────────────────────────────────────────

    /**
     * রেজিস্ট্রি থেকে সরাসরি — সেবার নিজের তালিকা দিয়ে নয়।
     *
     * ⚠️ সেবার `moneyActions()` দিয়ে প্রত্যাশা বানালে সেবা একটা কাজ বাদ
     * দিলে প্রত্যাশাও বাদ দিত, আর দাবিটা কখনো লাল হত না
     * ([[never-supply-the-name-yourself]])।
     *
     * @return list<string>
     */
    private function moneyKeysFromTheRegistry(): array
    {
        $keys = [];

        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->movesMoney as $action) {
                $keys[] = $module->code.'.'.$action;
            }
        }

        $this->assertNotEmpty($keys, 'The registry names no money action — every claim here would look at nothing.');

        return $keys;
    }

    /** @return \Illuminate\Support\Collection<int, ApprovalFlow> */
    private function flowsIn(Company $company)
    {
        return ApprovalFlow::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->with('steps')
            ->get();
    }
}
