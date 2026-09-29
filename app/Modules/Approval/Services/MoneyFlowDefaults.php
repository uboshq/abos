<?php

declare(strict_types=1);

namespace App\Modules\Approval\Services;

use App\Core\Contracts\ProvisionsCompany;
use App\Core\Module\ModuleRegistry;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * ⭐ প্রতিটা টাকার কাজে একটা ছক — হিসাবরক্ষকের সই (নকশা §৬)।
 *
 * ── ⛔ কেন এটা লাগল, অডিট §১.৩ ──────────────────────────────────────
 * মালিকের সিদ্ধান্ত (২৭ সেপ্টেম্বর ২০২৬): POS-এর নগদ বিক্রি ছাড়া
 * **প্রতিটা** টাকার পোস্টিংয়ে সই লাগবে। ⚠️ ইঞ্জিন তখন ছক-হীন টাকার
 * কাজে [[\App\Core\Engines\Approval\NoApprovalFlow]] ছুঁড়বে — তাই কোনো
 * কোম্পানিতে ছক না থাকলে সেখানে একটা টাকাও খাতায় উঠবে না।
 *
 * ⭐ এই সেবা সেই দিনের আগে প্রতিটা কোম্পানিতে ছকগুলো বসিয়ে রাখে।
 *
 * ── ⓘ কী বসে ─────────────────────────────────────────────────────────
 * রেজিস্ট্রির প্রতিটা মডিউলের `moves_money`-র প্রতিটা কাজে একটা ছক:
 *   · স্তর ১ — `BY_ROLE`, ঐ কোম্পানির Accountant রোল
 *   · `document_type` খালি (সব ধরনে), কোনো সীমা নেই, কোনো শর্ত নেই
 *
 * ⛔ কাজের তালিকা হাতে লেখা নয় — রেজিস্ট্রি থেকে পড়া। ⓘ নতুন টাকার কাজ
 * module.php-তে বসলেই পরের deploy-এ তার ছকও বসে
 * ([[never-supply-the-name-yourself]])।
 *
 * ⚠️ "চালু মডিউল" মানে এখানে **রেজিস্ট্রিতে থাকা** মডিউল, কোম্পানির
 * `<code>.enabled` সুইচ নয়। ⓘ সুইচ বন্ধ মডিউলের ছক কোনো ক্ষতি করে না;
 * ⛔ কিন্তু বাদ দিলে সুইচ চালু করার দিনে ঐ মডিউলের প্রতিটা টাকার কাগজ
 * পরের deploy পর্যন্ত আটকে থাকত।
 *
 * ── ⛔ যা কখনো করা হয় না ──────────────────────────────────────────────
 * · **চলতি ছক ছোঁয়া হয় না** — কোনো ছক (যেকোনো নথি-ধরন, চালু বা বন্ধ)
 *   থাকলে কাজটা বাদ। ⓘ ছকটা কোম্পানির নিজের সিদ্ধান্ত; উল্টে দেওয়া
 *   মানে তাঁর কাজ নষ্ট করা। বন্ধ বা ধাপ-হীন ছক আলাদা করে রিপোর্টে যায়।
 * · **রোলের id আন্দাজ করা হয় না** — নাম ধরে, এই কোম্পানিতেই, বড়-ছোট
 *   হাতের তফাত না মেনে ([[DemoSeeder]] ছোট হাতে `accountant` বসায়)।
 *   ⚠️ না পেলে, বা দুইটা পেলে, কিছুই বসে না — আর রিপোর্ট সেটা বলে।
 * · **অন্য কোম্পানির রোল ধার করা হয় না** — spatie teams-এ রোল
 *   কোম্পানিভেদে, আর অন্য কোম্পানির রোলে বসানো ধাপে কেউ কোনোদিন সই
 *   দিতে পারতেন না (কাগজ চিরকাল ঝুলত)।
 *
 * ── ⓘ বারবার চালানো নিরাপদ ──────────────────────────────────────────
 * দ্বিতীয়বার চালালে সব কাজ "আছে" হয়ে বাদ পড়ে। ⚠️ একই কাজে দুইটা ছক
 * কখনো বসে না — [[ApprovalFlowService::create()]]-এর নিজের পাহারা, আর
 * তার নিচে `approval_flow_scope` unique index।
 */
final class MoneyFlowDefaults implements ProvisionsCompany
{
    /** ⓘ টেমপ্লেটের নাম — Approval-এর module.php-র `role_templates`-এ ঠিক এই বানান। */
    public const ROLE = 'Accountant';

    private const GUARD = 'web';

    public function __construct(
        private readonly ModuleRegistry $modules,
        private readonly ApprovalFlowService $flows,
    ) {}

