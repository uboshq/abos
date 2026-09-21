<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\TurnsATypedNameIntoAParty;

/**
 * হাতে লেখা নাম থেকে সারি বানানোর কেউ নেই — আর সেটাও একটা বৈধ উত্তর।
 *
 * ── ⚠️ কেন একটা "পারি না" বাস্তবায়ন লাগে ───────────────────────────
 * চুক্তিটা সত্যিকারে পূরণ করে MasterData, আর সে বন্ধ থাকতে পারে
 * ([[ModuleRegistry]] কেবল চালু মডিউলের `bindings` বাঁধে)। ⛔ তখন
 * কনটেইনার চুক্তিটা মেটাতে না পেরে ছুঁড়ে ফেলত, আর **ভাউচারের যাচাইই
 * ভাঙত** — অর্থাৎ ঠিক সেই নির্ভরতাটা ফিরে আসত যেটা সরানো হলো, কেবল
 * অন্য চেহারায়।
 *
 * ⓘ `null` ফেরানোর মানে: হাতে লেখা নামটা সারি হয় না, তাই ব্যবহারকারী
 * তালিকা থেকে একজনকে বাছবেন। ⚠️ কাজটা কঠিন হয়, কিন্তু পর্দা ভাঙে না।
 */
final class NobodyCanMakeAParty implements TurnsATypedNameIntoAParty
{
    public function fromTypedName(string $name, ?string $mobile = null): ?int
    {
        return null;
    }

    public function partyType(): string
    {
        return 'person';
    }
}
