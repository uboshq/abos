<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Modules\Accounts\Models\Account;
use Illuminate\Validation\ValidationException;

/**
 * টাকা যে খাতে বসে সেটা সত্যিই টাকার খাত কি না — এক জায়গায় লেখা নিয়ম।
 *
 * ── ⛔ কেন আলাদা ক্লাস (পূর্ণ নিরীক্ষা, ২৭ সেপ্টেম্বর ২০২৬, §১.১) ─────────
 * নিয়মটা ছিল কেবল আদায়ে ([[CollectionService]])। কাউন্টারের "জমা" শুধু
 * দেখত খাতটা এই কোম্পানির কি না — ⚠️ তাই খরচের খাতকে "জমা" বানিয়ে ৮০,০০০
 * টাকার বাকি বিক্রয় বকেয়াসহ মুছে দেওয়া যেত, আর "টাকা এখনই দেওয়া হয়েছে"
 * ধরে বাকির সীমার দেয়ালও পেরিয়ে যেত। ⓘ নকল না করে এক জায়গায় তুলে আনা,
 * যাতে দুই দরজা কখনো আলাদা হয়ে না যায়।
 *
 * ⓘ হিসাব-মডিউলে, বিক্রয়ে নয় — ক্রয়ের পরিশোধও একই নিয়ম ডাকে, আর এক মডিউল
 * অন্যটার সার্ভিস ডাকে না (২৭ সেপ্টেম্বর ২০২৬)।
 *
 * টাকার খাত মানে: নগদ, ব্যাংক বা মোবাইল-ব্যাংকিংয়ের মাথার সন্তান খাত
 * ([[StandardChart::MONEY_PARENTS]]) — মাথা নিজে নয়, দলের খাত নয়। ⓘ হাতে
 * থাকা চেকের খাত (১১০৪) কেবল যেখানে চাওয়া হয় (`allowHolding`)।
 */
final class MoneyAccountRule
{
    /**
     * @param  string  $field  ত্রুটি কোন ঘরে দেখাবে — আদায়ে `account_id`, কাউন্টারে `deposits`
     */
    public function assert(int $accountId, bool $allowHolding = false, string $field = 'account_id'): Account
    {
        $account = Account::query()->postable()->whereKey($accountId)->first();

        if ($account === null) {
            throw ValidationException::withMessages([
                $field => __('accounts::validation.unknown_account'),
            ]);
        }

        if ($account->is_group) {
            throw ValidationException::withMessages([
                $field => __('accounts::validation.group_takes_no_money', ['name' => $account->name()]),
            ]);
        }

        // মায়ের সন্তান কোনো খাত — মাথা নিজে নয়
        $isChildOfMother = Account::query()
            ->whereKey($account->parent_id)
            ->whereIn('code', StandardChart::MONEY_PARENTS)
            ->exists();

        $isHoldingLeaf = $allowHolding && in_array($account->code, StandardChart::MONEY_HOLDING, true);

        if (! $isChildOfMother && ! $isHoldingLeaf) {
            throw ValidationException::withMessages([
                $field => __('accounts::validation.not_a_money_account', ['name' => $account->name()]),
            ]);
        }

        return $account;
    }
}
