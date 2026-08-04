<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\Accent;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * চেহারা — ব্যবহারকারীর নিজের রং, থিম ও ভাষা।
 *
 * DMS-এর একই নকশা: নির্দিষ্ট তালিকা থেকে বাছাই, ব্যক্তিপ্রতি, আর প্রতিটা
 * রঙের কনট্রাস্ট আগেই যাচাই করা।
 */
class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    private function owner(): User
    {
        return User::query()->where('email', 'owner@abos.test')->firstOrFail();
    }

    /** WCAG-এর সূত্র — DesignTokenTest-এর মতোই। */
    private function contrast(string $rgbTriplet, array $against): float
    {
        $luminance = function (array $channels): float {
            $linear = array_map(
                fn (float $c): float => ($c /= 255) <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4,
                $channels,
            );

            return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
        };

        $l1 = $luminance(array_map('floatval', explode(' ', $rgbTriplet)));
        $l2 = $luminance($against);

        [$light, $dark] = $l1 > $l2 ? [$l1, $l2] : [$l2, $l1];

        return ($light + 0.05) / ($dark + 0.05);
    }

    public function test_every_offered_colour_keeps_white_button_text_readable(): void
    {
        foreach (Accent::all() as $key => $accent) {
            foreach (['600', '700'] as $step) {
                $ratio = $this->contrast($accent['scale'][$step], [255, 255, 255]);

                // এটাই নির্দিষ্ট তালিকা রাখার কারণ। মুক্ত পিকারে কেউ এমন
                // হলুদ বাছত যাতে সাদা লেখা মিলিয়ে যায় — আর যে বোতামটা
                // তখন পড়া যেত না, সেটাই ইনভয়েস সেভ করার বোতাম।
                $this->assertGreaterThanOrEqual(
                    4.5,
                    $ratio,
                    sprintf('%s-%s is %.2f:1 against white text.', $key, $step, $ratio),
                );
            }
        }
    }

    public function test_the_colour_is_applied_on_the_server_not_in_javascript(): void
    {
        $this->owner()->forceFill(['accent' => 'emerald'])->save();

        $response = $this->actingAs($this->owner()->fresh())->get('/');

        // <html>-এ বসে, তাই পাতাটা প্রথম আঁকাতেই ঠিক রঙে। JS-এ করলে
        // ডিফল্ট রঙে এঁকে তারপর বদলাত — প্রতিটা লোডে একবার ঝলকানি।
        //
        // মানটা হাতে লেখা নয়, তালিকা থেকেই নেওয়া: হাতে লিখলে রংটা এক ধাপ
        // গাঢ় করার সাথে সাথে টেস্টটাও ভাঙত, অথচ আচরণ ঠিকই থাকত।
        $expected = '--accent-600:'.Accent::get('emerald')['scale']['600'].';';

        $response->assertSee($expected, false);
    }

    public function test_the_choice_is_remembered_on_the_user_not_the_browser(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)->post('/appearance', [
            'accent' => 'violet',
            'theme' => 'dark',
            'locale' => 'en',
        ])->assertRedirect();

        // রেকর্ডে, সেশনে নয় — এক ডিপোর একটা কম্পিউটারে দিনে তিনজন বসে,
        // আর সকালের অপারেটরের পছন্দ সন্ধ্যারজনকে স্বাগত জানানো উচিত নয়।
        $fresh = $owner->fresh();
        $this->assertSame('violet', $fresh->accent);
        $this->assertSame('dark', $fresh->theme);
        $this->assertSame('en', $fresh->locale);
    }

    public function test_a_colour_outside_the_list_is_refused(): void
    {
        $this->actingAs($this->owner())
            ->post('/appearance', ['accent' => '#ffff00', 'theme' => 'light', 'locale' => 'bn'])
            ->assertSessionHasErrors('accent');

        $this->assertSame('blue', $this->owner()->fresh()->accent);
    }

    public function test_two_people_can_have_different_colours(): void
    {
        $owner = $this->owner();
        $salesman = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        $owner->forceFill(['accent' => 'teal'])->save();
        $salesman->forceFill(['accent' => 'violet'])->save();

        $this->assertSame('teal', $owner->fresh()->accent);
        $this->assertSame('violet', $salesman->fresh()->accent);
    }

    public function test_the_screen_lists_every_colour_in_both_languages(): void
    {
        $response = $this->actingAs($this->owner())->get('/appearance');

        $response->assertOk();

        foreach (Accent::all() as $accent) {
            foreach (['bn', 'en'] as $locale) {
                $this->assertNotSame(
                    $accent['label'],
                    __($accent['label'], [], $locale),
                    "Missing {$locale} label for {$accent['label']}.",
                );
            }
        }
    }

    public function test_an_unknown_key_falls_back_rather_than_breaking_the_page(): void
    {
        // ডাটাবেজে হাতে বসানো ভুল মান, বা তালিকা থেকে সরানো একটা রং —
        // পাতাটা ভেঙে পড়ার চেয়ে ডিফল্টে ফেরা ভালো।
        $style = Accent::styleFor('a-colour-that-was-removed');

        $this->assertStringContainsString('--accent-600:', $style);
    }
}
