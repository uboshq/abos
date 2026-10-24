<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Services\Ownership;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * ⭐ মালিককে মালিকহীন কোম্পানিতে ফেরানো — সদস্যপদ আর সেই কোম্পানির নিজের super_admin (fe, ২৪ অক্টোবর ২০২৬)।
 *
 * ── ⛔ কেন লাগল ──────────────────────────────────────────────────────────
 * ১০ অক্টোবর মালিক ADI-তে দাঁড়িয়ে নিজের ব্যবহারকারী-ফর্ম save করলেন, AVA-র টিক বাদ পড়ল (লাইভ audit #384878): AVA-র
 * সদস্যপদ আর super_admin মুছে গেল। ⚠️ অ্যাপের ভেতর থেকে ফেরানোর পথ নেই — ফর্মে AVA দেখাতে AVA-র super_admin লাগে, আর
 * মালিকানা হস্তান্তরে একজন বর্তমান মালিক লাগে; AVA-তে কেউই নেই।
 *
 * ── ⛔ শর্ত (fe) ─────────────────────────────────────────────────────────
 *   · কেবল `abos.owner_emails`-এর মানুষ ([[Ownership::isGroupOwner()]]);
 *   · কেবল সেই কোম্পানিতে, যেখানে আজ কেউ super_admin নন — মালিক থাকলে পথ হস্তান্তর, এটা নয়;
 *   · কোম্পানির নিজের super_admin রোল (roles.company_id = কোম্পানি) — না থাকলে থামে, রোল বানায় না;
 *   · ডিফল্ট শাখা = কোম্পানির প্রধান শাখা (`is_default`), অনুমানে নয় — না থাকলে থামে;
 *   · audit `owner_restored` সেই কোম্পানির খাতায়; permission cache সাফ।
 *   · দ্বিতীয়বার চালালে কিছু বদলায় না — "আগে থেকেই আছে"।
 *
 * ⛔ লাইভে আগে `--dry`, আর মালিকের হ্যাঁ। deploy বা migration এটা কখনো ডাকে না।
 */
class RestoreOwner extends Command
{
    protected $signature = 'abos:restore-owner
        {--company= : কোম্পানির সংকেত (যেমন AVA)}
        {--user= : মালিকের user id}
        {--dry : কিছুই লেখে না — কী বসত তা বলে}';

    protected $description = 'মালিকহীন কোম্পানিতে মালিককে সদস্যপদ আর সেই কোম্পানির super_admin ফিরিয়ে দেয় (owner_emails-এর মানুষ কেবল)';

    public function handle(Ownership $ownership, AuditEngine $audit): int
    {
        $dry = (bool) $this->option('dry');
        $company = Company::query()->where('code', trim((string) $this->option('company')))->first();
        $user = User::query()->whereKey((int) $this->option('user'))->first();

        if ($company === null || $user === null) {
            $this->error('⛔ কোম্পানি বা মানুষ পাওয়া গেল না — --company=<সংকেত> --user=<id> দিন।');

            return self::FAILURE;
        }

        if (! $ownership->isGroupOwner($user)) {
            $this->error("⛔ {$user->id} {$user->name} মালিকের তালিকায় (abos.owner_emails) নেই — এই কমান্ড কেবল মালিকের জন্য।");

            return self::FAILURE;
        }

        $role = Role::query()->where('company_id', $company->id)
            ->where('name', PermissionSyncer::SUPER_ADMIN_ROLE)->where('guard_name', 'web')->first();

        if ($role === null) {
            $this->error("⛔ {$company->code}-এ super_admin রোলই নেই — রোল বানানো হয় না; আগে abos:sync-permissions।");

            return self::FAILURE;
        }

        $holders = DB::table('model_has_roles')->where('role_id', $role->id)->where('company_id', $company->id)
            ->where('model_type', $user->getMorphClass())->pluck('model_id')->map(fn ($id) => (int) $id)->all();
        $member = DB::table('company_user')->where('company_id', $company->id)->where('user_id', $user->id)
            ->first(['is_active', 'default_branch_id']);

        if (in_array((int) $user->id, $holders, true) && $member !== null && (int) $member->is_active === 1) {
            $this->info("{$company->code}: {$user->name} আগে থেকেই সক্রিয় সদস্য আর super_admin — কিছু বদলানো হয়নি।");

            return self::SUCCESS;
        }

        $others = array_values(array_diff($holders, [(int) $user->id]));

        if ($others !== []) {
            $this->error("⛔ {$company->code}-এ আজ super_admin আছেন (user ".implode(', ', $others).') — এটা মালিকহীন কোম্পানির জন্য; পথ মালিকানা হস্তান্তর।');

            return self::FAILURE;
        }

        // ⓘ প্রধান শাখা — `is_default`, অনুমানে "প্রথম চালু" নয় ([[Company::defaultBranch()]]-এর ফিরতি পথ এখানে নেওয়া হয় না)
        $main = Branch::query()->withoutGlobalScopes()->where('company_id', $company->id)->where('is_default', true)->first();

        if ($main === null) {
            $this->error("⛔ {$company->code}-এর প্রধান শাখা (is_default) নেই — শাখা অনুমানে বসানো হয় না।");

            return self::FAILURE;
        }

        $membership = $member === null ? 'নতুন সদস্যপদ' : ((int) $member->is_active === 1 ? 'সদস্যপদ আছে' : 'বন্ধ সদস্যপদ চালু');
        $branchId = $member?->default_branch_id ?? $main->id;
        $branchCode = (string) (DB::table('branches')->where('company_id', $company->id)->where('id', $branchId)->value('code') ?? $branchId);
        $super = in_array((int) $user->id, $holders, true) ? 'super_admin আছে' : 'super_admin দেওয়া';

        $this->line(sprintf('%s · %d %s · %s · ডিফল্ট শাখা %s · %s%s', $company->code, $user->id, $user->name,
            $membership, $branchCode, $super, $dry ? ' · (বসত — কিছুই লেখা হয়নি)' : ''));

        if ($dry) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($company, $user, $role, $member, $branchId, $membership, $branchCode, $super, $audit): void {
            if ($member === null) {
                $user->companies()->attach($company->id, ['is_active' => true, 'default_branch_id' => $branchId]);
            } elseif ((int) $member->is_active !== 1 || $member->default_branch_id === null) {
                $user->companies()->updateExistingPivot($company->id, ['is_active' => true, 'default_branch_id' => $branchId]);
            }

            CompanyContext::forCompany((int) $company->id, function () use ($user, $role, $membership, $branchCode, $super, $audit): void {
                if (! $user->unsetRelation('roles')->hasRole($role)) {
                    $user->assignRole($role);
                }

                $audit->recordAction($user, 'owner_restored', "{$membership} · ডিফল্ট শাখা {$branchCode} · {$super} (abos:restore-owner)");
            });
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info("{$company->code}: {$user->name} ফিরলেন — সক্রিয় সদস্য আর super_admin।");

        return self::SUCCESS;
    }
}
