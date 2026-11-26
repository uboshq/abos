<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Models\User;
use App\Modules\Mail\Models\MailAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * ⭐ কে কোন মেইলবক্স দেখতে পারেন — এক জায়গায় নিয়ম (মালিক, ২৫–২৬ নভেম্বর ২০২৬):
 *
 * - নিজের (`own`) আর ব্যক্তিগত (`personal`) — কেবল যার মেইলবক্স সে। ⛔ মালিক বা অন্য কেউ কারো নিজের মেইল খুলতে পারেন না।
 * - দলের (`shared`) — সদস্য তালিকায় যারা আছেন, সবাই (মালিকের উত্তর "ক")।
 * - ব্যক্তিগত মেইল কেবল অনুমোদিত (`active`) আর মেয়াদের ভিতরে হলে খোলে; অপেক্ষায় থাকলে তালিকায় দেখায়, খোলে না।
 * - `mail.manage` কোম্পানির মেইলবক্সের তালিকা আর সদস্য ঠিক করতে পারেন — ⛔ ভিতরের মেইল নয়।
 */
final class MailAccess
{
    /** ⓘ বাম পাশে যা দেখায় — নিজের, দলের, ব্যক্তিগত (অপেক্ষায় থাকাগুলোও, খোলা যায় না) */
    public function listed(User $user): Collection
    {
        return MailAccount::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $w) => $w->whereIn('kind', [MailAccount::OWN, MailAccount::PERSONAL])->where('user_id', $user->id))
                ->orWhere(fn (Builder $w) => $w->where('kind', MailAccount::SHARED)
                    ->whereHas('members', fn (Builder $m) => $m->where('users.id', $user->id))))
            ->whereNotIn('status', [MailAccount::REJECTED, MailAccount::OFF])
            ->orderByRaw("FIELD(kind, 'own', 'shared', 'personal')")
            ->orderBy('email')
            ->get();
    }

    /** ⛔ খোলা যায় কিনা — তালিকায় থাকা আর ব্যবহারযোগ্য দুটোই */
    public function canOpen(User $user, MailAccount $account): bool
    {
        if (! $account->isUsable()) {
            return false;
        }

        if ($account->kind === MailAccount::SHARED) {
            return $account->members()->where('users.id', $user->id)->exists();
        }

        return (int) $account->user_id === (int) $user->id;
    }

    public function canSendFrom(User $user, MailAccount $account): bool
    {
        if (! $this->canOpen($user, $account)) {
            return false;
        }

        if ($account->kind !== MailAccount::SHARED) {
            return true;
        }

        return (bool) $account->members()->where('users.id', $user->id)->first()?->pivot?->can_send;
    }

    /**
     * ⓘ ABOS-এর বারে মেইলের চিহ্ন — তিন ABOS রূপে, `mail.use` চাবিতে, আর অন্তত একটা চালু মেইলবক্স থাকলে।
     * ⓘ এক অনুরোধে একবারই হিসাব (বার প্রতিটা পাতায় আঁকা হয়)।
     */
    public static function showsOnBar(?User $user): bool
    {
        if ($user === null || ! $user->can('mail.use')) {
            return false;
        }

        $attrs = request()->attributes;

        if (! $attrs->has('mail.bar')) {
            $look = \App\Core\Support\Ui::clean(\App\Core\Support\LookRegistry::lookFor(\App\Core\Support\LookPreview::orChosen($user->ui)));
            $attrs->set('mail.bar', in_array($look, \App\Core\Support\Ui::FOLD_MENU_LOOKS, true)
                && app(self::class)->listed($user)->contains(fn (MailAccount $a) => $a->isUsable()));
        }

        return (bool) $attrs->get('mail.bar');
    }

    /** ⛔ খুলতে না পারলে থামে — নিয়ামক এটা ডাকে, তাই কোনো পথ নিয়ম এড়াতে পারে না */
    public function mustOpen(User $user, MailAccount $account): void
    {
        abort_unless($this->canOpen($user, $account), 403);
    }
}
