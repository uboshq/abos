<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Events;

use App\Core\Events\DomainEvent;
use App\Modules\Accounts\Models\Account;

/**
 * খাতের ফর্ম জমা হলো — খাতটা বসেছে বা বদলেছে।
 *
 * ⓘ পেলোডে খাতের আইডি আর ফর্মের `ext[...]` অংশ — যে ঘরগুলো অন্য মডিউল
 * [[AccountFormOpened]] দিয়ে যোগ করেছিল। হিসাব ওগুলোর মানে জানে না,
 * কেবল বয়ে নেয়।
 *
 * ⚠️ কেবল স্কেলার মান যায় — ডোমেইন ইভেন্টের নিয়ম; তালিকা বা বস্তু এলে
 * নীরবে বাদ।
 */
final class AccountSaved extends DomainEvent
{
    /** @param  array<string, mixed>  $ext */
    public static function from(Account $account, array $ext): self
    {
        $scalars = array_filter($ext, fn ($v) => $v === null || is_scalar($v));

        return new self(
            publicId: (string) $account->public_id,
            payload: [...$scalars, 'account_id' => (int) $account->id],
        );
    }
}
