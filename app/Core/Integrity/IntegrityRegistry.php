<?php

declare(strict_types=1);

namespace App\Core\Integrity;

use App\Core\Module\ModuleRegistry;
use App\Models\User;
use InvalidArgumentException;

/**
 * সব মডিউলের যাচাইগুলো এক জায়গায়।
 *
 * ── কেন এটা লাগল ────────────────────────────────────────────────────
 * ABOS-এর পরীক্ষাগুলো ধরে রাখে **কোড ঠিক আছে কি না**। কিন্তু চালু
 * খাতায় গরমিল ঢোকার পথ আরও আছে: হাতে চালানো SQL, অসম্পূর্ণ মাইগ্রেশন,
 * আধেক লেখা একটা ট্রানজেকশন, বা এমন একটা বাগ যেটা সারানোর আগেই কিছু
 * সারি লিখে ফেলেছে। সারানো কোড পুরনো সারিগুলোকে ঠিক করে দেয় না।
 *
 * তাই দুইটা আলাদা জিনিস দরকার: পরীক্ষা বলে কোডটা ঠিক, আর এই যাচাইটা
 * বলে **এই কোম্পানির আজকের খাতাটা** ঠিক।
 */
class IntegrityRegistry
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * এই ব্যবহারকারী যেগুলো চালাতে পারেন।
     *
     * ── কেন অনুমতি এখানে ছাঁকা হয়, প্রতিটা যাচাইয়ে নয় ───────────────
     * যাচাইয়ের ফল মানে ভাঙা কাগজগুলোর নাম-ধাম — কোন বিলে কত গরমিল।
     * সেটা সবার দেখার জিনিস নয়। প্রতিটা সরবরাহকারীকে নিজে ছাঁকতে
     * বললে একদিন কেউ ভুলত, আর তখন তালিকাটা খুলে যেত।
     *
     * @return list<IntegrityCheck>
     */
    public function forUser(?User $user): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (IntegrityCheck $check) => $user?->can($check->permission) === true,
        ));
    }

    /**
     * সবগুলো — চাবি ধরে।
     *
     * দুই মডিউল একই চাবি দাবি করলে চুপচাপ একটা আরেকটাকে চাপা দিলে
     * একটা যাচাই নীরবে চলা বন্ধ করত, আর "সব সবুজ" দেখাত।
     *
     * @return array<string, IntegrityCheck>
     */
    public function all(): array
    {
        $checks = [];
        $owner = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->integrity as $provider) {
                foreach ($provider::checks() as $check) {
                    if (isset($checks[$check->key])) {
                        throw new InvalidArgumentException(
                            "Two modules declare the integrity check '{$check->key}': "
                            ."{$owner[$check->key]} and {$module->code}."
                        );
                    }

                    $checks[$check->key] = $check;
                    $owner[$check->key] = $module->code;
                }
            }
        }

        return $checks;
    }
}
