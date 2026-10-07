<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Events;

use App\Core\Events\DomainEvent;
use App\Modules\Accounts\Models\Account;

/**
 * খাতের ফর্ম খুলেছে — অন্য মডিউল চাইলে নিজের একটা ঘর যোগ করতে পারে।
 *
 * ── কেন লাগল, ২০ সেপ্টেম্বর ২০২৬ ──────────────────────────────────────
 * অর্থের "আর্থিক প্রতিষ্ঠান" চায় ব্যাংকের খাত বানানোর সময়ই বলা যাক এটা
 * কোন ব্যাংকের। ⚠️ কিন্তু হিসাব অর্থকে চেনে না, চিনতে পারবেও না — সে
 * সবার নিচে দাঁড়ায় ([[Tests\Feature\Architecture\BoundariesTest]])। তাই
 * ফর্ম কেবল ঘোষণা করে "ফর্মটা খুলেছে", আর যে শোনে সে নিজের ভিউয়ের নাম
 * [[extras]]-এ রেখে যায়। অর্থ বন্ধ বা মুছে ফেলা হলে ফর্মটা আগের মতোই।
 *
 * ── ⚠️ এই একটা ইভেন্ট শ্রোতার লেখা ফেরত নেয় ─────────────────────────
 * ⓘ ডোমেইন ইভেন্টের সাধারণ নিয়ম একমুখী: ঘটনা জানানো হয়, শ্রোতা সাড়া
 * দেয়, কিন্তু ঘোষককে কিছু ফেরত দেয় না। এখানে [[extras]] ফেরত আসে, আর
 * সেটা ইচ্ছাকৃত ছাড় — পর্দা আঁকার সময়ের হুক, আর কোনো ব্যবসার সিদ্ধান্ত
 * এর উপর দাঁড়ায় না। ⛔ কেউ না শুনলে তালিকাটা খালি থাকে, ফর্ম আগের মতোই
 * চলে; অর্থাৎ ফেরত না এলেও কিছু ভাঙে না।
 *
 * ⓘ পেলোডে কেবল স্কেলার (খাতের আইডি, টাকার ধরন, নতুন কি না) — শ্রোতার
 * যা না হলেই নয়। ⚠️ যোগ করা ঘরের নাম `ext[...]`-এর ভেতরে রাখতে হয়;
 * জমার সময় ঐ অংশটাই [[AccountSaved]]-এ যায়, হিসাবের নিজের ঘরগুলোর
 * সাথে মেশে না।
 */
final class AccountFormOpened extends DomainEvent
{
    /** @var list<array{0: string, 1: array<string, mixed>}> ভিউয়ের নাম আর তার ডেটা */
    public array $extras = [];

    public static function from(Account $account): self
    {
        return new self(
            publicId: (string) $account->public_id,

            payload: [
                'account_id' => $account->exists ? (int) $account->id : 0,
                'money_kind' => $account->money_kind,
                'is_new' => ! $account->exists,
            ],
        );
    }

    /** @param  array<string, mixed>  $data */
    public function add(string $view, array $data = []): void
    {
        $this->extras[] = [$view, $data];
    }
}
