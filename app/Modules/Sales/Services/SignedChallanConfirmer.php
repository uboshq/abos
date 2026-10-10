<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Engines\Audit\AuditEngine;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Sales\Models\DeliveryChallan;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ⭐ শেষ সইয়ের পরে অফিসের চালান নিজে পাকা — মালিকের সিদ্ধান্ত, ২৭ সেপ্টেম্বর ২০২৬:
 * *"সব সহ শেষ হলে নিজে থেকেই পোস্ট হবে"* (বিক্রয়ের হাঁটায় ধরা, ২৯ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⓘ কী ঘটত ─────────────────────────────────────────────────────────────
 * কাউন্টারে এটা ছিল ([[HeldCounterSaleFinisher]]), অফিসের চালানে ছিল না: সই পড়ার পরেও চালান
 * খসড়া, আর বানানেওয়ালাকে ফিরে এসে আবার "নিশ্চিত" চাপতে হত — ততক্ষণ গুদাম মাল তুলতে পারত না।
 *
 * ── ⭐ কাজটা নতুন নয়, হুবহু সেই বোতামের ─────────────────────────────────
 * পাকা করে [[DeliveryChallanService::confirm()]], দরজার মতোই আগে [[TransportRule]] জিজ্ঞেস
 * করে ([[DeliveryChallanController::confirm()]])। ⚠️ চলে **বানানেওয়ালার নামে** — সইকারীর নামে
 * চালালে তাঁর শাখা-গুদামের সীমা আর `confirmed_by` গুলিয়ে যেত (কাউন্টারের একই কারণ)। অডিটের সারি
 * সইকারীর নামে, কারণ সিদ্ধান্তটা তাঁর।
 *
 * ── ⛔ কখন পাকা হয় না ──────────────────────────────────────────────────
 * চালানটা কাউন্টারের বিক্রির (ওটা [[HeldCounterSaleFinisher]]-এর), খসড়া নয়, বা বানানেওয়ালা
 * নিষ্ক্রিয়/কোম্পানির বাইরে; আরেকটা সই এখনো বাকি ([[HeldForApproval]] — অপেক্ষা, ব্যর্থতা নয়);
 * বা দরজার নিজের নিয়ম থামায় (বাকির দেয়াল, মজুদ, পরিবহন)। ⚠️ শেষেরটায় সই টিকে থাকে
 * ([[ApprovalDecided]] লেনদেন পাকা হওয়ার পরে ছোড়া), চালান খসড়া থাকে, আর **বানানেওয়ালা** খবর
 * পান কারণসহ — চালান তাঁর টেবিলেই ফিরেছে।
 */
final class SignedChallanConfirmer
{
    /** ⓘ অডিটের কাজের নাম — ঘরটা ২৪ অক্ষরের (`audit_trails.action`) */
    public const AUDIT_ACTION = 'auto_confirmed_signed';

    /** ⓘ খবরের ধরন — [[NotificationKinds]]-এ নিবন্ধিত, যাতে কেউ চাইলে বন্ধ করতে পারেন */
    public const NOTICE = 'sales.signed_challan_stuck';

    public function __construct(
        private readonly DeliveryChallanService $challans,
        private readonly AuditEngine $audit,
        private readonly NotificationService $notices,
    ) {}

    /** এই অনুরোধটা কি অফিসের কোনো খসড়া চালানের — কাউন্টারের বিক্রি বাদে। */
    public function challanFor(Approval $approval): ?DeliveryChallan
    {
        if ((string) $approval->approvable_type !== DeliveryChallan::class
            || (string) $approval->module !== 'sales'
            || (string) $approval->action !== 'challan') {
            return null;
        }

        // ⓘ কাউন্টারের বিক্রির চালানের সাথে বিল আগেই থাকে — ওটা শেষ করে [[HeldCounterSaleFinisher]]
        if (app(HeldCounterSaleFinisher::class)->invoiceFor($approval) !== null) {
            return null;
        }

        $challan = DeliveryChallan::acrossBranches()->find((int) $approval->approvable_id);

        return $challan !== null && $challan->status === 'draft' ? $challan : null;
    }

    /**
     * পাকা করো — বানানেওয়ালার নামে। ⚠️ কোনো ব্যতিক্রম বাইরে যায় না: ডাকে সইয়ের পরের শ্রোতা, আর
     * চালানের একটা বাধা সইকারীর সিদ্ধান্ত ফেরাতে পারে না।
     */
    public function confirm(DeliveryChallan $challan, ?User $signer = null): bool
    {
        $maker = User::query()->find((int) $challan->created_by);

        if ($maker === null || ! $maker->is_active || ! $maker->canAccessCompany((int) $challan->company_id)) {
            $this->tell($maker, $challan, __('sales::auto_finish.maker_gone'));

            return false;
        }

        $before = Auth::user();
        $stuck = null;

        try {
            Auth::setUser($maker);

            // ⓘ মাল কীভাবে যাবে — প্রশ্নটা এখন ছাপার দরজায়, নিশ্চিতে নয় (মালিকের অনুমোদিত বদল, ১ অক্টোবর ২০২৬; [[RequireTransportBeforePrint]])

            // ⛔ চালানের নিজের শাখায় — হেডারে অন্য শাখা থাকলে আদেশটা "নেই" হত আর আদেশের ধরা মাল ছাড়া হত না (পুনঃঅডিট ৯ অক্টোবর ২০২৬, বিক্রয় ১)
            CompanyContext::inBranch($challan->branch_id === null ? null : (int) $challan->branch_id,
                fn () => $this->challans->confirm($challan->fresh()));
        } catch (HeldForApproval) {
            // ⓘ আরেকটা সই বাকি — ঐ সই পড়লে এই পথই আবার চলবে
            return false;
        } catch (ValidationException $e) {
            $stuck = (string) (collect($e->errors())->flatten()->first() ?? $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $stuck = $e->getMessage();
        } finally {
            $this->restore($before);
        }

        /*
         * ⚠️ খবরটা লগইন ফেরত দেওয়ার **পরে** — [[NotificationService::send()]] নিজের কাজের খবর নিজের
         * কাছে পাঠায় না, আর তখনো বানানেওয়ালার নামে চললে খবরটা নীরবে হারাত (দাবিতে ধরা পড়েছে)।
         */
        if ($stuck !== null) {
            $this->tell($maker, $challan, $stuck);

            return false;
        }

        try {
            $this->audit->recordAction($challan->fresh() ?? $challan, self::AUDIT_ACTION, __('sales::auto_finish.challan_audit', [
                'signer' => $signer?->name ?? '—',
                'maker' => $maker->name,
            ]));
        } catch (Throwable $e) {
            // ⓘ চালান ততক্ষণে পাকা — অডিট লেখা ব্যর্থ হলেও সেটা সইকারীর কাছে ভুল হয়ে ফেরে না
            report($e);
        }

        return true;
    }

    /** ⚠️ অডিটের সারিটা সইকারীর নামে — সিদ্ধান্তটা তাঁর; `recordAction` লগইন থেকে নাম নেয়। */
    private function restore(?Authenticatable $before): void
    {
        if ($before !== null) {
            Auth::setUser($before);

            return;
        }

        Auth::forgetUser();
    }

    private function tell(?User $maker, DeliveryChallan $challan, string $reason): void
    {
        if ($maker === null) {
            return;
        }

        $this->notices->send(
            $maker,
            self::NOTICE,
            __('sales::auto_finish.challan_stuck_title', ['no' => $challan->document_no]),
            __('sales::auto_finish.challan_stuck_body', ['reason' => $reason]),
            route('sales.challan.show', $challan),
        );
    }
}
