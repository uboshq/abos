<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Support\Actor;
use App\Core\Support\CompanyContext;
use App\Core\Support\DateFormat;
use App\Core\Support\RoleLabel;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ সময়-বাঁধা সদস্যপদ — "থেকে · পর্যন্ত" (মালিকের অনুমোদিত রোলের পাতার নমুনা, ২০ নভেম্বর ২০২৬ "ok")।
 *
 * ── ⓘ কীভাবে কাজ করে ─────────────────────────────────────────────────
 * রোলটা spatie-র `model_has_roles`-এই থাকে; `sys_role_terms` কেবল মেয়াদ বলে। তাই অনুমতির প্রতিটা যাচাই আগের মতো — মেয়াদ শেষ
 * হলে রোলের সারিটাই তুলে নেওয়া হয়, আর তখন থেকে `can()` নিজেই "না" বলে।
 *   · রোজ রাতে [[due()]] (`abos:role-terms`): শুরুর দিন এলে রোল বসে, শেষ দিন পেরোলে ওঠে, শেষের আগের দিন খবর যায়।
 *   · লগইনের মুহূর্তে [[expireFor()]]: রাতের কাজ কোনো কারণে না চললেও মেয়াদ পেরোনো রোলে কেউ ঢোকেন না।
 * ⓘ "পর্যন্ত" দিনটা নিজেও মেয়াদের ভেতরে — ২৫ নভেম্বর পর্যন্ত মানে ২৫ তারিখ সারাদিন চলে, ২৬-এ বন্ধ।
 * ⛔ মালিকের রোলে মেয়াদ বসে না — কোম্পানি মালিকহীন হত।
 */
final class RoleTerms
{
    public const TABLE = 'sys_role_terms';

    public const ENDING = 'system_admin.role_ending';

    private static ?bool $ready = null;

    public function __construct(
        private readonly AuditEngine $audit,
        private readonly NotificationService $notify,
        private readonly Ownership $ownership,
    ) {}

    public function available(): bool
    {
        return self::$ready ??= Schema::hasTable(self::TABLE);
    }

    /**
     * এই রোলের মেয়াদগুলো, মানুষ ধরে (শেষ হয়ে যাওয়াগুলোসহ)।
     *
     * @return array<int, object>
     */
    public function forRole(int $roleId): array
    {
        if (! $this->available()) {
            return [];
        }

        return DB::table(self::TABLE)->where('company_id', CompanyContext::id())->where('role_id', $roleId)
            ->get()->keyBy('user_id')->all();
    }

    /**
     * ⭐ একজনের এই রোলের মেয়াদ বসানো বা বদলানো।
     *
     * ⓘ শুরুর দিন ভবিষ্যতে হলে রোলটা এখন তুলে রাখা হয় ("আসবে"), সেদিন নিজেই বসে; নইলে রোল বসানো থাকে ("চালু")।
     * ⓘ দুইটাই খালি = স্থায়ী — সারিটা থাকে, তবে কোনো দিন কিছু ঘটায় না।
     */
    public function set(Role $role, User $user, ?string $from, ?string $until, ?string $note = null, ?Carbon $today = null): void
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        if ($role->name === PermissionSyncer::SUPER_ADMIN_ROLE) {
            throw ValidationException::withMessages(['valid_until' => __('system_admin::permission.term_owner_fixed')]);
        }

        if ($until !== null && Carbon::parse($until)->lt($today)) {
            throw ValidationException::withMessages(['valid_until' => __('system_admin::permission.term_until_past')]);
        }

        if ($from !== null && $until !== null && Carbon::parse($from)->gt(Carbon::parse($until))) {
            throw ValidationException::withMessages(['valid_until' => __('system_admin::permission.term_until_before_from')]);
        }

        $companyId = (int) CompanyContext::id();
        $pending = $from !== null && Carbon::parse($from)->gt($today);
        $existing = DB::table(self::TABLE)->where('company_id', $companyId)->where('user_id', $user->id)->where('role_id', $role->id)->first();

        $values = [
            'valid_from' => $from,
            'valid_until' => $until,
            'note' => $note,
            'state' => $pending ? 'pending' : 'active',
            'ended_at' => null,
            // ⓘ শেষ দিন বদলালে নতুন দিনের খবর আবার যাবে
            'warned_for' => $existing && $existing->valid_until === $until ? $existing->warned_for : null,
            'set_by' => Actor::userId(),
            'updated_at' => now(),
        ];

