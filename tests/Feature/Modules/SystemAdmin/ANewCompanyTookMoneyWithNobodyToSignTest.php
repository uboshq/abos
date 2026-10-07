<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\SystemAdmin;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\RoleTemplateRegistry;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalFlow;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Approval\Services\MoneyFlowDefaults;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⛔ পর্দা থেকে খোলা নতুন কোম্পানিতে সইয়ের একটাও ছক আসত না।
 *
 * ── ⛔ কী ঘটত, ২৯ সেপ্টেম্বর ২০২৬ ────────────────────────────────────
 * মালিক নতুন কোম্পানি খুলে কাজ শুরু করতেন, আর রসিদ · পরিশোধ · খরচ ·
 * বেতন — টাকার **প্রতিটা** কাগজ সই ছাড়াই খাতায় বসে যেত।
 * ⓘ [[ApprovalEngine::request()]] ছক না পেলে `null` ফেরায়, অর্থাৎ
 * *"এগিয়ে যাও"*। ⚠️ কোনো পর্দা কিছু বলত না, কোথাও লাল হত না।
 *
 * ── ⓘ কারণটা ভাঙা যুক্তি নয়, একটা অনুপস্থিত জোড়া ───────────────────
 * [[MoneyFlowDefaults::provisionCompany()]] পুরো লেখা ছিল, কিন্তু
 * `Approval/module.php`-এ `'provisions'` কী-টা ছিল না — তাই
 * [[CompanyProvisioner]] কখনো ওটা ডাকত না। ⭐ মাপা: কী ছাড়া ০/১৫ ছক,
 * কী সহ ১৫/১৫।
 *
 * ── ⚠️ আর যা ভুল ধরেছিলাম, সেটাও লিখে রাখছি ─────────────────────────
 * প্রথমে মনে হয়েছিল ভূমিকাও আসে না। ⛔ ওটা ভুল ছিল: আমি কেবল
 * `CompanyProvisioner::create()` মেপেছিলাম, অথচ পর্দার কন্ট্রোলার তার
 * পরেই [[CompanyProvisioner::grantAccess()]] ডাকে, যা `sync()` চালিয়ে
 * ভূমিকা বসায়। ⓘ অর্ধেক পথ মেপে পুরো সিদ্ধান্ত নেওয়ার ফল।
 *
 * ⭐ তাই ভূমিকার দাবিটা (নিচে) সারাই নয়, **পাহারা** — আজ যা ঠিক আছে
 * তা যেন কাল কেউ নীরবে সরিয়ে না ফেলে।
 *
 * ── ⓘ কেন গণনা ঘোষণা থেকে, হাতে লেখা তালিকা থেকে নয় ────────────────
 * ⚠️ একবার Manager আর Warehouse-এর চাবির নাম নিজে টাইপ করে মেপেছিলাম,
 * আর চারটার একটাও আসল নাম ছিল না — ফলে "নেই" পড়েছিলাম যেখানে সব ঠিক।
 * ⭐ তাই এখানে সব সংখ্যা আসে মডিউলের নিজের ঘোষণা থেকে
 * ([[MoneyFlowDefaults::moneyActions()]], [[RoleTemplateRegistry]])।
 */
final class ANewCompanyTookMoneyWithNobodyToSignTest extends TestCase
{
    use RefreshDatabase;

    private User $maker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->maker = User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_every_money_action_a_module_declares_gets_its_flow(): void
    {
        $company = $this->companyFromTheScreen();

        $declared = array_map(
            fn (array $pair) => $pair['module'].'.'.$pair['action'],
            app(MoneyFlowDefaults::class)->moneyActions(),
        );

        /*
         * ⛔ ঘোষণা খালি হলে নিচের তুলনা খালি বনাম খালি হত, আর দাবিটা
         * কোনোদিন ব্যর্থ হতে পারত না।
         */
        $this->assertGreaterThan(10, count($declared),
            'কোনো মডিউলই টাকার কাজ ঘোষণা করছে না — তাহলে এই দাবিটা কিছুই মাপে না।');

        $live = CompanyContext::forCompany($company->id, fn () => ApprovalFlow::query()
            ->where('is_active', true)
            ->get()
            ->map(fn (ApprovalFlow $flow) => $flow->module.'.'.$flow->action)
            ->all());

        $missing = array_values(array_diff($declared, $live));

        $this->assertSame([], $missing,
            'নতুন কোম্পানিতে এই টাকার কাজগুলোর সইয়ের ছক নেই, তাই কাগজগুলো '
            .'সই ছাড়াই খাতায় বসবে: '.implode(', ', $missing));
    }

