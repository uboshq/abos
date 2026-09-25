<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Services\PermissionSyncer;
use App\Core\Services\RoleTemplateRegistry;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * ⭐ ভূমিকার ছাঁচে নতুন যোগ হওয়া চাবিগুলো চলমান ভূমিকায় বসায় — হাতে ডাকলে।
 *
 * ── ⛔ কেন এটা ডিপ্লয়ে আপনা-আপনি চলে না ──────────────────────────────
 * [[PermissionSyncer::applyRoleTemplates()]] ইচ্ছাকৃতভাবে সেই ভূমিকাগুলো
 * ছোঁয় না যেগুলো আগে থেকেই আছে, আর কারণটা ঐ ফাইলেই লেখা: *"হিসাবরক্ষক
 * বা বিক্রয়কর্মী নতুন মডিউলে কী পারবে সেটা ব্যবসার সিদ্ধান্ত, আর সেটা
 * নীরবে নিয়ে নেওয়ার চেয়ে খারাপ কিছু নেই।"*
 *
 * ⚠️ প্রতি ডিপ্লয়ে চলা একটা কমান্ড ঠিক ঐ সুরক্ষাটাই ভেঙে দিত: মানুষের
 * অনুমতি বাড়ত, অথচ কেউ সিদ্ধান্ত নেয়নি। ⛔ তাই এটা **সময়সূচিতে নেই,
 * ডিপ্লয়ের তালিকাতেও নেই** — কেবল মালিক যেদিন বলেন সেদিন।
 *
 * ── ⓘ ডিফল্টে কিছুই বদলায় না ────────────────────────────────────────
 * `--force` ছাড়া এটা কেবল **দেখায়** কোন ভূমিকায় কোন চাবিটা যোগ হত।
 * ⚠️ নজিরটা [[RolesInEveryCompany]]-এর, আর কারণটা একই: অনুমতি বাড়ানোর
 * আগে মানুষের চোখে পড়া উচিত ঠিক কী বাড়ছে।
 *
 * ── ⛔ কখনো কিছু কেড়ে নেয় না ────────────────────────────────────────
 * ⚠️ ছাঁচে নেই এমন চাবি ভূমিকায় থাকলে সেটা **থেকেই যায়**: কোনো
 * প্রতিষ্ঠান হাতে একটা চাবি দিয়ে থাকতে পারেন, আর সেটা কেড়ে নেওয়া মানে
 * তাঁর সিদ্ধান্ত মুছে ফেলা। ⓘ এটা সিঙ্ক নয়, কেবল যোগ।
 *
 * ⓘ মালিকের ভূমিকা বাদ — ওটা [[PermissionSyncer::keepOwnerComplete()]]
 * প্রতিবারই ভরে রাখে, আর সেটা ঠিক: তালাবন্ধ মালিকের ফেরার পথ থাকে না।
 */
class ApplyRoleTemplates extends Command
{
    protected $signature = 'abos:apply-role-templates
                            {--force : সত্যিই বসায়; নাহলে কেবল দেখায়}';

    protected $description = 'ভূমিকার ছাঁচে নতুন চাবিগুলো চলমান ভূমিকায় বসায় (হাতে ডাকার জন্য)';

    public function handle(RoleTemplateRegistry $templates): int
    {
        $apply = (bool) $this->option('force');
        $total = 0;

        foreach (Company::query()->orderBy('id')->get() as $company) {
            /*
             * ⓘ প্রতিটা কোম্পানির নিজের প্রসঙ্গে — ⛔ নাহলে ভূমিকার
             * কোম্পানি-স্কোপ প্রথম কোম্পানিরটাই দেখত, আর বাকিদের
             * ভূমিকা "নেই" ভেবে বাদ পড়ত। ⚠️ ঠিক এই ফাঁদটাই
             * [[PermissionSyncer]]-এ ৭ সেপ্টেম্বর ধরা পড়েছিল।
             */
            $added = CompanyContext::forCompany($company->id, function () use ($templates, $apply, $company) {
                $count = 0;

                foreach ($templates->all() as $roleName => $permissions) {
                    if ($roleName === PermissionSyncer::SUPER_ADMIN_ROLE) {
                        continue;
                    }

                    $role = Role::query()
                        ->where('name', $roleName)
                        ->where('company_id', CompanyContext::id())
                        ->first();

                    /*
                     * ⓘ ভূমিকাটা না থাকলে এই কমান্ডের কাজ নয় —
                     * [[PermissionSyncer]] নতুন ভূমিকা বসানোর সময়
                     * ছাঁচের সব চাবিই দেয়।
                     */
                    if ($role === null) {
                        continue;
                    }

                    $has = $role->permissions()->pluck('name')->all();
                    $missing = array_values(array_diff($permissions, $has));

                    if ($missing === []) {
                        continue;
                    }

                    /*
                     * ⚠️ কেবল যে চাবিগুলো সত্যিই ঘোষিত — ⛔ অঘোষিত একটা
                     * নাম দিলে Spatie ব্যতিক্রম ছুড়ত, আর গোটা
                     * কোম্পানিটা বাদ পড়ত।
                     */
                    $real = Permission::query()
                        ->whereIn('name', $missing)
                        ->pluck('name')
                        ->all();

                    if ($real === []) {
                        continue;
                    }

                    $this->line(sprintf(
                        '%s · %s: %s',
                        $company->code,
                        $roleName,
                        implode(', ', $real),
                    ));

                    if ($apply) {
                        $role->givePermissionTo($real);
                    }

                    $count += count($real);
                }

                return $count;
            });

            $total += $added;
        }

        if ($total === 0) {
            $this->info('প্রতিটা ভূমিকায় ছাঁচের সব চাবি আগে থেকেই আছে।');

            return self::SUCCESS;
        }

        $this->info($apply
            ? "মোট {$total}টা চাবি ভূমিকায় বসেছে।"
            : "উপরের {$total}টা চাবি বসত — সত্যিই বসাতে `--force` দিন।");

        return self::SUCCESS;
    }
}
