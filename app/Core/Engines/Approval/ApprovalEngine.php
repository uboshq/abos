<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\ApprovalFlow;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * অনুমোদন — প্ল্যান সেকশন ২.২, তৃতীয় engine।
 *
 * Phase 1-এই বানানো হচ্ছে, Phase 10-এ নয়। কারণ সিকিউরিটি চেকলিস্ট বলছে
 * edit/delete/reprint/discount-এ অনুমোদন লাগবে — মানে Sales ও Purchase
 * লেখার সময়েই hook দরকার। পরে বসাতে গেলে প্রতিটা কন্ট্রোলার আবার ছুঁতে হত।
 *
 * ইঞ্জিনটা কোনো ডকুমেন্টের কথা জানে না। যে মডিউল অনুমোদন চায় সে শুধু
 * ডকুমেন্টটা আর কাজটার নাম দেয়; কয় স্তর, কে অনুমোদন করবে, সীমা কত —
 * সবই কোম্পানির নিজের সাজানো ছক থেকে আসে।
 */
final class ApprovalEngine
{
    /**
     * অনুমোদন লাগবে কি না — লাগলে অনুরোধ তৈরি করে ফেরত দেয়, নাহলে null।
     *
     * null ফেরা মানে "এগিয়ে যাও", তাই কলিং কোড সরল থাকে:
     *   if ($engine->request(...) === null) { /* সরাসরি করে ফেলো *​/ }
     */
    public function request(
        Model $document,
        string $module,
        string $action,
        ?string $amount = null,
        ?array $payload = null,
        ?string $reason = null,
        ?int $userId = null,
    ): ?Approval {
        $flow = $this->flowFor($module, $action, $document);

        if ($flow === null || ! $flow->appliesTo($amount)) {
            return null;
        }

        if ($flow->steps()->count() === 0) {
            throw new RuntimeException(
                "Approval flow for {$module}.{$action} has no steps. "
                .'A flow with nobody in it would leave every document waiting forever.'
            );
        }

        $existing = Approval::query()
            ->where('approvable_type', $document::class)
            ->where('approvable_id', $document->getKey())
            ->where('action', $action)
            ->pending()
            ->first();

        // একই কাজের জন্য দুইটা অনুরোধ থাকলে অনুমোদনকারী দুইবার একই জিনিস
        // দেখে, আর একটা অনুমোদন করে অন্যটা ঝুলে থাকে।
        if ($existing !== null) {
            return $existing;
        }

        return Approval::create([
            'company_id' => CompanyContext::id(),
            'approvable_type' => $document::class,
            'approvable_id' => $document->getKey(),
            'module' => $module,
            'action' => $action,
            'amount' => $amount,
            'status' => Approval::PENDING,
            'current_level' => 1,
            'payload' => $payload,
            'requested_reason' => $reason,
            'requested_by' => $userId ?? auth()->id(),
            'requested_at' => now(),
        ]);
    }

    /**
     * এক স্তরের অনুমোদন। সব স্তর শেষ হলে অনুরোধটাই approved হয়।
     */
    public function approve(Approval $approval, User $user, ?string $remarks = null): Approval
    {
        $this->assertPending($approval);
        $this->assertCanDecide($approval, $user);

        return DB::transaction(function () use ($approval, $user, $remarks) {
            ApprovalDecision::create([
                'approval_id' => $approval->id,
                'level' => $approval->current_level,
                'user_id' => $user->id,
                'decision' => 'approved',
                'remarks' => $remarks,
                'decided_at' => now(),
            ]);

            $flow = $this->flowFor($approval->module, $approval->action, null);
            $steps = $flow?->steps ?? collect();

            $currentStep = $steps->firstWhere('level', $approval->current_level);

            // একই স্তরে দুইজন থাকলে এবং সবার সম্মতি লাগলে — বাকিরা না দিলে
            // স্তরটা এখনো শেষ হয়নি।
            if ($currentStep?->requires_all) {
                $required = $steps->where('level', $approval->current_level)->count();
                $given = $approval->decisions()
                    ->where('level', $approval->current_level)
                    ->where('decision', 'approved')
                    ->count();

                if ($given < $required) {
                    return $approval->fresh();
                }
            }

            $nextLevel = $steps->where('level', '>', $approval->current_level)->min('level');

            if ($nextLevel !== null) {
                $approval->update(['current_level' => $nextLevel]);

                return $approval->fresh();
            }

            $approval->update([
                'status' => Approval::APPROVED,
                'decided_at' => now(),
            ]);

            return $approval->fresh();
        });
    }

