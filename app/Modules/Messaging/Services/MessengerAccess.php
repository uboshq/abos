<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Models\User;
use App\Modules\Messaging\Models\Member;
use App\Modules\Messaging\Models\Thread;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may message or call whom.
 *
 * ⭐ Everyone of the group, SR to owner, with everyone — owner, 24 Nov 2026: "পুরো গ্রুপের যে কয়টা কোম্পানি থাকে সব কয়টা
 *   কোম্পানির যত এমপ্লয়ি আছে সবার সাথে সবাই কথা বলতে পারবে, এটার মধ্যে কোনো রেস্ট্রিকশান নেই এবং থাকবেও না".
 *   So the only questions are: are both people active accounts, and active in some company of the group (company_user)?
 *   ⓘ The role allow-list and the per-person key are gone; `messaging.use` always opens for an active account
 *     ([[AppServiceProvider]] gate). ⓘ "The group" = every company on this installation — the owner's own businesses; a
 *     rented ABOS runs on its own installation, so the group never spans two customers.
 *   ⓘ Customers are not users (they sign in to the customer portal), so they are never in this list. The owner's rule
 *     for them — a request the customer accepts first — belongs to the portal chat, when it is built.
 *
 * ⛔ The wall that stays is the person: reading or writing a thread always asks [[isMember]]; a call only its two people.
 */
final class MessengerAccess
{
    /** @var array<int, list<int>> per request: the companies where a person is active */
    private array $companies = [];

    public function mayUse(User $user): bool
    {
        return $user->can('messaging.use');
    }

    public function isMember(Thread $thread, User $user): bool
    {
        return Member::query()->where('thread_id', $thread->id)->where('user_id', $user->id)->exists();
    }

    public function member(Thread $thread, User $user): ?Member
    {
        return Member::query()->where('thread_id', $thread->id)->where('user_id', $user->id)->first();
    }

    /** May `$from` start talking to `$to` (new direct thread, call, add to a group)? */
    public function mayTalk(User $from, User $to): bool
    {
        return (int) $from->id !== (int) $to->id
            && (bool) $from->is_active && (bool) $to->is_active
            && $this->companiesOf($from) !== [] && $this->companiesOf($to) !== [];
    }

    /**
     * The people `$from` may start talking to — the list behind "নতুন বার্তা", the online strip and the share sheet:
     * everyone active in any company of the group, found by name or by mobile (owner, 23 Nov 2026).
     *
     * @return Collection<int, User>
     */
    public function reachable(User $from, string $q = '', int $limit = 200): Collection
    {
        $query = User::query()
            ->whereHas('companies', fn ($c) => $c->where('company_user.is_active', true))
            ->whereKeyNot($from->id)
            ->where('is_active', true);

        if ($q !== '') {
            $like = '%'.addcslashes($q, '%_\\').'%';
            $digits = (string) preg_replace('/\D+/', '', $q);
            $query->where(fn ($w) => $w->where('name', 'like', $like)
                ->when(strlen($digits) >= 3, fn ($m) => $m->orWhere('mobile', 'like', '%'.$digits.'%')));
        }

        return $query->orderBy('name')->limit($limit)->get()->values();
    }

    /** @return list<int> the companies where this person is active */
    public function companiesOf(User $user): array
    {
        $uid = (int) $user->id;

        return $this->companies[$uid] ??= DB::table('company_user')->where('user_id', $uid)->where('is_active', true)
            ->orderBy('company_id')->pluck('company_id')->map(fn ($id) => (int) $id)->all();
    }
}
