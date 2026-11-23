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

        $this->assertGreaterThanOrEqual(10, $seen, 'বার্তার লেখার দরজা খুঁজে পাওয়া যায়নি — রুটের নাম বদলেছে?');
        $this->assertSame([], $missing, '⛔ "সব শাখা"-তে এই বার্তার দরজাগুলো থামবে: '.implode(', ', $missing));
    }

    /**
     * ⭐ মালিক, ২৩ নভেম্বর ২০২৬ ("ok"): ব্যবহারকারী, ভূমিকা, সেটিংস — গোটা কোম্পানির প্রশাসন, কোনো শাখার কাগজ নয় — "সব শাখা"-তেও
     * লেখা যায়। ⛔ পুরনো খাতা থেকে আনা শাখার কাগজ লেখে, তাই বন্ধই; আর ব্যবসার কাগজ (আদায় ইত্যাদি) আগের মতোই বন্ধ।
     */
    public function test_company_administration_is_open_but_imports_and_business_papers_stay_closed(): void
    {
        foreach (['system_admin.user.store', 'system_admin.user.update', 'system_admin.role.store', 'system_admin.settings.update'] as $open) {
            $this->assertNotNull(app('router')->getRoutes()->getByName($open), "no such route {$open}");
            $this->assertTrue(AllBranchesView::opens($open), "{$open} is still closed in the all-branches view");
        }

        $this->assertFalse(AllBranchesView::opens('system_admin.import.store'), 'an import writes branch papers — it must stay closed');
        $this->assertFalse(AllBranchesView::opens('sales.collection.store'), 'a business paper opened in the all-branches view');

        $patterns = app(AllBranchesView::class)->allowedPathPatterns();
        $this->assertTrue(collect($patterns)->contains(fn (string $p): bool => (bool) preg_match('#'.$p.'#', '/system/users')),
            'the page script would still grey the new-user button');
    }
}
