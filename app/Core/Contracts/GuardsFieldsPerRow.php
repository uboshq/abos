<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * ⭐ যে মডেলের একই ঘর কোনো সারিতে গোপন, কোনোটায় নয় — সারি নিজে বলে কোন চাবি লাগে (a6, ২১ অক্টোবর ২০২৬; fe-র উত্তর খ)।
 *
 * ⓘ [[FieldSecurity]]-এর ঘোষণা (`sensitive_fields`, `section_guarded_fields`) ক্লাস ধরে — ক্লাসের প্রতিটা সারিতে একই নিয়ম।
 * অনুমোদনের অনুরোধের `amount` তা নয়: বেতনের রানে নিট (`hr.salary.view`), বিক্রির ছাড়ে খোলা অঙ্ক। ⓘ পড়ার মুহূর্তে জিজ্ঞেস করা হয়,
 * তাই ঢাকনা পড়ার আগে লেখা অডিট-সারিও ঢাকা।
 */
interface GuardsFieldsPerRow
{
    /** ⓘ এই সারির এই ঘর কোন অনুমতির পেছনে — `null` মানে খোলা। */
    public static function fieldPermissionFor(int $id, string $field): ?string;
}
