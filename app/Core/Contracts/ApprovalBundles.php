<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Models\Approval;

/**
 * যে অনুরোধগুলো একসাথে যায় — এক পাতায় দেখা, এক ক্লিকে নিশ্চিত (২৮ সেপ্টেম্বর ২০২৬)।
 *
 * ── ⛔ কেন (মালিকের কথা) ────────────────────────────────────────────────
 * *"অ্যাপ্রভাল করার সময় পরিবহন দেখাচ্ছে না, পরিবহনের ভাড়াও না, জমা টাকার
 * হিসাবও না … এক নজরে সব দেখে এক ক্লিকে সব অনুমোদন … এটা অনুমোদন বললে ভুল
 * হবে, এটা কনফার্মেশন, কারণ সিস্টেম তো আগেই ব্লক করা আছে"*।
 * ⓘ কাউন্টারের একটা বিক্রি সইয়ে আটকালে চালান, বিল আর প্রতিটা জমা আলাদা
 * অনুরোধ হয়ে ইনবক্সে বসত — সইকারী একটা একটা খুলতেন, কেউ পুরো ছবিটা দেখতেন না।
 *
 * ── ⭐ কেন একটা চুক্তি ──────────────────────────────────────────────────
 * অনুমোদন কোরের, বিক্রি Sales-এর; কোর জানে না কোন কাগজগুলো একটা বিক্রি
 * (§১৯.৭)। ⓘ যে মডিউল জানে সে বাঁধে (module.php `bindings`); না বাঁধলে প্রতিটা
 * অনুরোধ আগের মতোই একা।
 */
interface ApprovalBundles
{
    /**
     * এই অনুরোধ যে দলের — না থাকলে `null`।
     *
     * @return array{
     *     facts: list<array{label: string, value: string}>,
     *     columns: list<array{key: string, label: string, numeric?: bool}>,
     *     rows: list<array<string, string|null>>,
     *     totals: array<string, string>,
     *     deposits: list<array{method: string, account: string, amount: string, reference: string, slips: list<array{name: string, url: string}>}>,
     *     approvals: list<Approval>,
     *     party?: array{type: string, id: int}|null,
     * }|null
     *
     * ⚠️ `approvals` — দলের প্রতিটা কাগজের **সর্বশেষ** অনুরোধ, অবস্থা যা-ই হোক;
     * কোনটা এখনো অপেক্ষায় তা ডাকার দিক ছাঁকে।
     */
    public function bundleFor(Approval $approval): ?array;
}
