<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Policies;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseBill;

/**
 * ক্রয় বিল — কে কী পারে।
 *
 * অনুমতির নামগুলো module.php-তে ঘোষিত, আর ওখানেই একমাত্র জায়গা যেখানে
 * তালিকাটা আছে। এখানে দ্বিতীয় তালিকা রাখলে দুইটা একদিন আলাদা হত।
 */
class PurchaseBillPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('purchase.bill.view');
    }

    public function view(User $user, PurchaseBill $document): bool
    {
        return $user->can('purchase.bill.view');
    }

    public function create(User $user): bool
    {
        return $user->can('purchase.bill.create');
    }

    /**
     * সম্পাদনা — খসড়া সবার, নিশ্চিত কেবল super admin-এর।
     *
     * ── ⓘ কেন নিশ্চিত বিল সাধারণত বদলায় না ──────────────────────────
     * ওটা সরবরাহকারীর খাতায় **দেনা বসিয়ে ফেলেছে**, আর হয়তো টাকাও
     * পরিশোধ হয়েছে। ⚠️ চুপচাপ বদলাতে দিলে কাগজের সংখ্যা আর খাতার
     * সংখ্যা আলাদা হয়ে যেত, আর ছয় মাস পরে কেউ বলতে পারত না কোনটা আসল।
     *
     * ── ⭐ কেন তবু super admin পারে, ১৮ সেপ্টেম্বর ২০২৬ ──────────────
     * মালিকের সিদ্ধান্ত: *"edite update delete super admin korte parbe"*,
     * আর *"super admin sob pare sob company te"*।
     *
     * ⛔ কিন্তু অনুমতিটা একা যথেষ্ট নয়: ঐ বিলের দাখিলা খাতায় বসে আছে।
     * ⓘ তাই [[PurchaseBillService::update()]] নিশ্চিত বিল সম্পাদনার সময়
     * **পুরনো দাখিলা উল্টে নতুন করে বসায়** — নাহলে কাগজ বদলাত, খাতা নয়।
     *
     * ⚠️ বাতিল হওয়া বিল এখানেও বাদ: ওটা ইতিহাস, আর ইতিহাস বদলায় না।
     */
    public function update(User $user, PurchaseBill $document): bool
    {
        if (! $user->can('purchase.bill.create')) {
            return false;
        }

        if ($document->status === DocumentStatus::DRAFT) {
            return true;
        }

        return $document->status === DocumentStatus::CONFIRMED
            && $user->hasRole('super_admin');
    }

    public function delete(User $user, PurchaseBill $document): bool
    {
        return $user->can('purchase.bill.cancel');
    }
}
