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
 *   1. both people carry `messaging.use` — in any company of the group where they are active;
 *   2. both are active members of some company of the group (company_user, is_active) — ⭐ the whole group, not the open
 *      company (owner, 23–24 Nov 2026: "যে কোনো কোম্পানি থেকে যে কোনো ব্রাঞ্চে গ্রুপ অফ কোম্পানিতে" — a call or a chat must
 *      never depend on which company happens to be open). ⓘ This installation is the owner's own group; a rented ABOS runs
 *      on its own installation, so "the group" never spans two customers;
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

        if (! $this->usesMessaging($from) || ! $this->usesMessaging($to)) {
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
        // ⭐ everyone active in any company of the group — found by name or by mobile (owner, 23 Nov 2026)
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

        return $query->orderBy('name')->limit($limit * 2)->get()
            ->filter(fn (User $u): bool => $this->mayTalk($from, $u))
            ->take($limit)
            ->values();
    }

    /** @var array<int, bool> per request: does this person use the messenger anywhere in the group */
    private array $uses = [];

    /** @var array<int, list<string>> per request: the person's role names across the group */
    private array $roleNames = [];

    /** @return list<int> the companies where this person is active */
    public function companiesOf(User $user): array
    {
        return DB::table('company_user')->where('user_id', $user->id)->where('is_active', true)
            ->orderBy('company_id')->pluck('company_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * ⓘ `messaging.use` in ANY company where the person is active — roles are per company (spatie teams), so the open
     *   company alone would hide a colleague of a sister company. Each company is asked with its own team context.
     */
    private function usesMessaging(User $user): bool
    {
        $uid = (int) $user->id;

        if (! array_key_exists($uid, $this->uses)) {
            $active = $this->companiesOf($user);
            $here = in_array((int) CompanyContext::id(), $active, true) && $user->can('messaging.use');

            // ⓘ the open company asks the gate (overrides included); a sister company is read from the grants in one query
            //   — switching the team context per person would empty spatie's cache on every switch
            $this->uses[$uid] = $here || array_intersect($this->grantedCompanies($uid), $active) !== [];
        }

        return $this->uses[$uid];
    }

    /** @var array<int, list<int>>|null user id → companies where `messaging.use` is granted (role, direct, or super admin) */
    private ?array $grants = null;

    /** @return list<int> */
    private function grantedCompanies(int $uid): array
    {
        if ($this->grants === null) {
            $this->grants = [];
            $type = (new User)->getMorphClass();
            $perm = DB::table('permissions')->where('name', 'messaging.use')->where('guard_name', 'web')->value('id');

            $rows = collect();

            if ($perm !== null) {
                $rows = $rows
                    ->merge(DB::table('model_has_permissions')->where('model_type', $type)->where('permission_id', $perm)
                        ->get(['model_id', 'company_id']))
                    ->merge(DB::table('model_has_roles')->where('model_type', $type)
                        ->join('role_has_permissions', 'role_has_permissions.role_id', '=', 'model_has_roles.role_id')
                        ->where('role_has_permissions.permission_id', $perm)
                        ->get(['model_has_roles.model_id', 'model_has_roles.company_id']));
            }

            $rows = $rows->merge(DB::table('model_has_roles')->where('model_type', $type)
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', \App\Core\Services\PermissionSyncer::SUPER_ADMIN_ROLE)
                ->get(['model_has_roles.model_id', 'model_has_roles.company_id']));

            foreach ($rows as $row) {
                $this->grants[(int) $row->model_id][] = (int) $row->company_id;
            }
        }

        return $this->grants[$uid] ?? [];
    }

    /** @return list<string> role names across every company where the person is active */
    private function roles(User $user): array
    {
        $uid = (int) $user->id;

        if (! array_key_exists($uid, $this->roleNames)) {
            $this->roleNames[$uid] = DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_type', $user->getMorphClass())
                ->where('model_has_roles.model_id', $uid)
                ->whereIn('model_has_roles.company_id', $this->companiesOf($user) ?: [0])
                ->distinct()->pluck('roles.name')->map(fn ($n) => (string) $n)->values()->all();
        }

        return $this->roleNames[$uid];
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
