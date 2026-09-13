<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Services;

use App\Modules\MasterData\Models\Person;
use Illuminate\Validation\ValidationException;

/**
 * "কে" ঘরটার উত্তর — তালিকা থেকে বাছা, অথবা এখনই যোগ করা।
 *
 * ── কেন এটা লাগল, ১৩ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * অর্থের পাঁচটা পর্দায় নাম টাইপ করা বন্ধ করে তালিকা থেকে বাছা হচ্ছে।
 * কিন্তু কেবল ড্রপডাউন দিলে কাজটা **কঠিন** হয়ে যেত: যিনি আজ প্রথমবার
 * মালিকের ভাইয়ের কাছ থেকে টাকা নিচ্ছেন, তাঁকে আগে মাস্টার ডাটার পর্দায়
 * গিয়ে সারিটা বসিয়ে তারপর ফিরে আসতে হত।
 *
 * ⛔ আর ফিরে এসে তিনি দেখতেন **আধা ভরা ফর্মটা নেই** — অঙ্ক, তারিখ,
 * বিবরণ সব আবার লিখতে হত। মানুষ দুইবার ওটা করেন, তৃতীয়বার থেকে অন্য
 * পথ খোঁজেন — আর তখন যেকোনো একটা পুরনো নাম বেছে টাকাটা ভুল মানুষের
 * নামে বসিয়ে দেন। কঠিন করে দেওয়া ব্যবস্থা ভুল ডেটাই বানায়।
 *
 * তাই দুইটা পথ, একটাই জমা: হয় তালিকা থেকে বাছুন, নয় নতুন নামটা এই
 * ফর্মেই লিখুন। দুইটার যেকোনো একটা হলেই চলে।
 *
 * ── ⚠️ তবু নতুন নামের ঘরটা "মুক্ত লেখা" নয়, আর পার্থক্যটা মূলে ───────
 * পুরনো নকশায় নামটা **যেখানে জমা হত** সেটাই ছিল মুক্ত লেখা — কোনো
 * পাহারা ছাড়া, আর প্রতিটা নতুন বানান নীরবে নতুন একজন মানুষ।
 *
 * এখানে লেখাটা কেবল **তৈরির পথ**, আর তৈরিটা যায় [[MasterListService]]-এর
 * ভেতর দিয়ে — অর্থাৎ নকল-পাহারা চলে। কেউ "Al Amin" থাকা অবস্থায়
 * "Al-Amin" লিখলে সেটা থেমে যায় আর বলে দেওয়া হয় নামটা আগে থেকেই আছে
 * ([[App\Core\Services\DuplicateGuard::normaliseName]] যতিচিহ্ন ও কেস
 * সরায়)। জেনেশুনে দুইজন রাখতে হলে তালিকার পর্দা থেকে, যেখানে
 * `allow_duplicate` টিক আছে আর সেই সিদ্ধান্ত অডিটে বসে।
 */
final class PersonResolver
{
    public function __construct(private readonly MasterListService $lists) {}

    /**
     * অনুরোধের ঘরগুলো থেকে একজন মানুষের আইডি।
     *
     * ⓘ `$data` থেকে `person_new` ও `person_mobile` **মুছে দেওয়া হয়** —
     * ওগুলো সিদ্ধান্তের ঘর, কোনো কাগজের কলাম নয়। রেখে দিলে নিচের
     * `create()`-গুলোর mass-assignment-এ পৌঁছে যেত, আর অচেনা কলাম পেয়ে
     * প্রতিটা জমা ভেঙে পড়ত।
     *
     * @param  array<string, mixed>  $data
     */
    public function resolve(array &$data): ?int
    {
        $picked = (int) ($data['person_id'] ?? 0);
        $typed = trim((string) ($data['person_new'] ?? ''));
        $mobile = trim((string) ($data['person_mobile'] ?? ''));

        unset($data['person_new'], $data['person_mobile']);

        if ($picked > 0) {
            /*
             * দুইটাই ভরা — বাছাইটাই জেতে, আর লেখাটা চুপচাপ ফেলে দেওয়া হয় না।
             *
             * ⚠️ ফেলে দিলে মানুষ ভাবতেন নতুন নামটা বসেছে, অথচ টাকাটা
             * বসত পুরনো কারো নামে। দুইটা ভিন্ন উত্তর পেলে প্রশ্ন করাই ঠিক।
             */
            if ($typed !== '') {
                throw ValidationException::withMessages([
                    'person_new' => __('finance::validation.person_pick_or_type'),
                ]);
            }

            return $picked;
        }

        if ($typed === '') {
            return null;
        }

        $person = $this->lists->create(
            Person::class,
            ['name_en' => $typed, 'mobile' => $mobile !== '' ? $mobile : null],
            'people',
        );

        return (int) $person->getKey();
    }
}
