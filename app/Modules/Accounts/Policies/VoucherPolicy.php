<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Policies;

use App\Models\User;
use App\Modules\Accounts\Models\Voucher;

/**
 * ভাউচারের নীতি — কন্ট্রোলারের দরজাগুলোর হুবহু একই চাবি, ২৮ সেপ্টেম্বর ২০২৬।
 *
 * ── ⛔ কেন (স্লিপের কাজে ধরা পড়ল) ──────────────────────────────────────
 * ভাউচারের কোনো নীতি ছিল না, তাই `can('create', Voucher::class)` আর
 * `can('view', $voucher)` সবার জন্য — মালিকেরও — মিথ্যা। ⚠️ ফল: ভাউচারের
 * পাতায় কাগজ তোলার ফর্ম কোনোদিন আঁকা হয়নি ([[x-ui.attachments]]), আর কাগজ
 * নামানোর দরজা সবসময় ৪০৩ — কোথাও কিছু লাল হত না, কেবল ফর্মটা থাকত না।
 *
 * ⓘ ভাউচারের দরজাগুলোও ([[VoucherController::middleware()]]) এখন এই নীতিই
 * জিজ্ঞেস করে: দেখা `accounts.report`, লেখা `accounts.voucher.create`, বদল ও পোস্ট
 * `accounts.voucher.update`, বাতিল `accounts.voucher.delete`। ⚠️ দরজা আর কাগজের ঘর
 * এক জায়গা থেকে উত্তর পায়, তাই পাতা খোলে অথচ কাগজ খোলে না — এমন হতে পারে না।
 */
class VoucherPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounts.report');
    }

    public function view(User $user, Voucher $voucher): bool
    {
        return $user->can('accounts.report');
    }

    public function create(User $user): bool
    {
        return $user->can('accounts.voucher.create');
    }

    public function update(User $user, Voucher $voucher): bool
    {
        return $user->can('accounts.voucher.update');
    }

    public function delete(User $user, Voucher $voucher): bool
    {
        return $user->can('accounts.voucher.delete');
    }
}
