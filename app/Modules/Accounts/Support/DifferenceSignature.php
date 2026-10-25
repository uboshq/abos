<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Support;

/**
 * ⭐ রসিদ-পরিশোধ ভাউচারের কম-বেশির (ছাড় বা ৳০.৫০-এর বেশি রাউন্ডিং) মালিকের সই — কোন ছকে (cb-ma-5, ২৫ অক্টোবর ২০২৬)।
 *
 * ⓘ বিক্রির বিলের ছাড়ের হুবহু ছক — `sales.discount`, মালিকের একটাই ধাপ ([[\App\Modules\Approval\Services\OwnerSignsDiscounts]];
 * মালিকের নিয়ম ১–২ অক্টোবর: যেকোনো ছাড়ে মালিকের সই)। আলাদা ছক বানালে মালিককে দুই জায়গায় একই সই বসাতে হত, আর একটা ভুলে বন্ধ থাকত।
 * ⓘ নিজের ক্লাসে, [[\App\Modules\Accounts\Services\VoucherApproval]]-এ নয় — সেখানকার প্রতিটা লেখা-ধ্রুবক `accounts.<কাজ>` ছক বলে গোনা হয়
 * ([[EveryApprovalAskedForCanBeConfiguredTest]]), আর এই দুটো সেই ছক নয়।
 */
final class DifferenceSignature
{
    public const MODULE = 'sales';

    public const ACTION = 'discount';
}
