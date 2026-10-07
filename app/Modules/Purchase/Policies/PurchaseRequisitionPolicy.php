<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseRequisition;

/**
 * ক্রয়ের চাহিদা — কে কী পারে।
 *
 * ── ⚠️ চাওয়া আর মঞ্জুর করা এক অধিকার নয় ────────────────────────────
 * ⓘ চাওয়া প্রায় সবার কাজ: গুদামের লোক, দোকানের লোক, অফিসের যে কেউ।
 * ⛔ মঞ্জুর করা একটা **সিদ্ধান্ত** — ঐ মুহূর্তে প্রতিষ্ঠান টাকা খরচের
 * পথে এক ধাপ এগোয়।
 *
 * ⚠️ এক চাবিতে রাখলে যিনি চান তিনিই নিজের চাওয়া মঞ্জুর করতেন, আর
 * অনুমোদনের ধাপটার কোনো মানেই থাকত না — ⓘ ঠিক সেই ফাঁদ, যেটা গোনা ও
 * মেনে নেওয়া আলাদা রেখে বন্ধ করা হয়েছে ([[StockCountPolicy]])।
 */
class PurchaseRequisitionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchase.requisition.view');
    }

    public function view(User $user, PurchaseRequisition $document): bool
    {
        return $user->can('purchase.requisition.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchase.requisition.create');
    }

    /**
     * ⛔ বাতিল — খসড়া ও অনুমোদিত দুইটাই, কিন্তু আদেশ হয়ে যাওয়ার পর নয়।
     *
     * ⚠️ আদেশটা থেকে যেত, অথচ যে কারণে সেটা জন্মেছিল তা নেই — কাগজের
     * গল্পে একটা ফাঁক। ⓘ শর্তটা সেবাতেও আছে, কারণ ওখানে ভুলের বার্তায়
     * আদেশের নম্বরটা বলা যায়।
     */
    public function delete(User $user, PurchaseRequisition $document): bool
    {
        return $user->can('purchase.requisition.create')
            && $document->purchase_order_id === null
            && $document->status !== DocumentStatus::CANCELLED;
    }

    /**
     * ⭐ মঞ্জুর করা — নিজের চাবি।
     */
    public function approve(User $user, PurchaseRequisition $document): bool
    {
        return $user->can('purchase.requisition.approve')
            && $document->status === DocumentStatus::DRAFT;
    }
}
