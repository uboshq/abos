<?php

declare(strict_types=1);

namespace App\Core\Engines\Approval;

use App\Core\Support\CompanyContext;
use App\Models\ApprovalDelegation;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * সই দেওয়ার ভার — কে কার হয়ে, কোন সময় পর্যন্ত।
 *
 * ── ⚠️ কেন এটা ইঞ্জিনের ভিতরে নয় ─────────────────────────────────────
 * ⓘ ইঞ্জিন জিজ্ঞেস করে *"ইনি কি সই দিতে পারেন?"* — একটাই প্রশ্ন।
 * ⛔ ভার দেওয়ার নিয়ম (তারিখ, মডিউল, কাজ, বাতিল) ওখানে ঢোকালে
 * `canDecide()` আরও লম্বা হত, আর ওটা ইতিমধ্যে এই রিপোর সবচেয়ে
 * ঘন পদ্ধতিগুলোর একটা।
 */
final class DelegationService
{
    /**
     * ⭐ ভার দেওয়া।
     *
     * @param  list<string>  $modules  খালি = সব মডিউল
     * @param  list<string>  $actions  খালি = ঐ মডিউলের সব কাজ
     */
    public function grant(
        User $from,
        User $to,
        string $startsOn,
        string $endsOn,
        array $modules = [],
        array $actions = [],
        ?string $reason = null,
    ): ApprovalDelegation {
        /*
         * ⛔ নিজেকে ভার দেওয়া যায় না।
         *
         * ⚠️ দেখতে অর্থহীন, কিন্তু ঠেকানো দরকার: নিজেকে দিলে
         * [[ApprovalEngine::canDecide()]]-এর *"অনুরোধকারী নিজে সই দিতে
         * পারেন না"* নিয়মটা ঘুরিয়ে এড়ানো যেত — আমি অনুরোধ করলাম, আর
         * নিজের হয়ে নিজেই সই দিলাম।
         */
        if ($from->id === $to->id) {
            throw ValidationException::withMessages([
                'to_user_id' => __('core.approval.delegate_not_self'),
            ]);
        }

        if ($endsOn < $startsOn) {
            throw ValidationException::withMessages([
                'ends_on' => __('core.approval.delegate_bad_dates'),
            ]);
        }

        return ApprovalDelegation::query()->create([
            'company_id' => CompanyContext::id(),
            'from_user_id' => $from->id,
            'to_user_id' => $to->id,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'modules' => $modules,
            'actions' => $actions,
            'reason' => $reason,
            'created_by' => auth()->id(),
        ]);
    }

    /** ⓘ মোছা নয় — কে কবে কার হয়ে সই দিয়েছিলেন, সেই প্রশ্নের উত্তর পরে লাগে। */
    public function revoke(ApprovalDelegation $delegation): ApprovalDelegation
    {
        $delegation->update(['revoked_at' => now()]);

        return $delegation->fresh();
    }

    /**
     * ⭐ এই মানুষটা আজ কার কার হয়ে সই দিতে পারেন — এই কাজটায়।
     *
     * @return list<int> যাঁদের হয়ে
     */
    public function actingFor(User $user, string $module, string $action): array
    {
        return ApprovalDelegation::query()
            ->active()
            ->where('to_user_id', $user->id)
            ->get()
            ->filter(fn (ApprovalDelegation $d) => $d->covers($module, $action))
            ->pluck('from_user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
