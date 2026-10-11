<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Core\Contracts\ApprovalBundles;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ⭐ শেষ সই পড়লে কাগজ নিজেই পোস্ট — মালিক, ১১ অক্টোবর ২০২৬: *"Approval er pore Vautcher inv, & challan & others post korte hoy
 * tookhon khuje ber korle somoy lage"* → ক: নিজে পোস্ট, আটকালে আলাদা করে দেখা।
 *
 * ⓘ মালিকের ২৭ সেপ্টেম্বরের নিয়ম — শেষ সইয়ের পর কাউকে আবার "পাকা করুন" চাপতে হবে না — হিসাবের কাগজ, ফেরত আর কাউন্টারের বিক্রিতে
 * আগেই ছিল; ভাউচার আর ক্রয়-বিলে ছিল না (লাইভে RCV-0052: সই ১৩:৩৫, হাতে পোস্ট ১৩:৪১)।
 *
 * ── কীভাবে ──────────────────────────────────────────────────────────────
 * - যিনি লিখেছেন তাঁর নামে ([[HeldCounterSaleFinisher]]-এর একই ছাঁচ) — খাতায় "কে পোস্ট করল" আগের মতোই লেখকের।
 *   লেখক নেই বা বন্ধ হলে সইকারীর নামে।
 * - কাগজের নিজের শাখায় ([[CompanyContext::inBranch()]]) — সইকারীর হেডারে অন্য শাখা থাকলে কাগজটা "নেই" হত।
 * - ⛔ আটকালে (বাকির সীমা, মাস বন্ধ, মজুদ কম…) কাগজ খসড়াই থাকে, সইও থাকে; লেখক আর সইকারী কারণসহ খবর পান, আর কাগজটা
 *   "সই হয়েছে, পোস্ট হয়নি" তালিকায় ওঠে ([[SignedButNotPosted]]) — কিছুই চুপচাপ পড়ে থাকে না।
 * - ⓘ কখনো সইয়ের সিদ্ধান্তকে ভাঙে না: এখানকার কোনো ভুল সইদাতার পাতায় ৫০০ হয়ে ফেরে না।
 */
final class SignedPaperFinisher
{
    public const NOTICE = 'approval.signed_paper_stuck';

    public function __construct(private readonly NotificationService $notices) {}

    /**
     * @param  Closure(Model): mixed  $post  কাগজটা পোস্ট করার আসল কাজ — তাজা কপি পায়
     * @return string|null  আটকালে কারণ; পোস্ট হলে বা এখনো সইয়ের অপেক্ষায় থাকলে null
     */
    public function finish(Model $paper, ?User $signer, string $url, Closure $post): ?string
    {
        $maker = $this->makerOf($paper) ?? $signer;
        $before = Auth::user();
        $branchId = $paper->getAttribute('branch_id');

        try {
            if ($maker !== null) {
                Auth::setUser($maker);
            }

            CompanyContext::inBranch($branchId === null ? null : (int) $branchId, fn () => $post($paper));

            return null;
        } catch (HeldForApproval) {
            // ⓘ আরেক ধাপের সই এখনো বাকি — কিছু আটকায়নি
            return null;
        } catch (ValidationException $e) {
            $reason = (string) (collect($e->errors())->flatten()->first() ?? $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $reason = $e->getMessage();
        } finally {
            if ($before !== null) {
                Auth::setUser($before);
            } else {
                Auth::forgetUser();
            }
        }

        $this->stuck($paper, $signer, $url, $reason);

        return $reason;
    }

    /**
     * ⛔ একসাথে সই হওয়া দলের কাগজ এখানে নয় — কাউন্টারের এক বিক্রির চালান, বিল আর প্রতিটা জমা ([[ApprovalBundles]])।
     *
     * ⓘ দলের প্রতিটা সই আলাদা পড়ে; একটা জমার সই পড়ামাত্র সেটা পোস্ট করলে বাকি সই (ছাড়, বাকির সীমা) বাকি থাকতেই অর্ধেক টাকা
     * খাতায় উঠত ([[TheSaleWaitedForAButtonAfterItsLastSignatureTest]])। দলটা শেষ করে তার নিজের শেষকারী, সব সই পড়লে একসাথে
     * ([[HeldCounterSaleFinisher]])।
     */
    public function bundled(int $approvalId): bool
    {
        if (! app()->bound(ApprovalBundles::class)) {
            return false;
        }

        $approval = Approval::query()->find($approvalId);

        return $approval !== null && app(ApprovalBundles::class)->bundleFor($approval) !== null;
    }

    private function makerOf(Model $paper): ?User
    {
        $maker = User::query()->find((int) $paper->getAttribute('created_by'));

        if ($maker === null || ! $maker->is_active || ! $maker->canAccessCompany((int) $paper->getAttribute('company_id'))) {
            return null;
        }

        return $maker;
    }

    private function stuck(Model $paper, ?User $signer, string $url, string $reason): void
    {
        $no = (string) ($paper->getAttribute('document_no') ?? '#'.$paper->getKey());

        Log::warning('A signed paper was not posted automatically', [
            'company_id' => (int) $paper->getAttribute('company_id'),
            'paper' => $paper::class.'#'.$paper->getKey(),
            'no' => $no,
            'reason' => $reason,
        ]);

        $people = collect([User::query()->find((int) $paper->getAttribute('created_by')), $signer])
            ->filter()->unique(fn (User $u) => $u->id);

        foreach ($people as $person) {
            try {
                $this->notices->send(
                    $person,
                    self::NOTICE,
                    __('core.approval.signed_paper_stuck_title', ['no' => $no]),
                    __('core.approval.signed_paper_stuck_body', ['reason' => $reason]),
                    $url,
                    // ⓘ সইকারী নিজেই সই দিলেন, কিন্তু থেমে যাওয়াটা দেখেননি — তাই নিজের কাজ হলেও খবর
                    evenToSelf: true,
                    key: 'signed-paper:'.class_basename($paper).':'.$paper->getKey().':stuck:'.now()->toDateString(),
                    about: $paper,
                );
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
