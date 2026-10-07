<?php

declare(strict_types=1);

namespace App\Modules\Approval\Services;

use App\Core\Contracts\ProvisionsCompany;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * বিক্রয়ের প্রতিটা ছাড়ে মালিকের সই — মালিকের নিয়ম, ১ অক্টোবর ২০২৬:
 * *"bill e kono char maliker onumoti chara dite parbe na"*।
 *
 * ── ⭐ কী বসে, প্রতিটা কোম্পানিতে ────────────────────────────────────
 * `sales.discount` ছক: চালু, **কোনো সীমা নেই** (এক টাকাও), আর **একটাই ধাপ** — ঐ কোম্পানির super_admin রোল (মালিক)।
 * ⛔ আগের ধাপ (যেমন লাইভ ADI-র Manager) উঠে যায় — মালিকের সিদ্ধান্ত, ২ অক্টোবর ২০২৬: *শুধু মালিকের সই*, সব
 * কোম্পানিতে। আগে কী ছিল (সীমা, চালু কিনা, ধাপ) ফেরত আসে (`before`), মাইগ্রেশন সেটা লগে লেখে।
 * ⛔ মালিক নিজে ছাড় দিলেও সই লাগে — super_admin-এর জন্য কোনো ছাড় নেই (একই সিদ্ধান্ত)। অন্য কোনো ছক ছোঁয়া হয় না।
 *
 * ── ⓘ কী "ছাড়" ──────────────────────────────────────────────────────
 * সারির হাতে-দেওয়া ছাড়, বিলের মাথার ছাড়, আর ৳০.৫০-এর বেশি রাউন্ডিং ([[SalesInvoiceService::assertDiscountApproved()]])।
 * ⛔ স্কিম/অফারের ছাড় আর ফ্রি মাল নয় — ওগুলো নিজের ছকে সই পেয়েই চালু হয়।
 *
 * ── ⓘ কখন চলে ───────────────────────────────────────────────────────
 * নতুন কোম্পানিতে খোলার সময় ([[CompanyProvisioner]], `provisions`), আর পুরনোগুলোয় একবার মাইগ্রেশনে। বারবার
 * চালানো নিরাপদ। ⛔ super_admin রোল না পেলে (বা দুইটা পেলে) কিছুই বদলায় না — ভুল রোলে বসানো ধাপে কেউ সই
 * দিতে পারতেন না, আর প্রতিটা ছাড়ের বিল চিরকাল ঝুলত।
 */
final class OwnerSignsDiscounts implements ProvisionsCompany
{
    public const MODULE = 'sales';

    public const ACTION = 'discount';

    /** @return array{company: string, done: bool, problem: string|null} */
    public function ensure(Company $company): array
    {
        return CompanyContext::forCompany(
            (int) $company->id,
            fn () => DB::transaction(fn () => $this->ensureHere($company)),
        );
    }

    /**
     * নতুন কোম্পানি — [[CompanyProvisioner]]। ⚠️ রোলগুলো তৈরি হয় পরে ([[PermissionSyncer::sync()]]), তাই না পেলে আগে
     * সেটা ([[MoneyFlowDefaults::provisionCompany()]]-এর একই ফাঁদ)। ⛔ তবু না পেলে কোম্পানি খোলা থামে — মালিকের
     * সই ছাড়া ছাড়ের ছক নিয়ে কোম্পানি চালু হতে পারে না।
     */
    public function provisionCompany(): void
    {
        $company = Company::query()->whereKey(CompanyContext::id())->first();

        if ($company === null) {
            return;
        }

        $report = $this->ensure($company);

        if (! $report['done']) {
            app(PermissionSyncer::class)->sync('web');
            $report = $this->ensure($company);
        }

        if (! $report['done']) {
            throw new \RuntimeException('Owner discount signature could not be set for company '.$company->code.': '.$report['problem'].'.');
        }
    }

    /**
     * @return array{company: string, done: bool, problem: string|null, before?: list<array{threshold: string|null, active: bool, steps: list<string>}>}
     */
    private function ensureHere(Company $company): array
    {
        $owners = Role::query()
            ->where('company_id', $company->id)
            ->where('guard_name', 'web')
            ->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)
            ->limit(2)
            ->get();

        if ($owners->count() !== 1) {
            return ['company' => (string) $company->code, 'done' => false,
                'problem' => $owners->isEmpty() ? 'no super_admin role' : 'two super_admin roles'];
        }

        $owner = $owners->first();

        $flows = ApprovalFlow::query()
            ->where('company_id', $company->id)
            ->where('module', self::MODULE)
            ->where('action', self::ACTION)
            ->lockForUpdate()
            ->get();

        $flows = $flows->isNotEmpty() ? $flows : collect([ApprovalFlow::query()->create([
            'company_id' => $company->id,
            'module' => self::MODULE,
            'action' => self::ACTION,
            'document_type' => '',
            'threshold_amount' => null,
            'is_active' => true,
        ])]);

        $before = [];

        // ⓘ একাধিক নথি-ধরনের ছক থাকলে প্রতিটায় একই নিয়ম
        foreach ($flows as $each) {
            $steps = ApprovalFlowStep::query()->where('approval_flow_id', $each->id)->orderBy('level')->get();

            $before[] = [
                'threshold' => $each->threshold_amount === null ? null : (string) $each->threshold_amount,
                'active' => (bool) $each->is_active,
                'steps' => $steps->map(fn (ApprovalFlowStep $s) => $s->level.':'.$s->approver_type.'#'.$s->approver_id)->values()->all(),
            ];

            $each->forceFill(['threshold_amount' => null, 'is_active' => true])->save();

            ApprovalFlowStep::query()->where('approval_flow_id', $each->id)->delete();
            ApprovalFlowStep::query()->create([
                'approval_flow_id' => $each->id,
                'level' => 1,
                'step_name' => (string) $owner->name,
                'approver_type' => ApprovalFlowStep::BY_ROLE,
                'approver_id' => (int) $owner->id,
            ]);
        }

        return ['company' => (string) $company->code, 'done' => true, 'problem' => null, 'before' => $before];
    }
}
