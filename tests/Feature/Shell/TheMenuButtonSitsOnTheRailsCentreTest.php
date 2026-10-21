<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐ "≡"-এর মাঝ = রেলের আইকনের মাঝ (মালিকের ছবি, fe-র মাধ্যমে, ২১ অক্টোবর ২০২৬: বোতামটা অর্ধেক রেলে, অর্ধেক বারে)।
 *
 * ⓘ মাপা হয় গড়ন দিয়ে, পিক্সেল দিয়ে নয় — এই রিপোর ডিজাইন-দাবির রীতি: দুইটাই x = 0 থেকে শুরু, দুইটারই প্রস্থ একই
 * `--spacing-sidebar-icon` টোকেন, দুইটারই একই `border-e`, আর দুইটাই ভেতরের জিনিস মাঝখানে বসায়। তাহলে মাঝ দুইটা এক —
 * যে রূপ রেলের প্রস্থ যা-ই বসাক। (ব্রাউজারে মাপা: ১৩৬৬ ও ১৯২০-এ দুইটাই x = ২৮.৫px; cb/brand-logo-র কমিটে লেখা।)
 *
 * দাবি, আসল পাতায় (ABOS রূপ, পুরো চওড়া বার):
 *  - বারের প্রথম জিনিস "≡"-এর ঘর, আর বারের বাঁয়ে কোনো ফাঁক নেই (`md:ps-0`) — ঘরটা x = 0 থেকে;
 *  - রেলের `aside` পাতার সারির প্রথম জিনিস — রেলও x = 0 থেকে;
 *  - ঘর আর রেল: একই প্রস্থের টোকেন, একই `border-e`, ভেতরে মাঝখানে (ঘর `justify-center`, রেল `items-center`, টাইল `w-full justify-center`);
 *  - বোতাম ৪০×৪০, গোল, নাম "মেনু", কি-বোর্ডে দেখা যায় এমন ফোকাস-দাগ, আর এটাই রেল গুটানো-খোলার সুইচ।
 */
final class TheMenuButtonSitsOnTheRailsCentreTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_menu_button_shares_the_rail_icons_centre(): void
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);
        $owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $owner->forceFill(['ui' => 'navy'])->save();

        $html = (string) $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk()->getContent();

        // ── বার: x = 0 থেকে, প্রথম জিনিস "≡"-এর ঘর ──
        preg_match('/<header data-topbar="full"[^>]*class="([^"]*)"[^>]*>(.*?)<\/header>/s', $html, $bar);
        $this->assertNotEmpty($bar, 'প্রস্তুতিটাই ভুল — পুরো চওড়া বার নেই।');
        $this->assertMatchesRegularExpression('/(^|\s)md:ps-0(\s|$)/', $bar[1], '⛔ বারের বাঁয়ে ফাঁক — "≡"-এর ঘর রেলের কিনারা থেকে সরে যায়।');
        preg_match('/<([a-z]+)\b[^>]*>/', $bar[2], $firstChild);
        $this->assertStringContainsString('data-menu-cell', $firstChild[0] ?? '', '⛔ বারের প্রথম জিনিস "≡"-এর ঘর নয়।');

        preg_match('/<div data-menu-cell\s+class="([^"]*)"/', $bar[2], $cell);
        $this->assertNotEmpty($cell, '⛔ "≡"-এর ঘর নেই।');
        $cellClasses = preg_split('/\s+/', trim($cell[1])) ?: [];

        // ── রেল: পাতার সারির প্রথম জিনিস, তার প্রথম কলাম আইকনের `nav` ──
        $row = substr($html, (int) strpos($html, '</header>'));
        $row = substr($row, (int) strpos($row, '<div class="flex flex-1'));
        preg_match('/<div class="flex flex-1[^"]*">\s*<([a-z]+)\b/', $row, $rowFirst);
        $this->assertSame('aside', $rowFirst[1] ?? null, '⛔ রেল পাতার সারির প্রথমে নেই — x = 0 থেকে শুরু নয়।');

        $aside = substr($row, (int) strpos($row, '<aside'));
        preg_match('/<nav class="([^"]*)"\s+aria-label="'.preg_quote(e(__('core.a11y.module_navigation')), '/').'"/', $aside, $nav);
        $this->assertNotEmpty($nav, 'প্রস্তুতিটাই ভুল — রেলের আইকন-কলাম নেই।');
        $railClasses = preg_split('/\s+/', trim($nav[1])) ?: [];

        foreach (['w-(--spacing-sidebar-icon)', 'border-e'] as $same) {
            $this->assertContains($same, $cellClasses, "⛔ \"≡\"-এর ঘরে {$same} নেই — রেলের মাপ নয়।");
            $this->assertContains($same, $railClasses, "⛔ রেলে {$same} নেই — দুই মাপ আলাদা হয়ে গেছে।");
        }
        $this->assertContains('border-transparent', $cellClasses, '⛔ ঘরের ধার দেখা যায় — বারে একটা খাড়া দাগ।');
        $this->assertContains('justify-center', $cellClasses, '⛔ "≡" ঘরের মাঝখানে নয়।');
        $this->assertContains('items-center', $railClasses, '⛔ রেলের আইকন কলামের মাঝখানে নয়।');

        // ⓘ রেলের টাইল: পুরো চওড়া, ভেতরে মাঝখানে — হোমের চিহ্ন আর মডিউলের টাইল দুইটাই
        $tile = substr($aside, (int) strpos($aside, 'data-rail-home'));
        preg_match('/class="([^"]*)"/', $tile, $tileClass);
        $tileClasses = preg_split('/\s+/', trim($tileClass[1] ?? '')) ?: [];
        $this->assertContains('w-full', $tileClasses, '⛔ রেলের টাইল কলাম জোড়া নয়।');
        $this->assertContains('justify-center', $tileClasses, '⛔ রেলের টাইল মাঝখানে নয়।');

        // ── বোতাম ──
        $button = $this->between($bar[2], '<button', '</button>');
        foreach (['size-[40px]', 'rounded-full', 'focus-visible:outline-2', 'hover:bg-(--color-topbar-hover)'] as $class) {
            $this->assertStringContainsString($class, $button, "⛔ \"≡\" বোতামে {$class} নেই।");
        }
        $this->assertStringContainsString('aria-label="'.__('core.a11y.menu').'"', $button, '⛔ "≡"-এর নাম "মেনু" নয়।');
        $this->assertStringContainsString('@click="$store.sidebar.toggle()"', $button, '⛔ "≡" রেল গুটানো-খোলার সুইচ নয়।');
        $this->assertStringContainsString('--topbar-control-h: 40px', $button, '⛔ বারের ৩৬px নিয়ম বোতামটাকে ৪০px হতে দেয় না।');
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start, "প্রস্তুতিটাই ভুল — {$from} নেই।");
        $end = strpos($html, $to, $start + strlen($from));

        return substr($html, $start, $end === false ? null : $end - $start);
    }
}
