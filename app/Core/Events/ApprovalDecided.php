<?php

declare(strict_types=1);

namespace App\Core\Events;

use App\Models\Approval;

/**
 * একটা অনুমোদনের অনুরোধ শেষ সিদ্ধান্তে পৌঁছেছে — লেনদেন পাকা হওয়ার পরে।
 *
 * ── ⭐ কেন লাগল — মালিকের সিদ্ধান্ত, ২৭ সেপ্টেম্বর ২০২৬ ─────────────────
 * *"সব সহ শেষ হলে নিজে থেকেই পোস্ট হবে"*। ⓘ ইঞ্জিন কেবল "হ্যাঁ" বলত,
 * কাগজ এগোত না — লাইভে INV-0005-এর দুইটা সই-ই অনুমোদিত, অথচ বিক্রিটা
 * খসড়া, কারণ কেউ হাতে "নিশ্চিত" চাপেননি।
 *
 * ── ⚠️ কেন ঘটনা, সরাসরি ডাক নয় ────────────────────────────────────────
 * ইঞ্জিন কোরের, আর কাগজগুলো মডিউলের। ⛔ কোর মডিউলকে চিনলে সীমানা ভাঙত
 * ([[BoundariesTest]]); তাই কোর কেবল ঘোষণা করে, আর যে মডিউলের কাগজ সে
 * নিজে শোনে ([[FinishTheHeldSaleOnTheLastSignature]])।
 *
 * ⓘ ছোড়া হয় `DB::afterCommit`-এ — সইটা আগে পাকা হয়। ⚠️ শ্রোতা ব্যর্থ
 * হলেও সই ফেরত যায় না: সই মানুষের সিদ্ধান্ত, আর পরের ধাপের একটা বাধা
 * (ধরুন বাকির দেয়াল) সেই সিদ্ধান্ত মুছে দিতে পারে না।
 */
final class ApprovalDecided extends DomainEvent
{
    public static function from(Approval $approval): self
    {
        return new self(
            publicId: (string) $approval->public_id,

            payload: [
                'approval_id' => (int) $approval->id,
                'status' => (string) $approval->status,
                'module' => (string) $approval->module,
                'action' => (string) $approval->action,
                'approvable_type' => (string) $approval->approvable_type,
                'approvable_id' => (int) $approval->approvable_id,
            ],

            companyId: (int) $approval->company_id,
        );
    }
}
