<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Core\Engines\Audit\AuditEngine;
use App\Modules\Customer\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * গ্রাহককে নিজের পাতার চাবি দেওয়া — আর ফেরত নেওয়া।
 *
 * ── কেন গ্রাহকের সাধারণ সেবা নয়, আলাদা একটা ─────────────────────────
 * `CustomerService::update()` ফর্মের ঘরগুলো বসায়: নাম, ঠিকানা, ফোন।
 * পোর্টালের চাবি ওই দলের জিনিস নয় — ওটা দিলে বাইরের একজন মানুষ ভেতরের
 * সংখ্যা দেখতে পান। দুইটা একই সেবায় থাকলে একদিন একটা ছাঁকনি শিথিল করা
 * হত ঠিকানা শোধরানোর সুবিধার জন্য, আর চাবিটাও সেই ফাঁক দিয়ে বেরোত।
 *
 * ── কেন `forceFill`, `update` নয় ────────────────────────────────────
 * `portal_enabled` আর `portal_password` — দুইটার কোনোটাই `$fillable`-এ
 * নেই, ইচ্ছাকৃতভাবে। ফলে গ্রাহকের ফর্মে লুকানো একটা ঘর জুড়ে দিলেও
 * সেটা কিছু করত না। এখানে সচেতনভাবে জোর করে বসাতে হয়, আর সেটাই
 * একমাত্র পথ।
 */
final class CustomerPortalService
{
    public function __construct(private readonly AuditEngine $audit) {}

    /**
     * চালু করা, বা চালু থাকা অবস্থায় নতুন পাসওয়ার্ড বসানো।
     *
     * দুইটা কাজ একটাই পদ্ধতিতে, কারণ মালিকের দিক থেকে কাজটা একই:
     * "গ্রাহককে একটা পাসওয়ার্ড দিলাম"। আলাদা করলে "চালু আছে কিন্তু
     * পাসওয়ার্ড নেই" নামে একটা অবস্থা তৈরি হত — যেখানে গ্রাহক লগইন
     * পাতা দেখেন কিন্তু কোনো পাসওয়ার্ডেই ঢুকতে পারেন না।
     */
    public function enable(Customer $customer, string $password): Customer
    {
        $wasEnabled = (bool) $customer->portal_enabled;

        DB::transaction(function () use ($customer, $password, $wasEnabled): void {
            $customer->forceFill([
                'portal_enabled' => true,
                'portal_password' => $password,
            ])->save();

            /*
             * পাসওয়ার্ডটা কী বসল তা নয়, বসেছে সেটাই খাতায়।
             *
             * ── কেন আলাদা করে লিখতে হয় ─────────────────────────────
             * `portal_password` অডিটের বাদ-তালিকায় (`auditIgnores()`),
             * নাহলে hash-টা `audit_field_changes`-এ বসে যেত — অর্থাৎ
             * খাতাটাই একটা পাসওয়ার্ডের তালিকা হয়ে উঠত, আর যে কেউ
             * নিরীক্ষার পর্দা দেখতে পারেন তিনি সেটা দেখতেন।
             *
             * কিন্তু বাদ দিলে ঘটনাটাও হারাত। "গ্রাহকের পাসওয়ার্ড কে
             * বদলেছিল" — টাকার হিসাব নিয়ে ঝগড়ার দিন এটাই প্রথম
             * প্রশ্ন। তাই মান নয়, কাজটা লেখা হয়।
             */
            $this->audit->recordAction(
                $customer,
                $wasEnabled ? 'portal_password_set' : 'portal_enabled',
            );
        });

        return $customer->fresh();
    }

    /**
     * বন্ধ করা।
     *
     * ── কেন পাসওয়ার্ডটা মোছা হয় না ──────────────────────────────────
     * লগইন `portal_enabled` মেলায়, তাই বন্ধ থাকলে পুরনো পাসওয়ার্ড
     * দিয়েও ঢোকা যায় না — hash-টা রেখে দেওয়া নিরাপদ। আর রেখে দিলে
     * ভুল করে বন্ধ করা গ্রাহককে আবার চালু করতে নতুন পাসওয়ার্ড লাগে না,
     * অর্থাৎ তাকে ফোন করে নতুন একটা বলতে হয় না।
     */
    public function disable(Customer $customer): Customer
    {
        DB::transaction(function () use ($customer): void {
            $customer->forceFill(['portal_enabled' => false])->save();

            $this->audit->recordAction($customer, 'portal_disabled');
        });

        return $customer->fresh();
    }
}
