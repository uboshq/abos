<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Providers\ModuleServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * একটা পর্দা নতুন মডিউলে সরলেও তার রুটের নাম আগের মডিউলেরই থাকে — `Routes/web.<আশ্রয়দাতা>.php`
 * (পিওএস ধাপ ২, ১১ অক্টোবর ২০২৬)।
 *
 * ── ⛔ যা পাহারা দেওয়া হয় ────────────────────────────────────────────
 * ১. নামটা আশ্রয়দাতার কোড দিয়েই শুরু হয়, নতুন মডিউলের কোড দিয়ে নয় — নাহলে ফোনের অ্যাপ আর বুকমার্ক ভাঙত।
 * ২. controller খোঁজা হয় নতুন মডিউলের নামস্থানে।
 * ৩. যার উপর মডিউলটা দাঁড়ায় না তার নামে রুট বসানো যায় না — দুইটা মডিউল একই নাম দাবি করত, পরেরটা নীরবে জিতত।
 */
class ARouteKeepsTheNameOfTheModuleItLeftTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/borrowed-routes-'.getmypid());
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/web.host.php', "<?php\nRoute::get('/borrowed-route-claim', 'ScreenController@index')->name('screen.index');\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function register(array $dependsOn): void
    {
        $method = new ReflectionMethod(ModuleServiceProvider::class, 'registerBorrowedRouteNames');
        $method->invoke(new ModuleServiceProvider($this->app), $this->dir, 'guest', 'App\\Modules\\Guest', $dependsOn);
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_the_route_keeps_the_hosts_name_and_answers_from_the_guests_house(): void
    {
        $this->register(['host']);

        $route = Route::getRoutes()->getByName('host.screen.index');

        $this->assertNotNull($route, 'রুটটা আশ্রয়দাতার নামে বসেনি।');
        $this->assertNull(Route::getRoutes()->getByName('guest.host.screen.index'), 'নামের সামনে নতুন মডিউলের কোড বসে গেছে।');
        $this->assertSame('App\\Modules\\Guest\\Http\\Controllers\\ScreenController@index', $route->getActionName(),
            'controller নতুন মডিউলের ঘরে খোঁজা হচ্ছে না।');
        $this->assertContains('web', $route->gatherMiddleware());
    }

    public function test_a_module_cannot_take_the_name_of_one_it_does_not_stand_on(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'host' is not in its depends_on");

        $this->register(['someone_else']);
    }
}