    /**
     * প্রত্যাখ্যান — এক স্তরের একটা "না"-ই যথেষ্ট।
     *
     * বাকি স্তরে পাঠানো হয় না, কারণ নিচের স্তর না চাইলে উপরের স্তরের
     * মতামত নেওয়ার কোনো অর্থ নেই — আর সেটা চাইলে অনুরোধ নতুন করে করতে হবে।
     */
    public function reject(Approval $approval, User $user, string $remarks): Approval
    {
        $this->assertPending($approval);
        $this->assertCanDecide($approval, $user);

        return DB::transaction(function () use ($approval, $user, $remarks) {
            ApprovalDecision::create([
                'approval_id' => $approval->id,
                'level' => $approval->current_level,
                'user_id' => $user->id,
                'decision' => 'rejected',
                'remarks' => $remarks,
                'decided_at' => now(),
            ]);

            $approval->update([
                'status' => Approval::REJECTED,
                'decided_at' => now(),
            ]);

            return $approval->fresh();
        });
    }

    /** অনুরোধকারী নিজে প্রত্যাহার করলে। */
    public function cancel(Approval $approval, User $user): Approval
    {
        $this->assertPending($approval);

        if ($approval->requested_by !== $user->id) {
            throw new RuntimeException('Only the person who asked for an approval can withdraw it.');
        }

        $approval->update(['status' => Approval::CANCELLED, 'decided_at' => now()]);

        return $approval->fresh();
    }

    /** এই ব্যবহারকারীর অপেক্ষমাণ তালিকা — Approval Centre-এর queue। */
    public function pendingFor(User $user): iterable
    {
        return Approval::query()
            ->pending()
            ->get()
            ->filter(fn (Approval $approval) => $this->canDecide($approval, $user))
            ->values();
    }

    public function canDecide(Approval $approval, User $user): bool
    {
        $flow = $this->flowFor($approval->module, $approval->action, null);

        if ($flow === null) {
            return false;
        }

        $step = $flow->steps->where('level', $approval->current_level);

        if ($step->isEmpty()) {
            return false;
        }

        // নিজের অনুরোধ নিজে অনুমোদন করা যায় না। এটা না থাকলে পুরো
        // অনুমোদন ব্যবস্থাটাই সাজানো — যে ছাড় চায় সে নিজেই দিয়ে দেয়।
        if ($approval->requested_by === $user->id) {
            return false;
        }

        $alreadyDecided = $approval->decisions()
            ->where('level', $approval->current_level)
            ->where('user_id', $user->id)
            ->exists();

        if ($alreadyDecided) {
            return false;
        }

        return $step->contains(fn ($s) => $s->allows($user));
    }

    private function flowFor(string $module, string $action, ?Model $document): ?ApprovalFlow
    {
        $documentType = $document !== null ? class_basename($document) : null;

        $query = ApprovalFlow::query()
            ->where('module', $module)
            ->where('action', $action)
            ->where('is_active', true);

        // ডকুমেন্ট-নির্দিষ্ট ছক আগে, না থাকলে মডিউল-ব্যাপী ছক
        if ($documentType !== null) {
            $specific = (clone $query)->where('document_type', $documentType)->first();

            if ($specific !== null) {
                return $specific;
            }
        }

        return $query->whereNull('document_type')->first();
    }

    private function assertPending(Approval $approval): void
    {
        if (! $approval->isPending()) {
            throw new RuntimeException(
                "Approval {$approval->id} is already {$approval->status}."
            );
        }
    }

    private function assertCanDecide(Approval $approval, User $user): void
    {
        if (! $this->canDecide($approval, $user)) {
            throw new RuntimeException(
                "User {$user->id} cannot decide approval {$approval->id} at level {$approval->current_level}."
            );
        }
    }
}
