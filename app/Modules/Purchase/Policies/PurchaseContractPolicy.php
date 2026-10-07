<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseContract;

/**
 * ক্রয় চুক্তি — কে কী পারে।
 *
 * ── ⚠️ লেখা আর চালু করা এক অধিকার নয় ───────────────────────────────
 * ⓘ চুক্তির খসড়া লেখা কেরানির কাজও হতে পারে। ⛔ **চালু করা** মানে ঐ
 * দরটাকে প্রতিষ্ঠানের কথা বানিয়ে দেওয়া — এরপর থেকে প্রতিটা আদেশ ঐ
 * সংখ্যার বিরুদ্ধে মেলানো হবে।
 *
 * ⚠️ এক চাবিতে রাখলে যে কেউ একটা দর লিখে নিজেই চালু করে দিতে পারতেন,
 * আর সরবরাহকারীর সাথে কী কথা হয়েছিল তার কোনো স্বাধীন সাক্ষী থাকত না।
 */
class PurchaseContractPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchase.contract.view');
    }

    public function view(User $user, PurchaseContract $document): bool
    {
        return $user->can('purchase.contract.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchase.contract.create');
    }

    /**
     * ⭐ চালু করা — নিজের চাবি।
     */
    public function activate(User $user, PurchaseContract $document): bool
    {
        return $user->can('purchase.contract.activate')
            && $document->status === DocumentStatus::DRAFT;
    }
}
