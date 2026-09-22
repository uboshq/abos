<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * যাঁদের ভূমিকা এক কোম্পানিতে বসেছিল, বাকিগুলোতে তাঁদের পর্দা ফাঁকা।
 *
 * ── ⛔ কী ঘটেছিল ─────────────────────────────────────────────────────
 * অনুমতির ব্যবস্থায় `teams` চালু, আর দলটা **কোম্পানি**। তাই পুরনো কোডে
 * একবারের `syncRoles()` সারিটা লিখত কেবল **প্রশাসক তখন যে কোম্পানিতে
 * বসে ছিলেন** তার নামে — ফর্মে কয়টা কোম্পানি টিক দেওয়া হলো তাতে কিছু
 * যায়-আসত না।
 *
 * ⚠️ পর্দায় লেখা ছিল *"দুই কোম্পানিতে একই অধিকার থাকবে"*, আর কথাটা
 * সত্যি ছিল না। ⓘ কোডটা `08e96392`-তে সারানো হয়েছে — কিন্তু **পুরনো
 * সারিগুলো নিজে থেকে সরে না**, আর যত মানুষ ততবার ফর্ম খুলে সেভ করতে
 * হয়। লাইভে একজন মালিকের নিজের কোম্পানিতেই ফাঁকা মেনু দেখেছেন।
 *
 * ── ⚠️ কেন এটা কেবল **যোগ করে**, কখনো কাড়ে না ───────────────────────
 * ⛔ "সব কোম্পানিতে একই ভূমিকা" নিয়মটা মেনে ভূমিকাগুলোর **মিলন** বসিয়ে
 * দেওয়া যেত। কিন্তু কেউ যদি DEM-এ প্রশাসক আর TCL-এ কেবল দর্শক হন,
 * মিলন তাঁকে TCL-এও প্রশাসক বানিয়ে দিত — **লাইভ ডেটায় নীরবে ক্ষমতা
 * বাড়ানো**, আর কেউ সেটা টিকও দেয়নি।
 *
 * ⭐ তাই নিয়মটা সরু রাখা হয়েছে: কেবল সেই কোম্পানিগুলো ভরা হয়, যেখানে
 * মানুষটার **একটাও ভূমিকা নেই**। ⓘ ওটাই ঠিক ঐ উপসর্গ — ফাঁকা পর্দা —
 * আর যেখানে ভূমিকা আগে থেকেই আছে সেখানে হাত পড়ে না।
 *
 * ── ⓘ কোনটা বসবে, সেটা ঠিক হয় কীভাবে ───────────────────────────────
 * মানুষটা অন্য যে কোম্পানিগুলোতে আছেন, সেখানকার ভূমিকার নামগুলো। ⚠️ একের
 * বেশি রকম হলে কমান্ডটা **ঐ মানুষটাকে ছেড়ে দেয়** আর নাম ধরে বলে —
 * কারণ তখন কোনটা তাঁর "আসল" ভূমিকা সেটা কমান্ডের জানার কথা নয়, আর
 * আন্দাজ করা মানে আবার সেই নীরব ক্ষমতা বদল।
 */
class RolesInEveryCompany extends Command
{
    protected $signature = 'abos:roles-in-every-company
                            {--force : সত্যিই বসায়; নাহলে কেবল দেখায়}
                            {--user= : কেবল এই ইমেইলের জন্য}';

    protected $description = 'যে কোম্পানিতে কারো একটাও ভূমিকা নেই, সেখানে তাঁর ভূমিকাগুলো বসায়';

    public function handle(): int
    {
        $apply = (bool) $this->option('force');

        $users = User::query()
            ->when($this->option('user'), fn ($q, $email) => $q->where('email', $email))
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->warn('একজনও ব্যবহারকারী মিলল না।');

            return self::SUCCESS;
        }

        $filled = 0;
        $skipped = [];
        $was = getPermissionsTeamId();

        try {
            foreach ($users as $user) {
                $filled += $this->fill($user, $apply, $skipped);
            }
        } finally {
            setPermissionsTeamId($was);
        }

        foreach ($skipped as $line) {
            $this->warn($line);
        }

        $this->line('');
        $this->info($apply
            ? "বসানো হলো: {$filled} টা কোম্পানি-ভূমিকা।"
            : "বসত: {$filled} টা কোম্পানি-ভূমিকা। সত্যিই বসাতে --force দিন।");

