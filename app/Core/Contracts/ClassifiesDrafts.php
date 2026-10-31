<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ যে কাগজের সই status-এ বসে না (ভাউচার, আদায়, বিক্রির বিল), তার খসড়াগুলোর দল — [[ListSummary]]-এর জন্য, একসাথে।
 *
 * ⓘ খসড়া তিন রকম (তালিকার মোটের চুক্তি v6; fe, a4, a6 — ২৪ অক্টোবর ২০২৬):
 *   pending  — শেষ অনুমোদন PENDING, আর অনুরোধের পরে কাগজ বদলায়নি (ছাপ মেলে);
 *   rejected — শেষ অনুমোদন REJECTED, আর তারপর কাগজ বদলায়নি;
 *   draft    — বাকি সব: অনুরোধ নেই, বাসি অনুরোধ, ফেরানোর পরে বদলানো, সই আছে অথচ পোস্ট হয়নি।
 *
 * ⛔ দলটা কাগজের নিজের সই-সেবা ঠিক করে — পাতার নোট, ফোন আর অপেক্ষার তালিকা যে কোড ডাকে, সেই কোডেই। SQL-এ ঘড়ি দিয়ে
 * লিখলে "বদলায়নি" সেকেন্ডে মাপা হত ([[VoucherApproval::changedSince()]]-এর মন্তব্য), আর দুই জায়গা দুই কথা বলত।
 * ⚠️ একসাথে, সারি ধরে নয়: পুরনো ফেলে রাখা খসড়া শয়ে শয়ে হতে পারে — ছাপ [[DocumentFingerprint::ofMany()]] দিয়ে।
 */
interface ClassifiesDrafts
{
    /**
     * এই সেবা কোন কোন দল দিতে পারে — যে কাগজ ফেরানো যায় না, তার তালিকায় rejected থাকবে না (চাবিটাই বাদ যায়)।
     *
     * @return list<'pending'|'rejected'|'draft'>
     */
    public function groups(): array;

    /**
     * প্রতিটা খসড়ার দল — প্রত্যেকটার উত্তর চাই, একটাও বাদ নয়।
     *
     * @param  Collection<int, Model>  $drafts
     * @return array<int|string, 'pending'|'rejected'|'draft'>  চাবি = কাগজের আইডি
     */
    public function standing(Collection $drafts): array;
}
