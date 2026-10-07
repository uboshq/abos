<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Models\Account;
use App\Modules\Finance\Models\Institution;
use App\Modules\Finance\Models\InstitutionAccount;
use Illuminate\Validation\ValidationException;

/**
 * আর্থিক প্রতিষ্ঠান বসানো — আর একই নামে দ্বিতীয়বার নয়।
 *
 * ⓘ নকলের পাহারা দুই স্তরে: এখানে একটা বোঝার মতো বার্তা, আর ডাটাবেজে
 * `(company_id, name_key)`-এর ইউনিক চাবি — দুইজন একই মুহূর্তে একই নাম
 * বসালেও দ্বিতীয়টা থামে।
 */
final class InstitutionService
{
    /** @param  array<string, mixed>  $data */
    public function create(array $data): Institution
    {
        $this->assertNameIsFree((string) ($data['name_en'] ?? ''));

        return Institution::query()->create([
            ...$this->clean($data),
            'company_id' => CompanyContext::id(),
            'is_active' => true,
            'created_by' => auth()->id(),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(Institution $institution, array $data): Institution
    {
        $this->assertNameIsFree((string) ($data['name_en'] ?? ''), $institution->id);

        $institution->update($this->clean($data));

        return $institution->fresh();
    }

    public function setActive(Institution $institution, bool $active): Institution
    {
        $institution->update(['is_active' => $active]);

        return $institution;
    }

    /**
     * ফর্মের "কোন প্রতিষ্ঠান" ঘর — তালিকা থেকে বাছা, অথবা এখনই যোগ করা।
     *
     * ⓘ মূলধনের "কে" ঘরের ধাঁচে ([[PersonResolver]]): দুইটার একটা, দুইটাই নয়।
     * ⚠️ মালিকের কথায় তালিকাটা কেবল অর্থ মডিউলে — আলাদা মাস্টার পর্দায়
     * গিয়ে বসানোর পথ নেই, তাই নতুন নাম যোগ করা ফর্মেই, এক জমায়।
     *
     * @param  array<string, mixed>  $data  `institution_id` বা `institution_new` — দুইটাই এখান থেকে সরে যায়
     */
    public function resolve(array &$data, string $kind, string $field = 'institution_id'): ?int
    {
        $picked = (int) ($data[$field] ?? 0);
        $typed = trim((string) ($data['institution_new'] ?? ''));

        unset($data['institution_new']);

        if ($picked > 0) {
            if ($typed !== '') {
                throw ValidationException::withMessages([
                    'institution_new' => __('finance::institution.pick_or_type'),
                ]);
            }

            return $picked;
        }

        if ($typed === '') {
            return null;
        }

        /*
         * ⓘ নামটা আগে থেকেই থাকলে (বানানের ছোটখাটো তফাতসহ) নতুন সারি নয় —
         * সেটাই নেওয়া হয়। ⚠️ ব্যবহারকারী তালিকায় দেখেননি বলে টাইপ করেছেন;
         * থামিয়ে দিলে তিনি আবার খুঁজতেন, আর শেষে অন্য বানানে লিখতেন।
         */
        $existing = Institution::query()->where('name_key', Institution::keyFor($typed))->first();

        if ($existing !== null) {
            return $existing->id;
        }

        return $this->create(['kind' => $kind, 'name_en' => $typed])->id;
    }

    /**
     * প্রতিষ্ঠানের নাম — পুরনো লেখা-ঘরগুলো ভরে রাখার জন্য।
     *
     * ⓘ ইংরেজি নামটাই, চলতি ভাষা যা-ই হোক: ঘরগুলো ঐতিহাসিক, আর ভাষা
     * বদলালে খাতার পুরনো সারি বদলে যাওয়া উচিত নয়।
     */
    public function nameOf(?int $id): ?string
    {
        return $id === null ? null : Institution::query()->find($id)?->name_en;
    }

    /**
     * "খাত জোড়ো" — একটা ব্যাংক/MFS খাত এই প্রতিষ্ঠানের।
     *
     * ⛔ নগদের খাত নয়, আর MFS খাত কেবল মোবাইল ব্যাংকিংয়ের প্রতিষ্ঠানে —
     * নাহলে "এই ব্যাংকে আজ কত" সংখ্যায় ক্যাশবাক্স বা বিকাশ ঢুকে পড়ত।
     * ⚠️ একটা খাত একটাই প্রতিষ্ঠানের; আগে অন্যটায় জোড়া থাকলে থামে,
     * নীরবে সরিয়ে আনে না — কেউ ইচ্ছে করে ওখানে বসিয়েছিলেন।
     */
    public function link(Institution $institution, int $accountId): InstitutionAccount
    {
        $account = Account::query()->whereKey($accountId)->where('is_group', false)->first();

        $fits = $account !== null && ($account->money_kind === Account::MFS
            ? $institution->kind === Institution::MFS
            : $account->money_kind === Account::BANK
                && in_array($institution->kind, [Institution::BANK, Institution::NBFI], true));

        if (! $fits) {
            throw ValidationException::withMessages([
                'account_id' => __('finance::institution.account_does_not_fit'),
            ]);
        }

        $taken = InstitutionAccount::query()->with('institution')->where('account_id', $accountId)->first();

        if ($taken !== null) {
            throw ValidationException::withMessages([
                'account_id' => __('finance::institution.account_taken', ['name' => $taken->institution?->name()]),
            ]);
        }

        return InstitutionAccount::query()->create([
            'company_id' => CompanyContext::id(),
            'institution_id' => $institution->id,
            'account_id' => $accountId,
            'created_by' => auth()->id(),
        ]);
    }

    public function unlink(Institution $institution, int $accountId): void
    {
        InstitutionAccount::query()
            ->where('institution_id', $institution->id)
            ->where('account_id', $accountId)
            ->delete();
    }

    private function assertNameIsFree(string $name, ?int $exceptId = null): void
    {
        $clash = Institution::query()
            ->where('name_key', Institution::keyFor($name))
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->first();

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'name_en' => __('finance::institution.name_taken', ['name' => $clash->name()]),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $keep = ['kind', 'name_en', 'name_bn', 'short_code', 'branch_name', 'contact_person', 'phone'];

        return collect($data)
            ->only($keep)
            ->map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v)
            ->all();
    }
}
