<?php

namespace Tests\Feature\Core;

use App\Core\Services\AllBranchesView;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * ⛔ মালিক, ২৩ নভেম্বর ২০২৬: "ওয়েবে বার্তা লেখা যায় না"। "সব শাখা" দেখা অবস্থায় সার্ভার প্রতিটা লেখার দরজা থামায়
 * ([[RefusesWritesInAllBranchesView]]), আর বার্তার দরজাগুলো ছাড়ের তালিকায় ছিল না — পাঠানো, নতুন কথোপকথন, পড়া, লিখছে-চিহ্ন সব
 * 409 পেত, তাই কথোপকথন খুলত না আর লেখার ঘর লুকানো থাকত (ডেমোতে Playwright দিয়ে ধরা)।
 *
 * বার্তা ও কল কোনো শাখার কাগজ বা হিসাব নয় — সহকর্মীর সাথে কথা। তাই ওয়েবের দুই রাস্তা আর ফোনের API-র প্রতিটা লেখার দরজা
 * "সব শাখা"-তেও খোলা থাকবে। ⓘ নতুন বার্তার দরজা যোগ হলে এই পরীক্ষা লাল হবে, যতক্ষণ না ছাড়ের তালিকায় ওঠে।
 */
class MessagesAreWrittenInTheAllBranchesViewTest extends TestCase
{
    public function test_every_messenger_write_door_stays_open_in_the_all_branches_view(): void
    {
        $missing = [];
        $seen = 0;

        /** @var Route $route */
        foreach (app('router')->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (! preg_match('/^(api\.)?messaging\./', $name)) {
                continue;
            }

            if (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) === []) {
                continue;
            }

            $seen++;

            if (! in_array($name, AllBranchesView::ALLOWED, true)) {
                $missing[] = $name;
            }
        }

        $this->assertGreaterThan(10, $seen, 'বার্তার লেখার দরজা খুঁজে পাওয়া যায়নি — রুটের নাম বদলেছে?');
        $this->assertSame([], $missing, '⛔ "সব শাখা"-তে এই বার্তার দরজাগুলো থামবে: '.implode(', ', $missing));
    }
}
