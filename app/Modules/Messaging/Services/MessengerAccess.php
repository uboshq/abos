<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Models\User;
use App\Modules\Messaging\Models\Member;
use App\Modules\Messaging\Models\Thread;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may message or call whom — owner's plan: "কে কাকে বার্তা/কল দিতে পারবে — রোল দিয়ে".
 *
 * ⭐ Three walls, in order:
 *   1. both people carry `messaging.use` in THIS company;
 *   2. both are active members of this company (company_user, is_active);
 *   3. the role allow-list (setting `messaging.who_may_talk`): `{ "<sender role>": ["<receiver role>", …] }`,
 *      `"*"` meaning anyone. ⓘ An empty or missing list = everyone in the company may talk to everyone (the plan's default).
 *      ⓘ A role missing from a non-empty list may START a conversation with nobody — but may still answer inside a
 *      thread someone else opened with them; the wall is on starting (new direct thread, call, adding to a group).
 *
 * ⛔ The allow-list never opens a thread to a non-member: reading and writing a thread always asks [[isMember]].
 */
final class MessengerAccess
{
    public const SETTING = 'messaging.who_may_talk';

    public function __construct(private readonly SettingsService $settings) {}

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
        if ((int) $from->id === (int) $to->id) {
            return false;
        }

        if (! $this->inCompany($to) || ! $this->inCompany($from)) {
            return false;
        }

        if (! $this->mayUse($from) || ! $to->can('messaging.use')) {
            return false;
        }

        $rules = $this->rules();

        if ($rules === []) {
            return true;
        }

        $targets = [];
        foreach ($this->roles($from) as $role) {
            foreach ($rules[$role] ?? [] as $allowed) {
                $targets[(string) $allowed] = true;
            }
        }

        if (isset($targets['*'])) {
            return true;
        }

        foreach ($this->roles($to) as $role) {
            if (isset($targets[$role])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The people `$from` may start talking to — the list behind "নতুন বার্তা", the online strip and the share sheet.
     *
     * @return Collection<int, User>
     */
    public function reachable(User $from, string $q = '', int $limit = 200): Collection
    {
        $ids = DB::table('company_user')
            ->where('company_id', CompanyContext::id())
            ->where('is_active', true)
            ->where('user_id', '!=', $from->id)
            ->pluck('user_id');

        $query = User::query()->whereIn('id', $ids)->where('is_active', true);

        if ($q !== '') {
            $query->where('name', 'like', '%'.addcslashes($q, '%_\\').'%');
        }

        return $query->orderBy('name')->limit($limit * 2)->get()
            ->filter(fn (User $u): bool => $this->mayTalk($from, $u))
            ->take($limit)
            ->values();
    }

    private function inCompany(User $user): bool
    {
        return DB::table('company_user')
            ->where('company_id', CompanyContext::id())
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->exists();
    }

    /** @return list<string> */
    private function roles(User $user): array
    {
        try {
            return array_values(array_map('strval', $user->getRoleNames()->all()));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, list<string>> */
    private function rules(): array
    {
        $raw = $this->settings->get(self::SETTING);

        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $role => $targets) {
            if (is_string($role) && is_array($targets)) {
                $out[$role] = array_values(array_filter(array_map('strval', $targets), fn (string $t): bool => $t !== ''));
            }
        }

        return $out;
    }
}
