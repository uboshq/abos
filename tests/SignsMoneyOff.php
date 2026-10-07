<?php

declare(strict_types=1);

namespace Tests;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\Company;
use App\Models\User;
use App\Modules\Approval\Services\MoneyFlowDefaults;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * ⭐ টেস্টে টাকার কাগজে সই — আসল পথে, দ্বিতীয় একজন মানুষ দিয়ে।
 *
 * ── ⛔ কেন এটা লাগবে, অডিট §১.৩ ─────────────────────────────────────
 * দ্বিতীয় ধাপের পর ছক-হীন টাকার কাজে ইঞ্জিন
 * [[\App\Core\Engines\Approval\NoApprovalFlow]] ছুঁড়বে, আর বানানেওয়ালা
 * নিজের কাগজে সই দিতে পারবেন না — সুপার অ্যাডমিনও নন। ⚠️ তখন প্রায়
 * ১২৪টা টেস্ট-ফাইল, যারা ছক ছাড়াই টাকার কাগজ পোস্ট করে, লাল হবে।
 *
 * ⭐ সারানোর পথ এই trait: ছকগুলো **আসল সেবা** দিয়ে বসে
 * ([[MoneyFlowDefaults]] — deploy যা চালায় ঠিক সেটাই), আর সই দেন
 * **আলাদা একজন** Accountant, **আসল ইঞ্জিন** দিয়ে।
 *
 * ── ⛔ যা এখানে কখনো থাকবে না ────────────────────────────────────────
 * নিয়মটা বন্ধ করার কোনো সুইচ। ⚠️ টেস্টে বন্ধ করা যায় এমন নিয়ম লাইভে
 * একদিন বন্ধ থাকে — কনফিগের একটা ভুলে, আর কোনো টেস্ট লাল হয় না, কারণ
 * টেস্টগুলোই তো বন্ধ রেখে চলে।
 *
 * ── ⓘ নাম ─────────────────────────────────────────────────────────────
 * ⚠️ PHPUnit-এর `final` মেথডগুলোর নামে কোনো সহায়ক নয় (status, name,
 * size, result, groups, toString) — একটা মিললে গোটা ক্লাস লোডেই মরে।
 */
trait SignsMoneyOff
{
    /**
     * ⭐ এই কোম্পানিতে প্রতিটা টাকার কাজের ডিফল্ট ছক।
     *
     * ⓘ `Company::create()`-এ বানানো টেস্ট-কোম্পানিতে কোনো রোল থাকে না,
     * তাই Accountant রোলটা আগে বসানো হয় — ⚠️ এটা টেস্টের নিজের ডেটা,
     * সেবার আন্দাজ নয়: সেবা নিজে কখনো রোল বানায় না।
     *
     * @return array<string, mixed> [[MoneyFlowDefaults::ensure()]]-এর রিপোর্ট
     */
    protected function moneyFlowsFor(Company $company): array
    {
        $this->accountantRoleIn($company);

        $report = app(MoneyFlowDefaults::class)->ensure($company);

        $this->forgetTheEnginesFlows();

        return $report;
    }

    /** ⭐ ঐ কোম্পানির Accountant রোল — না থাকলে বসে (টেস্টের ডেটা)। */
    protected function accountantRoleIn(Company $company): Role
    {
        return CompanyContext::forCompany(
            (int) $company->id,
            fn () => Role::findOrCreate(MoneyFlowDefaults::ROLE, 'web'),
        );
    }

    /**
     * ⭐ সই দেওয়ার দ্বিতীয় মানুষ — এই কোম্পানির একজন Accountant।
     *
     * ⚠️ প্রতিবার নতুন মানুষ: বানানেওয়ালা আর সইকারী কখনো একজন হবেন না,
     * আর সেটাই তো পরীক্ষার বিষয়।
     */
    protected function secondSignerIn(Company $company, string $label = 'দ্বিতীয় সইকারী'): User
    {
        $role = $this->accountantRoleIn($company);

        $user = User::create([
            'name' => $label,
            'email' => 'signer-'.uniqid('', true).'@t.test',
            'password' => 'x',
        ]);

        $user->companies()->syncWithoutDetaching([$company->id]);

        CompanyContext::forCompany((int) $company->id, fn () => $user->assignRole($role));

        /*
         * ⚠️ ইঞ্জিন `$user->roles` পড়ে — আগের খালি সম্পর্কটা জমে থাকলে
         * নতুন রোলটা দেখা যেত না, আর "সই দিতে পারেন না" মিথ্যা হত।
         */
        $user->unsetRelation('roles');
        $this->forgetTheEnginesFlows();

        return $user;
    }

    /**
     * ⭐ এই কাগজের এই কাজের অপেক্ষমাণ অনুরোধ — দ্বিতীয় মানুষের সইয়ে।
     *
     * ⓘ প্রতিটা স্তরে একটা সই, যতক্ষণ না অনুরোধটা শেষ হয়। ⛔ কোনো স্তরে
     * এই মানুষ সই দিতে না পারলে ইঞ্জিন নিজেই ছুঁড়ে দেয় — চুপচাপ পাশ
     * কাটানো হয় না।
     */
    protected function signOffAsSecondPerson(Model $document, string $action, ?User $signer = null): Approval
    {
        $approval = Approval::query()
            ->where('approvable_type', $document::class)
            ->where('approvable_id', $document->getKey())
            ->where('action', $action)
            ->pending()
            ->orderByDesc('id')
            ->first();

        if ($approval === null) {
            $this->fail('No pending approval for '.class_basename($document).' #'.$document->getKey()
                ." ({$action}) — the money paper never asked for a signature.");
        }

        return $this->signPendingApproval($approval, $signer);
    }

    /** ⭐ একটা নির্দিষ্ট অনুরোধ — সব স্তর পার করে, দ্বিতীয় মানুষের সইয়ে। */
    protected function signPendingApproval(Approval $approval, ?User $signer = null): Approval
    {
        $signer ??= $this->secondSignerIn(Company::query()->findOrFail($approval->company_id));

        $engine = app(ApprovalEngine::class);

        /*
         * ⚠️ ছাদ — স্তর যতই হোক, অসীম লুপ নয়। ⓘ একই মানুষ একই অনুরোধের
         * দুই স্তরে সই দিতে পারেন কেবল যদি ছক তাঁকে দুই স্তরেই রাখে।
         */
        for ($round = 0; $round < 10 && $approval->isPending(); $round++) {
            $approval = CompanyContext::forCompany(
                (int) $approval->company_id,
                fn () => $engine->approve($approval, $signer, 'signed in test'),
            );
        }

        $this->assertSame(Approval::APPROVED, $approval->status,
            'The second signer went through every level and the request is still '.$approval->status.'.');

        return $approval;
    }

    /**
     * ইঞ্জিনের জমানো ছক ভুলে যাওয়া।
     *
     * ⓘ [[ApprovalEngine]] `scoped` আর ছকগুলো একবারই তোলে। ⚠️ টেস্টে
     * একটা "অনুরোধ" গোটা পদ্ধতি জুড়ে চলে, তাই ছক বসানোর আগে ইঞ্জিন
     * একবার ডাকা হয়ে থাকলে সে নতুন ছকগুলো দেখত না।
     */
    protected function forgetTheEnginesFlows(): void
    {
        $this->app->forgetInstance(ApprovalEngine::class);
    }
}