        if ($apply && $filled > 0) {
            // ⚠️ ক্যাশ চব্বিশ ঘণ্টা জমে — না মুছলে পর্দা পুরনো উত্তরই দিত।
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $skipped
     */
    private function fill(User $user, bool $apply, array &$skipped): int
    {
        /*
         * ⓘ `model_has_roles` সরাসরি পড়া হয়, `$user->roles` নয়।
         *
         * ⚠️ সম্পর্কটা **চলতি দলের** সারিগুলোই ফেরায়, তাই ওটা দিয়ে
         * "অন্য কোম্পানিতে কী আছে" প্রশ্নের উত্তর পাওয়াই যেত না — ঠিক
         * যে অন্ধত্বটা বাগটাকে এতদিন ঢেকে রেখেছিল।
         */
        $held = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->pluck('roles.name', 'model_has_roles.company_id');

        if ($held->isEmpty()) {
            return 0;
        }

        $names = $this->namesFor($user);

        if ($names === null) {
            $skipped[] = "ছেড়ে দেওয়া হলো — {$user->email}: কোম্পানিভেদে ভূমিকা আলাদা "
                .'('.$held->unique()->implode(', ').')। কোনটা আসল তা কমান্ড জানে না।';

            return 0;
        }

        $companies = $user->companies()->pluck('companies.id')->all();

        /*
         * ⓘ কেবল যেখানে একটাও ভূমিকা নেই।
         *
         * ⚠️ এটা **আজকের আসল বাধা নয়**, আর কথাটা লিখে রাখা দরকার:
         * উপরের [[namesFor()]] কোম্পানিভেদে ভূমিকা আলাদা দেখলেই ছেড়ে
         * দেয়, তাই এখানে পৌঁছানো মানে সব কোম্পানিতে ভূমিকা **এক** —
         * আর তখন ভরা কোম্পানিতে আবার একই জিনিস বসালেও কিছু বদলাত না।
         *
         * ⛔ মেপে দেখা (২২ সেপ্টেম্বর ২০২৬): এই লাইনটা ইচ্ছা করে সরিয়ে
         * দিলে পরীক্ষাগুলো **সবুজই থাকে**। ⭐ তবু লাইনটা থাকল — কেউ
         * কাল `namesFor()`-এর কড়া নিয়মটা আলগা করলে এটাই শেষ বাধা।
         */
        $empty = array_values(array_diff($companies, $held->keys()->all()));

        $done = 0;

        foreach ($empty as $companyId) {
            $this->line(sprintf(
                '%s → কোম্পানি %d-এ বসবে: %s',
                $user->email,
                $companyId,
                implode(', ', $names)
            ));

            if (! $apply) {
                $done++;

                continue;
            }

            setPermissionsTeamId((int) $companyId);

            $this->makeSureTheseRolesExistHere((int) $companyId, $names);

            $user->unsetRelation('roles')->syncRoles($names);

            $done++;
        }

        return $done;
    }

    /**
     * ⓘ সব কোম্পানিতে এক রকম হলে সেই নামগুলো, নাহলে `null`।
     *
     * ⚠️ উপরের `pluck` একই কোম্পানির একাধিক ভূমিকা চাপা দেয় (শেষটাই
     * থাকে), তাই এখানে পূর্ণ তালিকাটা আবার তোলা হয় — নাহলে দুই ভূমিকার
     * মানুষ এক ভূমিকা নিয়ে অন্য কোম্পানিতে বসতেন।
     *
     * @return list<string>|null
     */
    private function namesFor(User $user): ?array
    {
        $full = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->get(['model_has_roles.company_id', 'roles.name']);

        $sets = [];

        foreach ($full as $row) {
            $sets[$row->company_id][] = $row->name;
        }

        $shapes = [];

        foreach ($sets as $names) {
            sort($names);
            $shapes[implode('|', $names)] = $names;
        }

        return count($shapes) === 1 ? array_values(reset($shapes)) : null;
    }

    /**
     * ⓘ এই কোম্পানিতে ভূমিকাটা না থাকলে বসিয়ে দেওয়া — অনুমতিসহ।
     *
     * ⚠️ ছাড়া `syncRoles()` সরাসরি ছোঁড়ে: *"There is no role named …"*।
     * ⓘ নিয়মটা [[UserController::makeSureTheseRolesExistHere()]]-এর হুবহু।
     *
     * @param  list<string>  $names
     */
    private function makeSureTheseRolesExistHere(int $companyId, array $names): void
    {
        foreach ($names as $name) {
            $here = Role::query()
                ->where('company_id', $companyId)
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->first();

            if ($here !== null) {
                continue;
            }

            $template = Role::query()
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->first();

            $made = Role::create([
                'name' => $name,
                'guard_name' => 'web',
                'company_id' => $companyId,
            ]);

            if ($template === null) {
                continue;
            }

            /*
             * ⓘ অনুমতিগুলোও সাথে যায় — নাহলে নামটা থাকত, ক্ষমতা থাকত না,
             * আর মেনু আগের মতোই ফাঁকা দেখাত।
             */
            $made->syncPermissions(
                Permission::query()
                    ->whereIn('name', $template->permissions()->pluck('name'))
                    ->where('guard_name', 'web')
                    ->get()
            );
        }
    }
}
