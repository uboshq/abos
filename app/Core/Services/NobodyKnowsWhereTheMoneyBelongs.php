<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\KnowsWhereAPersonsMoneyBelongs;

/**
 * কেউ কিছু বলেনি — আর সেটাও একটা বৈধ উত্তর।
 *
 * ── ⚠️ কেন একটা খালি বাস্তবায়ন লাগে ─────────────────────────────────
 * চুক্তিটা পূরণ করে Finance, আর Finance বন্ধ থাকতে পারে ([[ModuleRegistry]]
 * কেবল চালু মডিউলের `bindings` বাঁধে)। ⛔ তখন কনটেইনার চুক্তিটা মেটাতে
 * না পেরে ছুঁড়ে ফেলত, আর ভাউচারের পর্দাই ভাঙত — অর্থাৎ ঠিক সেই
 * নির্ভরতাটাই ফিরে আসত যেটা সরানো হলো, কেবল অন্য চেহারায়।
 *
 * ⓘ তাই কোর নিজেই একটা "জানি না" বেঁধে রাখে, আর মডিউল থাকলে সেটা
 * উপরে বসে যায়।
 */
final class NobodyKnowsWhereTheMoneyBelongs implements KnowsWhereAPersonsMoneyBelongs
{
    public function accountCodeFor(int $personId): ?string
    {
        return null;
    }

    /** @return list<string> */
    public function peopleItKnowsAbout(): array
    {
        return [];
    }
}
