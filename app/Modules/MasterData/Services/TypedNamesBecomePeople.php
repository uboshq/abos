<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Services;

use App\Core\Contracts\TurnsATypedNameIntoAParty;

/**
 * হাতে লেখা একটা নাম → `mdm_people`-এর একটা সারি।
 *
 * ── ⓘ কেন এটা একটা মোড়ক, নতুন যুক্তি নয় ────────────────────────────
 * কাজটা আগে থেকেই [[PersonResolver]] করে, আর সেটাই একমাত্র জায়গা
 * যেখানে "একই মানুষ দুইবার বসবে না" নিয়মটা লেখা। ⚠️ এখানে ঐ যুক্তি
 * আবার লিখলে একদিন দুইটা আলাদা নিয়ম হত, আর একই নাম দুই পর্দায় দুই
 * রকম আচরণ করত।
 *
 * ⭐ এই শ্রেণির একমাত্র কাজ সীমানা পার করানো: Accounts চুক্তিটা চায়,
 * MasterData সেটা পূরণ করে ([[TurnsATypedNameIntoAParty]])।
 */
final class TypedNamesBecomePeople implements TurnsATypedNameIntoAParty
{
    public function __construct(private readonly PersonResolver $people) {}

    public function fromTypedName(string $name, ?string $mobile = null): ?int
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        /*
         * ⚠️ `resolve()` ঘরগুলো **রেফারেন্সে** নেয় আর নিজের মতো বদলায়,
         * তাই একটা নিজের অ্যারে দেওয়া হয় — ডাকনেওয়ালার তথ্য ছোঁয়া হয় না।
         */
        $fields = [
            'person_new' => $name,
            'person_mobile' => trim((string) $mobile),
        ];

        return $this->people->resolve($fields);
    }

    public function partyType(): string
    {
        return 'person';
    }
}
