<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Services;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
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

    private function assertCodeFree(string $code): void
    {
        if (Branch::query()->where('code', $code)->exists()) {
            throw ValidationException::withMessages([
                'code' => __('system_admin::message.branch_code_taken'),
            ]);
        }
    }
}
