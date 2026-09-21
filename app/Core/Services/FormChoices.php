<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\OffersChoicesOnAForm;
use App\Core\Module\ModuleRegistry;

/**
 * একটা ফর্মের জন্য সব মডিউলের দেওয়া তালিকাগুলো, এক জায়গায়।
 *
 * ── ⓘ কোর কারও নাম জানে না ──────────────────────────────────────────
 * কে কী দিচ্ছে সেটা আসে মডিউলের নিজের ঘোষণা থেকে (`module.php`-র
 * `form_choices`)। ⚠️ তাই নতুন মডিউল একটা ঘর যোগ করলে এই ফাইলটা ছুঁতে
 * হয় না — ঠিক যেভাবে মেনু, অনুমতি ও রিপোর্ট কাজ করে।
 *
 * ⛔ নামের সংঘাত হলে **পরের মডিউলটা জেতে**, আর সেটা নীরব। ⓘ বাস্তবে
 * হয় না, কারণ প্রতিটা নাম ঐ ফর্মের একটা নির্দিষ্ট ঘরের — দুইটা মডিউল
 * একই ঘর ভরতে এলে সেটা নকশার প্রশ্ন, কোডের নয়।
 */
final class FormChoices
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * @return array<string, mixed>
     */
    public function for(string $form): array
    {
        $all = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->formChoices as $class) {
                $giver = app($class);

                if (! $giver instanceof OffersChoicesOnAForm) {
                    continue;
                }

                foreach ($giver->choicesFor($form) as $name => $options) {
                    $all[$name] = $options;
                }
            }
        }

        return $all;
    }
}
