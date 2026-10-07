<?php

declare(strict_types=1);

namespace App\Modules\Hr\Support;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\ApprovalFlow;
use App\Models\ApprovalFlowStep;
use App\Modules\Hr\Models\ExpenseClaim;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * ⛔ খরচের দাবি আর অগ্রিম কখনো সই ছাড়া নয় — সমন্বয়কের আদেশ (অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬; মালিকের নিয়ম "স্বয়ংক্রিয় অনুমোদন
 * বাদ", ২৬ সেপ্টেম্বর ২০২৬; [[AClaimNeverPassesWithoutASignatureTest]])।
 *
 * ⓘ সইয়ের ইঞ্জিন ছক না পেলে "কিছু আটকায় না" বলে — আগে তখন দাবি সাথে সাথে অনুমোদিত হত: অগ্রিম থেকে খরচের জাবেদা পাকা, আর
 * নগদের খসড়া ক্যাশিয়ারের কাছে, কোনো মানুষের সই ছাড়া। এখন কোম্পানিতে কাজটার কোনো ছক না থাকলে এখানে একটা বসে — এক স্তর,
 * কোম্পানির মালিক (super_admin রোল), কোনো সীমা নেই। যিনি চাইলেন তিনি নিজে সই দেন না (ইঞ্জিনের নিয়ম)।
 *
 * ⚠️ কোম্পানির নিজের ছক — যেকোনো নথি-ধরনের, চালু বা বন্ধ — থাকলে ছোঁয়া হয় না: সেটা কোম্পানির সিদ্ধান্ত, আর আগের মতোই খাটে।
 * মালিকের রোল না পেলে (বা দুইটা) দাবি থামে — সই ছাড়া পার হওয়ার চেয়ে থামা ভালো।
 */
final class ClaimSigner
{
    public const MODULE = 'hr';

    /** @var list<string> */
    public const ACTIONS = [ExpenseClaim::ACTION_EXPENSE, ExpenseClaim::ACTION_ADVANCE];

    /**
     * কাজটার ছক আছে কি না দেখা, না থাকলে মালিকের সইয়ের ছক বসানো — এই কোম্পানিতে।
     *
     * @return bool নতুন ছক বসল কি না — বসলে ইঞ্জিনের এই অনুরোধের ছক-স্মৃতি পুরনো, তাই কপিটা ভুলিয়ে দেওয়া হয়; ডাকা জায়গা তখন
     *              নতুন কপি নেয়
     */
    public function ensure(string $action): bool
    {
        $made = DB::transaction(function () use ($action): bool {
            $exists = ApprovalFlow::query()
                ->where('company_id', CompanyContext::id())
                ->where('module', self::MODULE)
                ->where('action', $action)
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                return false;
            }

            $owners = Role::query()
                ->where('company_id', CompanyContext::id())
                ->where('guard_name', 'web')
                ->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)
                ->limit(2)
                ->get();

            if ($owners->count() !== 1) {
                throw ValidationException::withMessages([
                    'kind' => __('hr::claim.no_owner_to_sign'),
                ]);
            }

            $flow = ApprovalFlow::query()->create([
                'company_id' => CompanyContext::id(),
                'module' => self::MODULE,
                'action' => $action,
                'document_type' => '',
                'threshold_amount' => null,
                'is_active' => true,
            ]);

            ApprovalFlowStep::query()->create([
                'approval_flow_id' => $flow->id,
                'level' => 1,
                'step_name' => (string) $owners->first()->name,
                'approver_type' => ApprovalFlowStep::BY_ROLE,
                'approver_id' => (int) $owners->first()->id,
            ]);

            return true;
        });

        if ($made) {
            app()->forgetInstance(ApprovalEngine::class);
        }

        return $made;
    }
}
