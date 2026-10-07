<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Policies;

use App\Models\User;
use App\Modules\Inventory\Models\QualityInspection;

/**
 * গুণমান পরিদর্শন — কে কী পারে।
 *
 * ── ⚠️ কাগজ খোলা আর রায় দেওয়া এক অধিকার নয় ──────────────────────────
 * ⓘ কাগজ খোলা মানে *"এই মালটা দেখা দরকার"* — গুদামের যে কেউ বলতে
 * পারেন, আর মজুদে কিছুই বদলায় না। ⛔ রায় মানে *"এই মাল নেওয়া হবে
 * না"*: মালটা আটকে যায়, সরবরাহকারীর সাথে তর্কে যায়, আর টাকার হিসাবে
 * যায়।
 *
 * ⭐ মালিকের সিদ্ধান্ত (২৪ সেপ্টেম্বর ২০২৬): পরিদর্শক একটা **আলাদা
 * ভূমিকা**। ⓘ একই চাবিতে রাখলে যিনি মাল বুঝে নেন তিনিই নিজের নেওয়া
 * মালকে পাশ করিয়ে দিতে পারতেন — ঠিক সেই ফাঁদ, যেটা গোনা ও মেনে নেওয়া
 * আলাদা রেখে বন্ধ করা হয়েছে ([[StockCountPolicy]])।
 */
class QualityInspectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.qc.view');
    }

    public function view(User $user, QualityInspection $document): bool
    {
        return $user->can('inventory.qc.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.qc.create');
    }

    /*
     * ⛔ এখানে `update()` ও `delete()` ছিল — ২৪ সেপ্টেম্বর ২০২৬-এ সরানো।
     *
     * ── ⚠️ কেন সরল ──────────────────────────────────────────────────
     * পরিদর্শনের কোনো সম্পাদনা বা মোছার রুট নেই, তাই নিয়ম দুইটায়
     * **পৌঁছানোর কোনো পথ ছিল না**। ⓘ একটা মৃত নিয়ম নীরবে বিপজ্জনক:
     * পরেরজন সেটা পড়ে বিশ্বাস করেন ওটাই পাহারা, অথচ আসল সিদ্ধান্ত
     * অন্য কোথাও নেওয়া হচ্ছে ([[EveryPolicyRuleIsActuallyReachedTest]])।
     *
     * ⭐ ভুল কাগজ লেখা হলে রায় না দিয়ে ফেলে রাখা যায় — মজুদে কিছুই
     * নড়েনি, তাই ক্ষতি নেই। ⓘ সম্পাদনার পর্দা যেদিন লাগবে, নিয়ম
     * দুইটা সেদিন রুটসহ ফিরবে।
     */

    /**
     * ⭐ রায় — নিজের চাবি।
     */
    public function decide(User $user, QualityInspection $document): bool
    {
        return $user->can('inventory.qc.decide') && $document->isPending();
    }

    /**
     * ⭐ বাতিল মাল বিনাশ — রায়েরই চাবি, কিন্তু রায় হয়ে যাওয়া কাগজে (অডিট গ৪, ৪ অক্টোবর ২০২৬)।
     *
     * ── ⛔ কী ভাঙা ছিল ─────────────────────────────────────────────────
     * বিনাশের রুট পাহারা দিত `decide` নিয়ম, আর সে খোলে কেবল **অপেক্ষমাণ** কাগজে — অথচ বিনাশ চলে কেবল
     * **বাতিল বা কোয়ারেন্টাইন** কাগজে। ফলে সবার জন্য ৪০৩: বাতিল মাল চিরকাল আটকে থাকত, বা লোকে ঘুরপথ
     * নিতেন (ছাড়ো, তারপর বের করো) — আর সেই মাঝের মুহূর্তে বাতিল মাল বিক্রয়যোগ্য।
     *
     * ⓘ চাবি একই (`inventory.qc.decide`) — বিনাশ রায়ের ধারাবাহিকতা, আলাদা ক্ষমতা নয়।
     */
    public function dispose(User $user, QualityInspection $document): bool
    {
        return $user->can('inventory.qc.decide') && in_array($document->status, [
            QualityInspection::REJECTED,
            QualityInspection::QUARANTINE,
        ], true);
    }
}
