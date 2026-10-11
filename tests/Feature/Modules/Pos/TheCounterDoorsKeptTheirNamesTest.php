<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Pos;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * কাউন্টারের কোড পিওএস-এ এসেছে, কিন্তু প্রতিটা দরজার নাম, ঠিকানা আর পাহারা হুবহু আগেরটাই (পিওএস ধাপ ২,
 * ১১ অক্টোবর ২০২৬; docs/cloud-tasks/pos-module.md §০.১)।
 *
 * ── ⛔ কেন পুরো তালিকাটা হাতে লেখা ───────────────────────────────────
 * ফোনের অ্যাপ নাম ধরে ডাকে, বুকমার্ক ঠিকানা ধরে, আর চাবি না মিললে ক্যাশিয়ার সকালে কাউন্টার খুলতে পারতেন
 * না। ⓘ তালিকাটা ধাপ ২-এর আগের `route:list` থেকে নেওয়া — একটা সারিও বদলালে এই দাবি লাল হয়।
 *
 * ⭐ আর প্রতিটা দরজা এখন পিওএস-এর controller-এ যায় — অর্থাৎ ভিতরটা সত্যিই সরেছে, কেবল নামটা থেকে গেছে।
 */
class TheCounterDoorsKeptTheirNamesTest extends TestCase
{
    /** নাম → [পদ্ধতি, ঠিকানা, পাহারা] — ধাপ ২-এর আগের হুবহু রূপ */
    private const DOORS = [
        'sales.pos.index' => ['GET|HEAD', 'sales/pos', ['web', 'auth', 'can:sales.pos']],
        'sales.pos.checkout' => ['POST', 'sales/pos', ['web', 'auth', 'can:sales.pos']],
        'sales.pos.lookup' => ['GET|HEAD', 'sales/pos/lookup', ['web', 'auth', 'can:sales.pos']],
        'sales.pos.park' => ['POST', 'sales/pos/park', ['web', 'auth', 'can:sales.pos']],
        'sales.pos.resume' => ['POST', 'sales/pos/{invoice}/resume', ['web', 'auth', 'can:sales.pos']],
        'sales.pos.bill' => ['GET|HEAD', 'sales/pos/bill', ['web', 'auth', 'can:sales.pos']],
        'sales.pos.return' => ['POST', 'sales/pos/return', ['web', 'auth', 'can:sales.pos', 'can:sales.return.create']],
        'sales.shift.index' => ['GET|HEAD', 'sales/shifts', ['web', 'auth', 'can:sales.pos']],
        'sales.shift.open' => ['POST', 'sales/shifts', ['web', 'auth', 'can:sales.pos']],
        'sales.shift.close' => ['POST', 'sales/shifts/{shift}/close', ['web', 'auth', 'can:sales.pos']],
        'sales.shift.show' => ['GET|HEAD', 'sales/shifts/{shift}', ['web', 'auth', 'can:sales.pos']],
    ];

    public function test_every_counter_door_keeps_its_name_address_and_guard(): void
    {
        foreach (self::DOORS as $name => [$methods, $uri, $guards]) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route, "{$name} নামের দরজাটাই নেই — ফোনের অ্যাপ আর বুকমার্ক ভাঙবে।");
            $this->assertSame($methods, implode('|', $route->methods()), "{$name}-এর পদ্ধতি বদলে গেছে।");
            $this->assertSame($uri, $route->uri(), "{$name}-এর ঠিকানা বদলে গেছে।");
            $this->assertSame($guards, $this->guardsOf($route), "{$name}-এর পাহারা বদলে গেছে।");
        }
    }

    public function test_no_counter_door_was_added_or_lost(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter(fn (?string $name) => $name !== null
                && (str_starts_with($name, 'sales.pos.') || str_starts_with($name, 'sales.shift.')))
            ->sort()->values()->all();

        $expected = array_keys(self::DOORS);
        sort($expected);

        $this->assertSame($expected, $names, 'কাউন্টার বা শিফটের দরজা যোগ হয়েছে বা হারিয়েছে।');
    }

    public function test_every_counter_door_now_answers_from_the_pos_module(): void
    {
        foreach (array_keys(self::DOORS) as $name) {
            $this->assertStringStartsWith('App\\Modules\\Pos\\Http\\Controllers\\',
                Route::getRoutes()->getByName($name)->getActionName(),
                "{$name} এখনো বিক্রয়ের controller-এ যায় — কোডটা সরেনি।");
        }
    }

    /**
     * রুটের নিজের আর controller-এর `middleware()`-এর পাহারা, একসাথে।
     *
     * @return list<string>
     */
    private function guardsOf(\Illuminate\Routing\Route $route): array
    {
        return array_values($route->gatherMiddleware());
    }
}
