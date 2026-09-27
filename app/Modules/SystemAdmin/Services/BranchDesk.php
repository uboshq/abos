<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Services;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\UserDataScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * শাখা খোলা, বদলানো আর নিষ্ক্রিয় করা — নিয়মগুলো এক জায়গায়।
 *
 * ── ⭐ কেন একটা সেবা ─────────────────────────────────────────────────
 * ⓘ ২৭ সেপ্টেম্বর মালিক "শাখা"-র আলাদা মেনু চাইলেন। শাখা এতদিন খোলা হত
 * কেবল কোম্পানির পাতা থেকে ([[CompanyController::storeBranch()]])। ⛔ নতুন
 * পাতায় নিয়মগুলো আবার লিখলে একদিন দুই দরজায় দুই নিয়ম হত — এক পাতায় কোড
 * বড় হাতের, আরেক পাতায় নয়; এক পাতায় প্রথম শাখা ডিফল্ট, আরেকটায় নয়।
 * ⭐ তাই দুই দরজাই এই সেবা ডাকে।
 *
 * ── ⚠️ কেন সব কাজ `CompanyContext::forCompany`-র ভিতরে ────────────────
 * ⓘ [[Branch]]-এ `BelongsToCompany`: `company_id` বসে চলতি প্রসঙ্গ থেকে,
 * আর প্রশ্নও চলতি কোম্পানিতে ছাঁকা হয়। ⛔ বাইরে থেকে ডাকলে শাখাটা **চলতি**
 * কোম্পানিতে বসত — বাছা কোম্পানিতে নয় — আর নকল কোডের যাচাই ভুল কোম্পানিতে হত।
 */
final class BranchDesk
{
    /** ⓘ দুই দরজার ফর্মের নিয়ম একটাই */
    public const RULES = [
        'code' => ['required', 'string', 'max:16', 'alpha_dash'],
        'name_en' => ['required', 'string', 'max:160'],
        'name_bn' => ['nullable', 'string', 'max:160'],
        'address_en' => ['nullable', 'string', 'max:500'],
        'phone' => ['nullable', 'string', 'max:32'],
    ];

    /**
     * ⭐ নতুন শাখা — বাছা কোম্পানিতে।
     *
     * @param  array<string, mixed>  $data
     */
    public function open(Company $company, array $data): Branch
    {
        return CompanyContext::forCompany($company->id, function () use ($data) {
            $code = strtoupper((string) $data['code']);

            /*
             * ⛔ একই কোম্পানিতে একই কোড দুইবার নয়।
             *
             * ⓘ আগে এখানে `abort(422)` ছিল — ফর্মের পাশে বার্তা নয়, একটা খালি
             * ভুলের পাতা। ⭐ এখন ঘরের পাশে বার্তা, আর লেখা ঘরগুলো ফিরে আসে।
             */
            $this->assertCodeFree($code);

            return Branch::create([
                ...$data,
                'code' => $code,

                // প্রথম শাখাটাই ডিফল্ট — নইলে নতুন লেনদেনে কোনটা বসবে তা নির্ধারিত থাকত না
                'is_default' => ! Branch::query()->where('is_default', true)->exists(),
                'is_active' => true,
            ]);
        });
    }

    /**
     * ⓘ নাম, ঠিকানা, ফোন — আর কোড, যদি নতুনটা ঐ কোম্পানিতে খালি থাকে।
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Branch $branch, array $data): Branch
    {
        return CompanyContext::forCompany((int) $branch->company_id, function () use ($branch, $data) {
            $code = strtoupper((string) $data['code']);

            if ($code !== $branch->code) {
                $this->assertCodeFree($code);
            }

            $branch->fill([...$data, 'code' => $code])->save();

            return $branch;
        });
    }

    /**
     * নিষ্ক্রিয় করা — মোছা নয়।
     *
     * ⛔ ডিফল্ট শাখা নিষ্ক্রিয় করা যায় না: ⓘ নতুন লেনদেন ওখানেই বসে, আর
     * বন্ধ শাখায় বসা লেনদেন পরের পর্দাগুলোতে হারিয়ে যেত। আগে আরেকটা শাখাকে
     * ডিফল্ট করতে হয়।
     */
    public function toggle(Branch $branch): Branch
    {
        if ($branch->is_active && $branch->is_default) {
            throw ValidationException::withMessages([
                'is_active' => __('system_admin::message.cannot_disable_default_branch'),
            ]);
        }

        $branch->is_active = ! $branch->is_active;
        $branch->save();

        return $branch;
    }