    /**
     * ⭐ রেজিস্ট্রির প্রতিটা টাকার কাজ — "module.action" ধরে।
     *
     * @return list<array{module: string, action: string}>
     */
    public function moneyActions(): array
    {
        $out = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->movesMoney as $action) {
                $out[] = ['module' => $module->code, 'action' => $action];
            }
        }

        return $out;
    }

    /**
     * ⭐ এই কোম্পানিতে অনুপস্থিত টাকার ছকগুলো বসাও।
     *
     * ⓘ `missing_role` কেবল তখনই সত্য যখন **বসানোর মতো কিছু ছিল** অথচ
     * রোলটা পাওয়া গেল না। ⚠️ যে কোম্পানি রোলটার নাম বদলে নিজের ছক
     * সব কাজে বসিয়ে রেখেছে, তাকে প্রতিটা deploy-এ লাল দেখানো হত
     * মিথ্যা অভিযোগে।
     *
     * @return array{
     *     company: string,
     *     role_id: int|null,
     *     missing_role: bool,
     *     role_problem: string|null,
     *     created: list<string>,
     *     skipped: list<string>,
     *     inactive: list<string>,
     *     empty: list<string>,
     *     wanting: list<string>
     * }
     */
    public function ensure(Company $company): array
    {
        return CompanyContext::forCompany(
            (int) $company->id,
            fn () => DB::transaction(fn () => $this->ensureHere($company)),
        );
    }

    /**
     * নতুন কোম্পানি — [[CompanyProvisioner]] ডাকে (module.php-র `provisions`-এ
     * বসলে; প্রথম ধাপে বসানো হয়নি, নকশার সম্পাদনা-তালিকায় আছে)।
     *
     * ── ⚠️ ক্রমের ফাঁদ ────────────────────────────────────────────────
     * নতুন কোম্পানিতে Accountant রোল তৈরি হয় **পরে** —
     * [[CompanyProvisioner::grantAccess()]] → [[PermissionSyncer::sync()]]।
     * ⓘ তাই রোল না পেলে আগে `sync()` চালানো হয়; সেটা idempotent, আর
     * একটু পরেই `grantAccess()` আবার চালায়।
     *
     * ⛔ তবু না পেলে কোম্পানি খোলা আটকানো হয় না — সতর্কবার্তা লগে যায়,
     * আর `abos:sync-money-flows` পরের deploy-এ কোম্পানিটার নাম ধরে লাল হয়।
     */
    public function provisionCompany(): void
    {
        $company = Company::query()->whereKey(CompanyContext::id())->first();

        if ($company === null) {
            return;
        }

        if ($this->accountantRole((int) $company->id)['role'] === null) {
            app(PermissionSyncer::class)->sync(self::GUARD);
        }

        $report = $this->ensure($company);

        /*
         * ── ⛔ আগে এখানে একটা `Log::warning` ছিল ────────────────
         * ⓘ একটা লগ লাইন, আর পর্দায় কিছুই নয় — তাই কোম্পানিটা
         * তৈরি হয়ে যেত শূন্য ছক নিয়ে, আর কেউ জানত না।
         *
         * ⚠️ এটা শুধু নীরবতার প্রশ্ন নয়। সারাইয়ের পর এই পদ্ধতি
         * [[CompanyProvisioner]]-এর লেনদেনের ভিতর থেকে চলে। ⭐ চুপ করে
         * সরে গেলে কোম্পানিটা **অর্ধেক তৈরি** অবস্থায় বসে যেত;
         * ব্যতিক্রম ছুড়লে পুরো কোম্পানিটাই ফিরে যায় — আর অর্ধেক
         * তৈরি কোম্পানির চেয়ে না-তৈরি কোম্পানি অনেক ভালো।
         */
        /*
         * ── ⓘ আর এই শাখাটায় আজ পৌঁছানো যায় না, আর সেটা মেপে জানা ──────
         * ⚠️ উপরে ভূমিকা না পেলে `sync()` ডাকা হয়, আর ছাঁচ থেকে
         * হিসাবরক্ষক ফিরে আসে। আর "একই নামের দুইটা ভূমিকা" দশাটা Spatie
         * নিজেই আটকায় (`RoleAlreadyExists`, বড়-ছোট হাত নির্বিশেষে —
         * ২৯ সেপ্টেম্বর ২০২৬-এ মাপা)।
         *
         * ⭐ তবু রাখা হলো: collation বা ভূমিকার API বদলালে দশাটা ফিরে
         * আসতে পারে, আর তখন নীরব একটা লগ-লাইনের চেয়ে জোরে থামা ভালো।
         * ⛔ কিন্তু এর জন্য কোনো দাবি লেখা যায় না — লিখলে সেটা একটা নকল
         * দাবি হত, আর নকল দাবি আসল পাহারার চেয়েও খারাপ।
         */
        if ($report['missing_role']) {
            throw new RuntimeException(
                'No single Accountant role in company '.$company->code.' ('
                .($report['role_problem'] ?? 'none found').'), so '
                .count($report['wanting']).' money action(s) would have no approval flow. '
                .'Roles must be provisioned before the approval flows.'
            );
        }
    }

    /** @return array<string, mixed> */
    private function ensureHere(Company $company): array
    {
        $report = [
            'company' => (string) $company->code,
            'role_id' => null,
            'missing_role' => false,
            'role_problem' => null,
            'created' => [],
            'skipped' => [],
            'inactive' => [],
            'empty' => [],
            'wanting' => [],
        ];

        foreach ($this->moneyActions() as $pair) {
            $key = $pair['module'].'.'.$pair['action'];

            /*
             * ⚠️ যেকোনো ছক — নথি-ধরন যাই হোক, চালু বা বন্ধ।
             *
             * ⓘ কোম্পানির ছাঁকনি হাতে লেখা, যদিও [[BelongsToCompany]]
             * নিজেও বসায় — ⛔ কনসোলে প্রসঙ্গ ভুল থাকলে ভুল কোম্পানির ছক
             * "আছে" ধরে এই কোম্পানির কাজটা বাদ পড়ত, আর কেউ টের পেত না।
             */
            $existing = ApprovalFlow::query()
                ->where('company_id', $company->id)
                ->where('module', $pair['module'])
                ->where('action', $pair['action'])
                ->with('steps')
                ->get();

            if ($existing->isNotEmpty()) {
                $report['skipped'][] = $key;

                $active = $existing->filter(fn (ApprovalFlow $flow) => (bool) $flow->is_active);

                /*
                 * ⚠️ ছোঁয়া হয় না, কিন্তু নাম ধরে বলা হয়: বন্ধ ছক বা
                 * ধাপ-হীন ছক থাকলে দ্বিতীয় ধাপের পর ঐ কাজের প্রতিটা
                 * কাগজ আটকাবে — ⓘ জোরে, নীরবে নয়, তবু আগে জানা ভালো।
                 */
                if ($active->isEmpty()) {
                    $report['inactive'][] = $key;
                } elseif ($active->contains(fn (ApprovalFlow $flow) => $flow->steps->isEmpty())) {
                    $report['empty'][] = $key;
                }

                continue;
            }

            $report['wanting'][] = $key;
        }

        if ($report['wanting'] === []) {
            return $report;
        }

        $found = $this->accountantRole((int) $company->id);

        if ($found['role'] === null) {
            $report['missing_role'] = true;
            $report['role_problem'] = $found['problem'];

            return $report;
        }

        $role = $found['role'];
        $report['role_id'] = (int) $role->id;

        foreach ($report['wanting'] as $key) {
            [$module, $action] = explode('.', $key, 2);

            $this->flows->create(
                [
                    'module' => $module,
                    'action' => $action,
                    'document_type' => '',
                    'threshold_amount' => null,
                    'remarks' => $this->words(
                        'approval::message.money_flow_default',
                        'টাকার কাজ — প্রতিটা কাগজে বানানেওয়ালা ছাড়া আরেকজনের সই লাগে (স্বয়ংক্রিয়ভাবে বসানো)।',
                    ),
                    'is_active' => true,
                ],
                [[
                    'level' => 1,
                    'step_name' => (string) $role->name,
                    'approver_type' => ApprovalFlowStep::BY_ROLE,
                    'approver_id' => (int) $role->id,
                ]],
            );

            $report['created'][] = $key;
        }

        $report['wanting'] = [];

        return $report;
    }

    /**
     * ⛔ এই কোম্পানির Accountant — নাম ধরে, id আন্দাজ করে নয়।
     *
     * ⓘ `LOWER()` ইচ্ছাকৃত: MySQL-এর সাধারণ collation এমনিতেই বড়-ছোট
     * মেলায়, ⚠️ কিন্তু `_bin` collation-এ মেলায় না — আর তখন ডেমোর
     * `accountant` চুপচাপ "নেই" হত।
     *
     * ⚠️ দুইটা মিললে (কেবল `_bin`-এ সম্ভব) কোনোটাই নেওয়া হয় না — কোনটা
     * আসল সেটা যন্ত্র জানে না, আর ভুলটা বাছলে ভুল মানুষ সই দিতেন।
     *
     * @return array{role: Role|null, problem: string|null}
     */
    private function accountantRole(int $companyId): array
    {
        $matches = Role::query()
            ->where('company_id', $companyId)
            ->where('guard_name', self::GUARD)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(self::ROLE)])
            ->limit(2)
            ->get();

        return match ($matches->count()) {
            1 => ['role' => $matches->first(), 'problem' => null],
            0 => ['role' => null, 'problem' => 'missing'],
            default => ['role' => null, 'problem' => 'ambiguous'],
        };
    }

    /**
     * অনুবাদ থাকলে সেটা, নাহলে বাংলা বাক্যটা।
     *
     * ⓘ চাবিটা Approval-এর ভাষা-ফাইলে বসবে (সম্পাদনা-তালিকায় আছে); ⚠️
     * তার আগে কাঁচা চাবি ছকের মন্তব্যে জমা হলে পর্দায় সেটাই দেখাত।
     */
    private function words(string $key, string $fallback): string
    {
        $line = __($key);

        return is_string($line) && $line !== $key ? $line : $fallback;
    }
}
