<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Account;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * খাত তৈরি, সম্পাদনা ও নিষ্ক্রিয় করা।
 *
 * নিয়মগুলো এখানে, কন্ট্রোলারে নয় (সেকশন ১৯.৬) — কারণ খাত তৈরি হবে
 * স্ক্রিন থেকে, প্রমিত ছক বসানোর সময়, আর পরে ইমপোর্ট থেকেও। তিন জায়গায়
 * তিনবার নিয়ম লিখলে একদিন একটায় ফাঁক থাকত, আর ফাঁকটা দিয়ে ঢোকা একটা
 * ভুল খাত পুরো ব্যালেন্স শিট নষ্ট করত।
 */
final class AccountService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Account
    {
        return DB::transaction(function () use ($data) {
            $parent = $this->resolveParent($data['parent_id'] ?? null);

            $type = $this->resolveType($data, $parent);

            $this->assertCodeIsFree((string) $data['code']);

            return Account::create([
                ...$data,
                'code' => trim((string) $data['code']),
                'type' => $type,
                'nature' => $data['nature'] ?? Account::defaultNatureFor($type),
                'is_group' => (bool) ($data['is_group'] ?? false),
                'is_active' => $data['is_active'] ?? true,
                // সিস্টেমের খাত স্ক্রিন থেকে তৈরি হয় না — শুধু প্রমিত ছক
                // বসানোর সময়, আর সেটা markAsSystem() দিয়ে।
                'is_system' => false,
                'status' => DocumentStatus::CONFIRMED,
                // branch_id এখানে ছিল, অথচ accounts টেবিলে ওই কলামই নেই —
                // হিসাবের ছক কোম্পানি-ব্যাপী, শাখাভিত্তিক নয়। সাধারণ
                // অবস্থায় mass assignment ওটা ফেলে দিত বলে কিছু হত না,
                // কিন্তু সিডারে গার্ড বন্ধ থাকে, আর তখন ইনসার্টটাই ভাঙত।
                'created_by' => auth()->id(),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Account $account, array $data): Account
    {
        return DB::transaction(function () use ($account, $data) {
            if (isset($data['code']) && trim((string) $data['code']) !== $account->code) {
                $this->assertNotSystem($account, 'code');
                $this->assertCodeIsFree(trim((string) $data['code']), $account->id);
            }

            $parent = $this->resolveParent($data['parent_id'] ?? $account->parent_id, $account);

            if (array_key_exists('type', $data) && $data['type'] !== $account->type) {
                $this->assertNotSystem($account, 'type');
                $this->assertTypeCanChange($account);
            }

            $type = $this->resolveType($data + ['type' => $account->type], $parent);

            /*
             * গ্রুপ ↔ সাধারণ বদলানোর দুইটা নিষেধ।
             *
             * এন্ট্রি আছে এমন খাত গ্রুপ বানানো যায় না: গ্রুপে সরাসরি এন্ট্রি
             * থাকতে পারে না, আর আগের এন্ট্রিগুলো তখন কোথাও গোনা হত না —
             * ট্রায়াল ব্যালেন্স নীরবে কম দেখাত।
             *
             * সন্তান আছে এমন গ্রুপ সাধারণ বানানো যায় না: তখন সন্তানরা
             * এমন এক বাবার নিচে থাকত যাতে নিজেরও টাকা বসে, আর মাথার
             * যোগফল আর সন্তানদের যোগফল আলাদা হত।
             */
            $wantsGroup = (bool) ($data['is_group'] ?? $account->is_group);

            if ($wantsGroup && ! $account->is_group && $account->hasEntries()) {
                throw ValidationException::withMessages([
                    'is_group' => __('accounts::validation.has_entries_cannot_group'),
                ]);
            }

            if (! $wantsGroup && $account->is_group && $account->children()->exists()) {
                throw ValidationException::withMessages([
                    'is_group' => __('accounts::validation.has_children_must_stay_group'),
                ]);
            }

            // খোলা ব্যালেন্স সম্পাদনায় বদলায় না — গ্রাহকের ক্ষেত্রেও একই
            // নিয়ম, একই কারণে: লেজার আর এই সংখ্যা দুই রকম বললে কোনটা
            // সত্যি তা বলার উপায় থাকে না। বদলাতে জাবেদা ভাউচার।
            unset($data['opening_balance'], $data['opening_date'], $data['is_system']);

            $account->update([
                ...$data,
                'code' => isset($data['code']) ? trim((string) $data['code']) : $account->code,
                'type' => $type,
                'nature' => $data['nature'] ?? $account->nature,
                'is_group' => $wantsGroup,
            ]);

            // ধরন বদলালে নিচের সবারও বদলায় — সন্তান বাবার চেয়ে অন্য ধরনের
            // হতে পারে না, নাহলে একটা সম্পদের নিচে একটা খরচ ঝুলত।
            if ($account->wasChanged('type')) {
                $this->cascadeType($account);
            }

            return $account->fresh();
        });
    }

    /**
     * নিষ্ক্রিয় করা — মোছা নয় (নিয়ম ৫)।
     *
     * এন্ট্রি থাকা খাত মুছলে ওই এন্ট্রিগুলো কোন খাতের তা বলার উপায় থাকত না,
     * আর ট্রায়াল ব্যালেন্সে "অজানা খাত" নামের একটা সারি জন্মাত।
     */
    public function deactivate(Account $account): Account
    {
        $this->assertNotSystem($account, 'is_active');

        return DB::transaction(function () use ($account) {
            // নিচের সবগুলোও — একটা সক্রিয় সন্তান নিষ্ক্রিয় বাবার নিচে
            // ঝুললে ড্রপডাউনে সেটা দেখা যেত কিন্তু গাছে খুঁজে পাওয়া যেত না।
            $this->setActive($account->selfAndDescendants(), false);

            return $account->fresh();
        });
    }

    public function activate(Account $account): Account
    {
        return DB::transaction(function () use ($account) {
            // উপরের দিকে — নিষ্ক্রিয় বাবার নিচে সক্রিয় সন্তান রাখা যায় না
            $this->setActive($account->ancestors()->push($account), true);

            return $account->fresh();
        });
    }

    /**
     * সক্রিয়/নিষ্ক্রিয় লেখা — প্রতিটা সারিতে সত্যিই।
     *
     * refresh() দরকার, কারণ save() শুধু বদলানো ঘরগুলো লেখে। ডাকার
     * জায়গায় ধরে রাখা পুরনো একটা ইনস্ট্যান্সে is_active ইতিমধ্যেই কাঙ্ক্ষিত
     * মান হলে Eloquent কোনো UPDATE পাঠাত না, অথচ ডাটাবেজে উল্টোটা থাকত।
     *
     * ঠিক এটাই ঘটেছিল: বাবাকে নিষ্ক্রিয় করার পর সন্তানকে সক্রিয় করতে গেলে
     * সন্তানের মেমোরির কপিতে is_active এখনো true, তাই কিছুই লেখা হত না
     * আর সন্তানটা নিষ্ক্রিয়ই থেকে যেত — নীরবে।
     *
     * একটা whereIn আপডেটে সমস্যাটা মিটত, কিন্তু তখন মডেল ইভেন্ট চলত না,
     * আর অডিট (নিয়ম ২) ওই ইভেন্টের উপরেই দাঁড়াবে।
     *
     * @param  Collection<int, Account>|\Illuminate\Database\Eloquent\Collection<int, Account>  $nodes
     */
    private function setActive(iterable $nodes, bool $active): void
    {
        foreach ($nodes as $node) {
            $node->refresh()->forceFill(['is_active' => $active])->save();
        }
    }

    /**
     * প্রমিত ছক বসানোর সময় খাতটাকে সিস্টেমের বলে চিহ্নিত করা।
     *
     * আলাদা পদ্ধতি, create()-এর প্যারামিটার নয়: স্ক্রিন বা ইমপোর্ট থেকে
     * কেউ যেন নিজের খাতকে সিস্টেমের বলে দাবি করতে না পারে।
     */
    public function markAsSystem(Account $account): Account
    {
        $account->forceFill(['is_system' => true])->save();

        return $account;
    }

    private function resolveParent(mixed $parentId, ?Account $moving = null): ?Account
    {
        if (blank($parentId)) {
            return null;
        }

        $parent = Account::query()->find($parentId);

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => __('accounts::validation.parent_not_found'),
            ]);
        }

        if (! $parent->is_group) {
            throw ValidationException::withMessages([
                'parent_id' => __('accounts::validation.parent_must_be_group'),
            ]);
        }

        if ($moving !== null) {
            // নিজের নিচে নিজেকে বসানো — গাছটা চক্র হয়ে যেত, আর তখন
            // ব্যালেন্স গুনতে গিয়ে অসীম লুপ।
            $descendantIds = $moving->selfAndDescendants()->pluck('id')->all();

            if (in_array($parent->id, $descendantIds, true)) {
                throw ValidationException::withMessages([
                    'parent_id' => __('accounts::validation.parent_cannot_be_own_descendant'),
                ]);
            }
        }

        return $parent;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveType(array $data, ?Account $parent): string
    {
        // বাবা থাকলে বাবার ধরনই চলে — একটা সম্পদের নিচে খরচ থাকতে পারে না
        if ($parent !== null) {
            return $parent->type;
        }

        $type = $data['type'] ?? null;

        if (! in_array($type, Account::TYPES, true)) {
            throw ValidationException::withMessages([
                'type' => __('accounts::validation.unknown_type'),
            ]);
        }

        return $type;
    }

    private function cascadeType(Account $account): void
    {
        foreach ($account->children as $child) {
            $child->forceFill([
                'type' => $account->type,
                'nature' => Account::defaultNatureFor($account->type),
            ])->save();

            $this->cascadeType($child);
        }
    }

    private function assertTypeCanChange(Account $account): void
    {
        if ($account->hasEntries()) {
            throw ValidationException::withMessages([
                'type' => __('accounts::validation.has_entries_cannot_retype'),
            ]);
        }
    }

    private function assertNotSystem(Account $account, string $field): void
    {
        if ($account->is_system) {
            throw ValidationException::withMessages([
                $field => __('accounts::validation.system_account_locked', ['name' => $account->name()]),
            ]);
        }
    }

    private function assertCodeIsFree(string $code, ?int $exceptId = null): void
    {
        $taken = Account::query()
            ->where('code', $code)
            ->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))
            // মুছে ফেলা খাতের কোডও ধরা হয়: soft delete মানে সারিটা আছে,
            // আর unique ইনডেক্সও সেটা দেখে — না দেখলে সেভ করার সময়
            // ডাটাবেজ ব্যতিক্রম দিত, ব্যবহারকারী বুঝত না কী হয়েছে।
            ->withTrashed()
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'code' => __('accounts::validation.code_taken', ['code' => $code]),
            ]);
        }
    }

    /** কোম্পানির প্রসঙ্গ আছে কি না — ছক বসানোর আগে দেখা হয়। */
    public function assertCompanyContext(): void
    {
        if (CompanyContext::id() === null) {
            throw new \RuntimeException('No company in context. Chart of accounts is per company.');
        }
    }
}