    /**
     * ⭐ মুছে ফেলা — কেবল যে শাখা কোথাও ব্যবহৃত নয় (মালিকের নিয়ম, ২৭ সেপ্টেম্বর ২০২৬)।
     *
     * ⛔ মোছা যায় না: ডিফল্ট শাখা; কোম্পানির শেষ শাখা; আর যে শাখা কোনো কাগজ,
     * খাতা, মজুদ বা ব্যবহারকারীর সাথে যুক্ত — তখন বার্তা বলে "নিষ্ক্রিয় করুন"।
     * ⓘ মোছা মানে soft delete ([[Branch]]-এ `SoftDeletes`) — সারিটা থাকে।
     */
    public function remove(Branch $branch): void
    {
        if ($branch->is_default) {
            throw ValidationException::withMessages([
                'branch' => __('system_admin::message.cannot_delete_default_branch'),
            ]);
        }

        $siblings = Branch::query()->withoutGlobalScopes()
            ->whereNull('deleted_at') // ⚠️ মুছে ফেলা শাখা গোনায় নয়
            ->where('company_id', $branch->company_id)
            ->count();

        if ($siblings <= 1) {
            throw ValidationException::withMessages([
                'branch' => __('system_admin::message.cannot_delete_last_branch'),
            ]);
        }

        $usedIn = $this->whereUsed($branch);

        if ($usedIn !== null) {
            throw ValidationException::withMessages([
                'branch' => __('system_admin::message.branch_in_use', ['where' => $usedIn]),
            ]);
        }

        $branch->delete();
    }

    /**
     * ⭐ শাখাটা কোথায় ব্যবহৃত — প্রথম যে টেবিলে পাওয়া যায় তার নাম, নয়তো `null`।
     *
     * ── ⚠️ কেন হাতে লেখা তালিকা নয় ──────────────────────────────────
     * ⓘ ডাটাবেজের প্রতিটা টেবিলের যে ঘরের নাম `branch_id`-এ শেষ হয়
     * (`branch_id`, `current_branch_id`, `default_branch_id`, `from_branch_id`…)
     * সেখানে খোঁজা হয়। ⛔ হাতে লেখা তালিকা একদিন নতুন মডিউলের টেবিল বাদ দিত,
     * আর ব্যবহৃত শাখা নীরবে মুছে যেত — তার কাগজগুলো অনাথ হয়ে।
     *
     * ⓘ ব্যবহারকারীর শাখা-সীমা (`user_data_scopes`, ধরন `branch`) আলাদা করে দেখা
     * হয়, কারণ সেখানে ঘরের নাম `scope_id`।
     */
    public function whereUsed(Branch $branch): ?string
    {
        foreach (Schema::getTables() as $table) {
            $name = (string) ($table['name'] ?? '');

            if ($name === '' || $name === 'branches') {
                continue;
            }

            $columns = Schema::getColumnListing($name);
            $refs = array_values(array_filter($columns, fn (string $c) => str_ends_with($c, 'branch_id')));

            if ($refs === []) {
                continue;
            }

            $hasCompany = in_array('company_id', $columns, true);

            /*
             * ⓘ কোম্পানির ছাঁকনি যেখানে ঘরটা আছে — শাখার id গোটা ডাটাবেজে অনন্য,
             * তবু এক কোম্পানির প্রশ্ন আরেক কোম্পানির সারি ছোঁয় না।
             */
            $used = DB::table($name)
                ->when($hasCompany, fn ($q) => $q->where('company_id', $branch->company_id))
                ->where(function ($q) use ($refs, $branch) {
                    foreach ($refs as $column) {
                        $q->orWhere($column, $branch->id);
                    }
                })
                ->exists();

            if ($used) {
                return $name;
            }
        }

        $scoped = UserDataScope::query()->withoutGlobalScopes()
            ->where('company_id', $branch->company_id)
            ->where('scope_type', UserDataScope::BRANCH)
            ->where('scope_id', $branch->id)
            ->exists();

        return $scoped ? 'user_data_scopes' : null;
    }

    private function assertCodeFree(string $code): void
    {
        if (Branch::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => __('system_admin::message.branch_code_taken'),
            ]);
        }
    }
}