    public function test_every_role_the_templates_declare_arrives_with_its_keys(): void
    {
        $company = $this->companyFromTheScreen();

        $templates = app(RoleTemplateRegistry::class)->all();

        $this->assertGreaterThan(5, count($templates),
            'কোনো ভূমিকার ছাঁচই ঘোষিত নেই — দাবিটা তখন ফাঁকা।');

        CompanyContext::forCompany($company->id, function () use ($templates) {
            $shortfall = [];

            foreach ($templates as $name => $wanted) {
                $role = Role::query()
                    ->where('name', $name)
                    ->where('company_id', CompanyContext::id())
                    ->first();

                if ($role === null) {
                    $shortfall[] = $name.' (ভূমিকাটাই নেই)';

                    continue;
                }

                /*
                 * ⚠️ কেবল সত্যিই তৈরি হওয়া চাবিই গোনা হয়: কোনো মডিউল
                 * বন্ধ থাকলে তার চাবি থাকে না, আর সেটা ছাঁচের দোষ নয়।
                 */
                $real = Permission::query()->whereIn('name', $wanted)->pluck('name')->all();

                $has = $role->permissions->pluck('name')->all();

                $absent = array_values(array_diff($real, $has));

                if ($absent !== []) {
                    $shortfall[] = $name.' → '.implode(', ', $absent);
                }
            }

            $this->assertSame([], $shortfall,
                'নতুন কোম্পানিতে ছাঁচের ভূমিকা বা চাবি অনুপস্থিত: '
                .implode(' | ', $shortfall));
        });
    }

