<?php

declare(strict_types=1);

namespace Tests\Feature\Design;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * হোম = "ব্যবসার কমান্ড সেন্টার" — মালিকের নকশা, ১ অক্টোবর ২০২৬।
 *
 * ⭐ মাথার সারিতে বাঁয়ে শিরোনাম, ডানে "হাতে ও ব্যাংকে মোট" কার্ড (হাতে নগদ · MFS · ব্যাংক · পথে)।
 * ⓘ টাকার অবস্থান কোনো কালপর্বের নয় — আজ/মাস/বছর যেটাই বাছা হোক, কার্ডটা মাথায় থাকে।
 * ⛔ আর নিচের সারিতে কার্ডটা দ্বিতীয়বার আসে না: একই সংখ্যা দুই জায়গায় থাকলে মালিক ভাবেন দুইটা আলাদা টাকা।
 * ⚠️ যাঁর টাকার অনুমতি নেই, তাঁর মাথায় শুধু শিরোনাম — কার্ড নয়।
 */
final class TheMoneyPositionSitsAtTheHeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_sees_the_money_position_once_at_the_head_whatever_the_period(): void
    {
        [$owner] = $this->people();

        foreach (['today', 'month', 'year'] as $period) {
            $response = $this->actingAs($owner)->get(route('dashboard', ['period' => $period]))->assertOk();
            $html = (string) $response->getContent();

            $this->assertStringContainsString('data-command-head', $html, "{$period}: কমান্ড সেন্টারের মাথা নেই।");
            $this->assertStringContainsString(e(__('home.command_center')), $html, "{$period}: শিরোনাম নেই।");
            $this->assertSame(1, substr_count($html, 'data-money-position'), "{$period}: টাকার অবস্থানের কার্ড মাথায় একবার নেই।");

            $label = $this->positionLabel($response->viewData('groups'));
            $this->assertNotNull($label, 'মালিকের জন্য হিসাব মডিউল টাকার অবস্থান দেয়নি।');

            $head = $this->between($html, 'data-command-head', '</section>');
            $this->assertStringContainsString(e($label), $head, "{$period}: \"{$label}\" মাথার সারিতে নেই।");
            if (str_contains($html, 'data-period-cards')) {
                $cards = $this->between($html, 'data-period-cards', '</section>');
                $this->assertStringNotContainsString(e($label), $cards,
                    "{$period}: \"{$label}\" নিচের কার্ডের সারিতেও বসে গেছে — একই টাকা দুই জায়গায়।");
            }
        }
    }

    public function test_someone_without_the_money_door_gets_the_heading_but_no_money_card(): void
    {
        [, $sales] = $this->people();

        $html = (string) $this->actingAs($sales)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-command-head', $html);
        $this->assertStringNotContainsString('data-money-position', $html, '⛔ টাকার অনুমতি ছাড়াই টাকার অবস্থান দেখা গেছে।');
    }

    /**
     * ⭐ ব্যবসার চিত্র — প্রতিটা মডিউলের প্রধান চার্ট হোমে, আর সেটা মডিউলের নিজের পর্দার প্রথম চার্টই।
     * ⛔ যে মডিউলের দরজা যাঁর জন্য বন্ধ, তাঁর হোমে সেই চার্ট নেই — বিক্রয়কর্মী হিসাবের চার্ট পান না।
     */
    public function test_each_module_lends_its_lead_chart_to_the_home_and_only_to_those_who_may_open_it(): void
    {
        [$owner, $sales] = $this->people();

        $response = $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $html = (string) $response->getContent();
        $rows = array_values(array_filter($response->viewData('overall'), fn (array $row) => $row['panel'] !== null));

        $this->assertNotEmpty($rows, 'মালিকের হোমে একটাও মডিউলের চার্ট নেই।');
        $this->assertStringContainsString('data-business-pictures', $html);

        $pictures = $this->between($html, 'data-business-pictures', '</section>');

        foreach ($rows as $row) {
            $door = route('module.dashboard', ['module' => $row['module']]);
            $this->assertStringContainsString('href="'.e($door).'"', $pictures, "\"{$row['module']}\"-এর চার্টে নিজের পর্দার দরজা নেই।");
            $this->assertStringContainsString(e($row['panel']->label), $pictures, "\"{$row['module']}\"-এর চার্টের নাম হোমে নেই।");

            $own = $this->actingAs($owner)->get($door)->assertOk()->viewData('dashboard');
            $this->assertSame($own->panels[0]->label, $row['panel']->label, "\"{$row['module']}\": হোমের চার্ট আর নিজের পর্দার প্রথম চার্ট আলাদা।");
        }

        $salesRows = $this->actingAs($sales)->get(route('dashboard'))->assertOk()->viewData('overall');
        $this->assertNotContains('accounts', array_column($salesRows, 'module'), '⛔ বিক্রয়কর্মী হিসাবের চার্ট পেয়েছেন।');
    }

    /**
     * ⭐ ব্যতিক্রম কেন্দ্র — যা আটকে আছে তার প্রতিটা একটা কার্ড, আর কার্ডে পদক্ষেপের দরজা।
     * ⛔ যেটার সংখ্যা শূন্য, সেটার কার্ড নেই — শুধু "বাকিগুলোতে কিছু নেই" এক লাইনে।
     */
    public function test_every_waiting_item_gets_a_card_with_a_door_in_the_exception_center(): void
    {
        [$owner] = $this->people();

        $response = $this->actingAs($owner)->get(route('dashboard'))->assertOk();
        $html = (string) $response->getContent();
        $todo = $response->viewData('groups')['todo'];

        $this->assertNotEmpty($todo);
        $this->assertStringContainsString('data-exception-center', $html);
        $center = $this->between($html, 'data-exception-center', '</section>');

        foreach ($todo as $widget) {
            $digits = (int) preg_replace('/\D/', '', strtr($widget->value, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
                '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']));

            if ($digits > 0) {
                $this->assertStringContainsString('href="'.e($widget->href).'"', $center, "\"{$widget->label}\" আটকে আছে, অথচ কার্ডে দরজা নেই।");
                $this->assertStringContainsString(e($widget->label), $center);
            } else {
                $this->assertStringNotContainsString('href="'.e($widget->href).'"', $center, "\"{$widget->label}\" শূন্য, তবু কার্ড বসেছে।");
            }
        }
    }

    /** @return array{0: User, 1: User} */
    private function people(): array
    {
        $this->seed(DemoSeeder::class);
        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        return [
            User::query()->where('email', 'owner@abos.test')->firstOrFail(),
            User::query()->where('email', 'sales@abos.test')->firstOrFail(),
        ];
    }

    /** @param array<string, list<\App\Core\Dashboard\Widget>> $groups */
    private function positionLabel(array $groups): ?string
    {
        foreach ($groups as $widgets) {
            foreach ($widgets as $widget) {
                if ($widget->tone === 'money' && $widget->parts !== []) {
                    return $widget->label;
                }
            }
        }

        return null;
    }

    private function between(string $html, string $from, string $to): string
    {
        $start = strpos($html, $from);
        $this->assertNotFalse($start);
        $end = strpos($html, $to, $start);

        return substr($html, $start, $end === false ? null : $end - $start);
    }
}
