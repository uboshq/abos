<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Contracts\ProvidesDashboard;
use App\Core\Engines\Dashboard\DashboardDefinition;
use App\Core\Engines\Dashboard\DashboardEngine;
use App\Core\Engines\Dashboard\Stat;
use App\Core\Module\ModuleRegistry;
use Tests\TestCase;

/**
 * ড্যাশবোর্ডের মেনু সারি নেই মানে দরজা বন্ধ — হোমের "গোটা ব্যবসা" সারিতেও।
 *
 * ── ⛔ কী ভেঙেছিল (ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬) ──────────
 * [[DashboardEngine::permissionFor()]] সারি না পেলে `null` দেয়, আর তার
 * নিজের কথা: null মানে বন্ধ ([[ModuleDashboardController]] তাই করে)।
 * কিন্তু [[DashboardEngine::overall()]] লিখত `$gate !== null && …` —
 * অর্থাৎ সারি ভুলে যাওয়া নতুন মডিউলের প্রথম সংখ্যা আর চার্ট **সবার**
 * হোমে উঠত, অথচ তার নিজের পাতা সবার জন্য ৪০৩।
 */
final class ADashboardWithoutItsDoorRowShowedToEveryoneTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = storage_path('framework/testing/modules-'.uniqid());
        mkdir($this->tmp.DIRECTORY_SEPARATOR.'Ghost', 0777, true);

        file_put_contents($this->tmp.DIRECTORY_SEPARATOR.'Ghost'.DIRECTORY_SEPARATOR.'module.php', '<?php return '.var_export([
            'code' => 'ghost',
            'name' => ['en' => 'Ghost', 'bn' => 'ভূত'],
            'depends_on' => [],
            'nav' => ['section' => 'business', 'order' => 10],
            'dashboard' => GhostDashboard::class,
        ], true).';');
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp.DIRECTORY_SEPARATOR.'Ghost'.DIRECTORY_SEPARATOR.'module.php');
        @rmdir($this->tmp.DIRECTORY_SEPARATOR.'Ghost');
        @rmdir($this->tmp);

        parent::tearDown();
    }

    public function test_a_module_with_no_dashboard_menu_row_stays_off_the_overall_page(): void
    {
        $engine = new DashboardEngine(new ModuleRegistry($this->tmp));

        $this->assertNull($engine->permissionFor('ghost'), 'পরীক্ষার ছকটাই ভুল: মডিউলটার দরজার সারি আছে।');

        $this->assertSame([], array_column($engine->overall(null), 'module'),
            'দরজার সারি নেই, অথচ মডিউলের সংখ্যা হোমে সবার জন্য উঠেছে।');
    }
}

/** দরজার সারি ছাড়া একটা ড্যাশবোর্ড — প্রথম সংখ্যাটা কারো চাবি চায় না, তাই কেবল দরজাই তাকে আটকাতে পারে। */
final class GhostDashboard implements ProvidesDashboard
{
    public static function dashboard(): DashboardDefinition
    {
        return new DashboardDefinition(
            title: 'Ghost',
            subtitle: 'Ghost',
            stats: [new Stat(label: 'Ghost', value: '42', hint: 'ghost')],
        );
    }
}