    public function test_a_payment_in_a_new_company_waits_for_a_signature(): void
    {
        /*
         * ⓘ পরিশোধ বেছে নেওয়া হয়েছে, রসিদ নয় — মালিকের নিয়মে **নিজের
         * বাক্সে নগদ রসিদে** সই লাগে না ([[VoucherApproval::stopping()]]),
         * তাই ওটা দিয়ে মাপলে দাবিটা ছাড়ের উপর দাঁড়াত, ছকের উপর নয়।
         */
        $company = $this->companyFromTheScreen();

        $signer = $this->someoneWhoCanSignIn($company);

        /*
         * ⛔ কোম্পানি বদলানো হয় পর্দার নিজের দরজা দিয়ে।
         *
         * ⓘ প্রথমে কেবল [[CompanyContext::set()]] দিয়েছিলাম, আর দাবিটা
         * লাল হয়েছিল **ভুল কারণে** — `404`। ⚠️ কারণ HTTP অনুরোধ
         * নিজে প্রসঙ্গ আবার ঠিক করে ([[ResolveCompanyContext]]), তাই কাগজটা
         * এক কোম্পানির আর অনুরোধটা আরেক কোম্পানির হয়ে যাচ্ছিল।
         *
         * ⭐ ভুল কারণে লাল হওয়া দাবি একদিন ভুল কারণে সবুজও হয়।
         */
        $this->actingAs($this->maker);

        $this->post(route('company.switch'), [
            'company_id' => $company->id,
            'branch_id' => $company->defaultBranch()?->id,
        ])->assertRedirect();

        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        /*
         * ⛔ টাকার খাত এড়ানো হয়েছে, আর সেটা ইচ্ছাকৃত।
         *
         * ⓘ প্রথমে নগদ দিয়ে একটা পরিশোধ লিখেছিলাম। ⚠️ ছক না থাকা
         * সত্ত্বেও কাগজটা খসড়াই রয়ে গিয়েছিল — কারণ
         * [[VoucherService::assertCashLandsInOwnTill()]] ফিরিয়ে দিয়েছিল:
         * একদম নতুন কোম্পানিতে বানানেওয়ালার নিজের কোনো ক্যাশ-বাক্স নেই।
         *
         * ⛔ আর প্রত্যাখ্যানটা ফিরে আসে `back()` হয়ে — হুবহু সইয়ের অপেক্ষার
         * মতো দেখতে। ⭐ তাই "কাগজটা খসড়াই আছে" কথাটা কিছুই প্রমাণ
         * করত না, আর সারাইয়ের পর দাবিটা ভুল কারণে সবুজ হত।
         *
         * ⓘ জার্নালও একটা ঘোষিত টাকার কাজ (`accounts.journal`), কিন্তু
         * দুই পাশে টাকা-বহির্ভূত খাত বসালে কোনো টাকার নিয়ম আটকাতে
         * পারে না — তখন থামার একমাত্র কারণ সই।
         */
        $heads = Account::query()->where('is_group', false)
            ->whereNull('money_kind')->limit(2)->get();

        $this->assertCount(2, $heads, 'টাকা-বহির্ভূত দুইটা খাতই পাওয়া যায়নি।');

        $voucher = app(VoucherService::class)->create(
            [
                'type' => Voucher::JOURNAL,
                'trx_date' => now()->toDateString(),
            ],
            [
                ['account_id' => $heads[0]->id, 'debit' => '250', 'credit' => '0'],
                ['account_id' => $heads[1]->id, 'debit' => '0', 'credit' => '250'],
            ],
        );

        /*
         * ⓘ প্রথমে দরজাটা সত্যিই খুলল কি না — নাহলে নিচের "খসড়াই আছে"
         * কথাটা একটা `404`-ও মেনে নিত।
         */
        $answer = $this->post(route('accounts.voucher.post', $voucher));

        $this->assertNotSame(404, $answer->getStatusCode(),
            'কাগজটাই খুঁজে পাওয়া যায়নি — দাবিটা তখন সই নয়, প্রসঙ্গ মাপছে।');

        $answer->assertRedirect();

        /*
         * ⛔ পর্দা নিজে কী বলল, সেটাই প্রমাণ — কাগজের অবস্থা নয়।
         * ⓘ একটা নিয়ম-প্রত্যাখ্যানও কাগজটাকে খসড়া রাখে, আর সেটাও
         * `back()` হয়েই ফেরে।
         */
        $answer->assertSessionHasNoErrors();

        $answer->assertSessionHas('warning', __('accounts::message.voucher_approval_pending', [
            'no' => $voucher->document_no,
        ]));

        $voucher->refresh();

        $this->assertSame(DocumentStatus::DRAFT, $voucher->status,
            'সই ছাড়াই কাগজটা পোস্ট হয়ে গেছে।');

        $this->assertSame(0, LedgerEntry::query()->where('source_id', $voucher->id)
            ->where('source_type', 'voucher')->count(),
            'কাগজটা সই ছাড়াই খাতায় সারি লিখেছে।');

        $waiting = Approval::query()
            ->where('approvable_type', Voucher::class)
            ->where('approvable_id', $voucher->id)
            ->first();

        $this->assertNotNull($waiting, 'কোনো সইয়ের অনুরোধই তৈরি হয়নি।');

        /*
         * ⭐ আর সই দেওয়ার পর কাগজটা সত্যিই খাতায় যেতে পারে — নাহলে
         * পাহারাটা দরজা নয়, দেয়াল।
         *
         * ── ⚠️ সই দিলেই নিজে পোস্ট হয় না, আর সেটা মেপে জানা ─────────
         * ⓘ শেষ সইয়ের পর নিজে-পোস্ট হওয়াটা **প্রতিটা মডিউলের নিজের**
         * listener-এ বসানো ([[ApprovalDecided]])। বিক্রয়ে দুইটা আছে
         * ([[ConfirmTheChallanOnTheLastSignature]],
         * [[FinishTheHeldSaleOnTheLastSignature]]) — ⛔ হিসাবের ভাউচারে
         * একটাও নেই।
         *
         * ⭐ তাই মানুষটা আবার "নিশ্চিত" চাপেন, আর এবার
         * [[VoucherApproval::stopping()]] `null` ফেরায় বলে কাগজটা বসে যায়।
         * ⓘ এই দাবিটা সেই আসল পথটাই মাপে; নিজে-পোস্ট না হওয়ার কথাটা
         * নিরীক্ষার রিপোর্টে ⚠️ হিসেবে লেখা আছে।
         */
        app(ApprovalEngine::class)->approve($waiting, $signer);

        $this->assertSame(DocumentStatus::DRAFT, $voucher->fresh()->status,
            'সই দিতেই কাগজটা নিজে পোস্ট হয়ে গেছে — রিপোর্টের ⚠️ কথাটা তাহলে পুরনো।');

        $this->actingAs($this->maker);

        $again = $this->post(route('accounts.voucher.post', $voucher));

        $again->assertSessionHasNoErrors();
        $again->assertRedirect();

        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->fresh()->status,
            'সই দেওয়ার পরেও কাগজটা খাতায় যাচ্ছে না — তাহলে সইটা একটা দেয়াল।');
    }

    public function test_a_missing_accountant_is_healed_not_ignored(): void
    {
        /*
         * ⛔ এই দাবিটা প্রথমে লিখেছিলাম "ভূমিকা না মিললে কোম্পানিই তৈরি হয় না"
         * বলে, আর সেটা কোনোদিন লাল হতে পারত না। ⓘ কারণ
         * [[MoneyFlowDefaults::provisionCompany()]] নিজেই সারিয়ে নেয়:
         * হিসাবরক্ষকের ভূমিকা না পেলে সে `PermissionSyncer::sync()` ডাকে,
         * আর ছাঁচ থেকে ভূমিকাটা আবার বসে যায়।
         *
         * ⭐ তাই যা সত্যি, দাবিটা এখন সেটাই মাপে: ভূমিকাটা মুছে গেলেও
         * নতুন কোম্পানি **ছক ছাড়া জন্মায় না** — সারিয়ে নেওয়া হয়।
         *
         * ⚠️ আর `Log::warning`-এর বদলে যে ব্যতিক্রমটা বসানো হয়েছে, সেটা
         * থাকে ঐ পথটার জন্য যেটা সারানো যায় না (একই নামের দুইটা ভূমিকা,
         * `_bin` collation-এ সম্ভব)। ⓘ সেই দশাটা এখানে বানানো যায় না, আর
         * বানাতে গিয়ে একটা নকল দাবি লেখার চেয়ে কথাটা লিখে রাখা ভালো।
         */
        $company = $this->companyFromTheScreen('HEALME');

        CompanyContext::forCompany($company->id, function () {
            ApprovalFlow::query()->delete();

            Role::query()
                ->where('name', MoneyFlowDefaults::ROLE)
                ->where('company_id', CompanyContext::id())
                ->firstOrFail()
                ->forceFill(['name' => 'Book Keeper'])
                ->save();
        });

        CompanyContext::forCompany($company->id, fn () => app(MoneyFlowDefaults::class)->provisionCompany());

        $after = CompanyContext::forCompany($company->id, fn () => [
            'role' => Role::query()->where('name', MoneyFlowDefaults::ROLE)
                ->where('company_id', CompanyContext::id())->exists(),
            'flows' => ApprovalFlow::query()->where('is_active', true)->count(),
        ]);

        $this->assertTrue($after['role'],
            'হিসাবরক্ষকের ভূমিকাটা ফিরে আসেনি, তাই ছকও বসবে না।');

        $this->assertSame(
            count(app(MoneyFlowDefaults::class)->moneyActions()),
            $after['flows'],
            'ভূমিকা ফিরলেও ঘোষিত প্রতিটা টাকার কাজের ছক বসেনি।',
        );
    }

    public function test_the_live_path_leaves_every_flow_switched_on(): void
    {
        /*
         * ⛔ এই দাবিটা একটা ধারের পাহারা।
         *
         * ⓘ [[\Database\Seeders\DemoSeeder]] ডেমো কোম্পানির ছকগুলো বসার
         * পর **বন্ধ** করে রাখে, কারণ আজকের শত শত টেস্ট সই-ছাড়া
         * কোম্পানি ধরে লেখা।
         *
         * ⚠️ ⛔ কিন্তু মালিক যে পথে কোম্পানি খোলেন সেটা সিডার নয় —
         * সেটা পর্দা। তাই এই দাবি পর্দার পথটাই মাপে: ওখানে প্রতিটা
         * ছক **চালু** থাকতে হবে। ⭐ কেউ যদি কোনোদিন বন্ধ করার কোডটা
         * সিডার থেকে সেবায় সরিয়ে নেন, এই দাবিটা লাল হবে।
         *
         * ⓘ আর কোডটা সিডারেই আছে কি না, সেটা আলাদা পাহারায়:
         * [[\Tests\Feature\Architecture\OnlyTheDemoSeederParksAnApprovalFlowTest]]।
         */
        $company = $this->companyFromTheScreen('LIVECO');

        $flows = CompanyContext::forCompany($company->id,
            fn () => ApprovalFlow::query()->get());

        $this->assertGreaterThan(10, $flows->count(),
            'পর্দার পথে কোনো ছকই বসেনি — তখন "সব চালু" কথাটা শূন্যের উপর দাঁড়ায়।');

        $off = $flows->reject(fn (ApprovalFlow $flow) => (bool) $flow->is_active)
            ->map(fn (ApprovalFlow $flow) => $flow->module.'.'.$flow->action)
            ->values()
            ->all();

        $this->assertSame([], $off,
            'পর্দা থেকে খোলা কোম্পানিতে এই ছকগুলো বন্ধ অবস্থায় বসেছে, অর্থাৎ '
            .'ওই কাগজগুলো সই ছাড়াই পোস্ট হবে: '.implode(', ', $off));
    }


    // ── সহায়ক ─────────────────────────────────────────────────────────

    /**
     * ⭐ ঠিক যেভাবে মানুষ খোলেন — পর্দার ফর্ম, পর্দার কন্ট্রোলার।
     *
     * ⚠️ সেবাটা সরাসরি ডাকা হয় না: কন্ট্রোলার `create()`-এর পরে
     * `grantAccess()`-ও ডাকে, আর ঐ দ্বিতীয় ডাকটাই ভূমিকা বসায়। ⓘ সেবাটা
     * একা মেপেই একবার ভুল সিদ্ধান্তে পৌঁছেছিলাম।
     */
    private function companyFromTheScreen(string $code = 'NEWCO'): Company
    {
        $this->actingAs($this->maker);

        $this->post(route('system_admin.company.store'), [
            'code' => $code,
            'name_en' => 'New Depot',
            'name_bn' => 'নতুন ডিপো',
            'branch_code' => 'MAIN',
            'branch_name_en' => 'Head Office',
            'year_name' => '2026-2027',
            'year_starts_on' => '2026-07-01',
            'year_ends_on' => '2027-06-30',
        ])->assertRedirect();

        return Company::query()->where('code', $code)->firstOrFail();
    }

    /**
     * ⓘ সই দেন **আলাদা একজন** — বানানেওয়ালা নিজের কাগজে সই দিতে পারেন না।
     */
    private function someoneWhoCanSignIn(Company $company): User
    {
        return CompanyContext::forCompany($company->id, function () use ($company) {
            $signer = User::query()->create([
                'name' => 'হিসাবরক্ষক',
                'email' => 'signer.newco@abos.test',
                'password' => bcrypt('password'),
                'is_active' => true,
            ]);

            $signer->companies()->syncWithoutDetaching([$company->id]);

            $signer->assignRole(MoneyFlowDefaults::ROLE);

            return $signer->fresh();
        });
    }
}
