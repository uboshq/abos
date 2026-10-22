<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Models\User;

/**
 * ⭐ সইয়ের অপেক্ষায়, অথচ অনুমোদনের ছকের বাইরে — অনুমোদনের বাক্সে দেখানোর জন্য (নকশা দফা #৬, ২১ অক্টোবর ২০২৬)।
 *
 * ⓘ কিছু সই সবসময় দ্বিতীয় একজনের, ছক বন্ধ থাকলেও (যেমন সরবরাহকারীর ব্যাংক তথ্যের বদল); সেগুলো `approvals` সারি নয়,
 * তাই ইঞ্জিনের `pendingQueryFor()` ওগুলো চেনে না। ⭐ যে মডিউল এমন সই রাখে সে `module.php`-র `bindings`-এ এটা দেয়;
 * কোর কারো নাম জানে না, আর মডিউল না থাকলে খালি ([[NoSignaturesWaitingElsewhere]])।
 *
 * ⛔ প্রতিটা সারি কেবল তাঁর, যিনি সত্যিই সই দিতে পারেন — চাবি, নাগাল, আর যিনি চেয়েছেন তিনি নন।
 */
interface SignaturesWaitingElsewhere
{
    /**
     * @return list<array{kind: string, title: string, detail: string, requested_by: string, requested_at: ?\DateTimeInterface, url: string}>
     */
    public function waitingFor(User $user): array;
}