        DB::transaction(function () use ($existing, $values, $companyId, $user, $role, $pending): void {
            if ($existing) {
                DB::table(self::TABLE)->where('company_id', $companyId)->where('id', $existing->id)->update($values);
            } else {
                DB::table(self::TABLE)->insert($values + [
                    'public_id' => (string) Str::uuid7(),
                    'company_id' => $companyId,
                    'user_id' => $user->id,
                    'role_id' => $role->id,
                    'created_at' => now(),
                ]);
            }

            $pending ? $this->detach($companyId, $user->id, (int) $role->id) : $this->attach($companyId, $user->id, (int) $role->id);

            $this->audit->record($role, 'role_term_set', [
                'member' => [null, $user->name],
                'valid_from' => [$existing?->valid_from, $values['valid_from']],
                'valid_until' => [$existing?->valid_until, $values['valid_until']],
            ], $values['note']);
        });
    }

    /**
     * ⭐ রোজকার কাজ — চলতি কোম্পানিতে। বারবার চালানো নিরাপদ।
     *
     * @return array{started: int, ended: int, warned: int}
     */
    public function due(?Carbon $today = null): array
    {
        $done = ['started' => 0, 'ended' => 0, 'warned' => 0];

        if (! $this->available()) {
            return $done;
        }

        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $companyId = (int) CompanyContext::id();
        $rows = DB::table(self::TABLE)->where('company_id', $companyId)->whereIn('state', ['pending', 'active'])->get();

        foreach ($rows as $row) {
            $until = $row->valid_until !== null ? Carbon::parse($row->valid_until)->startOfDay() : null;
            $from = $row->valid_from !== null ? Carbon::parse($row->valid_from)->startOfDay() : null;

            if ($until !== null && $until->lt($today)) {
                $this->end($row);
                $done['ended']++;

                continue;
            }

            if ($row->state === 'pending' && ($from === null || $from->lte($today))) {
                $this->attach($companyId, (int) $row->user_id, (int) $row->role_id);
                DB::table(self::TABLE)->where('company_id', $companyId)->where('id', $row->id)->update(['state' => 'active', 'updated_at' => now()]);
                $this->recordOnRole((int) $row->role_id, 'role_term_started', (int) $row->user_id);
                $done['started']++;
            }

            // ⓘ শেষের আগের দিন (আর শেষের দিনও, আগের দিনের কাজ ফসকালে) — একই শেষ দিনে একবারই
            if ($until !== null && $until->lte($today->copy()->addDay()) && $row->warned_for !== $until->toDateString()
                && ($row->state === 'active' || ($from !== null && $from->lte($today)))) {
                $this->warn($row, $until);
                $done['warned']++;
            }
        }

        if ($done['started'] + $done['ended'] > 0) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $done;
    }

    /**
     * ⛔ লগইনের মুহূর্তে — এই মানুষের মেয়াদ পেরোনো রোল এখনই তোলা, সব কোম্পানিতে।
     */
    public function expireFor(User $user, ?Carbon $today = null): int
    {
        if (! $this->available()) {
            return 0;
        }

        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $rows = DB::table(self::TABLE)->where('user_id', $user->id)->whereIn('state', ['pending', 'active'])
            ->whereNotNull('valid_until')->where('valid_until', '<', $today->toDateString())
            ->get(['id', 'company_id', 'user_id', 'role_id', 'valid_until']);

        foreach ($rows as $row) {
            CompanyContext::forCompany((int) $row->company_id, fn () => $this->end($row));
        }

        if ($rows->isNotEmpty()) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return $rows->count();
    }

    private function end(object $row): void
    {
        $companyId = (int) $row->company_id;

        DB::transaction(function () use ($row, $companyId): void {
            $this->detach($companyId, (int) $row->user_id, (int) $row->role_id);
            DB::table(self::TABLE)->where('company_id', $companyId)->where('id', $row->id)
                ->update(['state' => 'ended', 'ended_at' => now(), 'updated_at' => now()]);
            $this->recordOnRole((int) $row->role_id, 'role_term_ended', (int) $row->user_id);
        });
    }

    private function warn(object $row, Carbon $until): void
    {
        $companyId = (int) $row->company_id;
        $role = Role::query()->find($row->role_id);
        $user = User::query()->withoutGlobalScopes()->find($row->user_id);

        if (! $role || ! $user) {
            return;
        }

        $title = __('system_admin::permission.term_ending_title', [
            'name' => $user->name,
            'role' => RoleLabel::for($role->name),
            'date' => DateFormat::format($until->toDateString()),
        ]);
        $body = __('system_admin::permission.term_ending_body');
        $url = Route::has('system_admin.role.edit') ? route('system_admin.role.edit', ['role' => $role->id, 'tab' => 'members']) : null;
        $key = 'role_term:'.$row->id.':'.$until->toDateString();

        // ⓘ ঐ মানুষটা নিজে — রোলের পাতার চাবি তাঁর হয়তো নেই, তাই লিংক নয়
        $this->notify->send($user, self::ENDING, $title, $body, null, evenToSelf: true, key: $key);

        foreach ($this->ownership->activeOwnersIn($companyId) as $owner) {
            if ((int) $owner->id !== (int) $user->id) {
                $this->notify->send($owner, self::ENDING, $title, $body, $url, evenToSelf: true, key: $key);
            }
        }

        DB::table(self::TABLE)->where('company_id', $companyId)->where('id', $row->id)->update(['warned_for' => $until->toDateString()]);
    }

    private function recordOnRole(int $roleId, string $action, int $userId): void
    {
        $role = Role::query()->find($roleId);

        if ($role) {
            $name = User::query()->withoutGlobalScopes()->whereKey($userId)->value('name');
            $this->audit->record($role, $action, ['member' => [null, (string) $name]]);
        }
    }

    private function attach(int $companyId, int $userId, int $roleId): void
    {
        $type = (new User)->getMorphClass();
        $held = DB::table('model_has_roles')->where('company_id', $companyId)
            ->where('role_id', $roleId)->where('model_type', $type)->where('model_id', $userId)->exists();

        if (! $held) {
            DB::table('model_has_roles')->insert(['company_id' => $companyId, 'role_id' => $roleId, 'model_type' => $type, 'model_id' => $userId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function detach(int $companyId, int $userId, int $roleId): void
    {
        DB::table('model_has_roles')->where('company_id', $companyId)
            ->where('role_id', $roleId)->where('model_type', (new User)->getMorphClass())->where('model_id', $userId)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
