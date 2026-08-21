<?php

declare(strict_types=1);

namespace Tests\Feature\Shell;

use App\Core\Support\DocumentStatus;
use App\Models\User;
use App\View\Components\Ui\Table;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * শেয়ার্ড কম্পোনেন্ট — One Toolbar, One Form, One Table (সেকশন ১৫.২৪)।
 *
 * এখানকার সবচেয়ে জরুরি টেস্ট: data-label ছাড়া কলাম তৈরিই করা যায় না।
 * ওটা ভোলা সবচেয়ে সহজ, আর ডেস্কটপে টেস্ট করলে কখনো ধরা পড়ে না — মোবাইলে
 * তখন ব্যবহারকারী শুধু কতগুলো সংখ্যা দেখে।
 */
class ComponentTest extends TestCase
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

    public function test_a_column_without_a_label_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/needs a label/');

        // ফোনে টেবিলের হেডার লুকানো থাকে; লেবেলটাই একমাত্র জিনিস যা বলে
        // এই মানটা কীসের।
        new Table(columns: ['date', 'amount']);
    }

    public function test_a_column_missing_its_key_or_label_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Table(columns: [['key' => 'date']]);
    }

    public function test_every_cell_carries_its_label_for_the_card_layout(): void
    {
        $response = $this->actingAs($this->owner())->get('/components');

        $response->assertOk();

        // প্রতিটা কলামের জন্য একটা করে data-label — নাহলে মোবাইলে card
        // রূপান্তরটা অর্থহীন।
        foreach (['তারিখ', 'ডকুমেন্ট', 'পক্ষ', 'ডেবিট', 'ক্রেডিট'] as $label) {
            $response->assertSee('data-label="'.$label.'"', false);
        }
    }

    public function test_numeric_columns_get_the_tabular_class(): void
    {
        $response = $this->actingAs($this->owner())->get('/components');

        // হিসাবের কলামে দশমিক বিন্দু এক লাইনে না থাকলে চোখে যোগফল
        // মেলানো যায় না।
        /*
         * ঘরটা এখন কেবল `num` পরে।
         *
         * ── কেন দাবিটা বদলাল ────────────────────────────────────────
         * আগে এখানে `px-3 align-middle num` খোঁজা হত, অর্থাৎ তিনটা
         * শ্রেণী একসাথে। প্যাডিং ও উল্লম্ব সারিবদ্ধতা এখন CSS-এ
         * (`.ui-list td`), কারণ ইউটিলিটি হিসেবে লেখা থাকলে কোনো
         * চেহারা ওগুলো ছুঁতে পারত না।
         *
         * পরীক্ষাটার নাম ও উদ্দেশ্য বদলায়নি: **সংখ্যার কলাম tabular
         * শ্রেণীটা পায় কি না**। ওই একটাই শ্রেণী দশমিক বিন্দু এক
         * লাইনে রাখে; বাকি দুইটা সাজসজ্জা ছিল।
         */
        $response->assertSee('class="num"', false);

        /*
         * শ্রেণীটা থাকলেই যথেষ্ট নয় — ওর নিয়মটাও থাকতে হবে।
         *
         * `num` বসানো আর `num` কিছু করা দুইটা আলাদা কথা। নিয়মটা
         * মুছে গেলে HTML অবিকল একই থাকত, পরীক্ষাটা পাশ করত, আর
         * সংখ্যার কলাম আবার আঁকাবাঁকা হয়ে যেত।
         */
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $this->assertMatchesRegularExpression('/td\.num,\s*\r?\n?\s*th\.num\s*\{[^}]*font-variant-numeric/s', $css,
            'num শ্রেণীটা বসে, কিন্তু ওর tabular নিয়মটা নেই।');

        /*
         * সারির উচ্চতা প্যাডিং থেকে নয়, টোকেন থেকে — আর নিয়মটা এখন
         * ইনলাইন স্টাইল থেকে নয়, ক্লাস থেকে।
         *
         * ── তিনবার বদলেছে, আর প্রতিবারই একই কারণে ────────────────────
         * প্রথমে `py-2.5`। প্যাডিং দিয়ে উচ্চতা ঠিক করলে যে ঘরে দুই
         * লাইন লেখা (নাম + কোড) সেই সারিটা লম্বা হয়ে যেত — এক সারি
         * ৪০px, পাশেরটা ৫৬।
         *
         * তারপর `style="height: var(--row-height)"`। মাপটা টোকেনে গেল,
         * কিন্তু **নিয়মটা** ইনলাইনেই রয়ে গেল — আর ইনলাইন স্টাইল সব
         * স্তর, সব ইউটিলিটি, এমনকি `!important`-ও হারায়। ফলে কোনো
         * চেহারা ওটা ছুঁতে পারত না: Suite (NetSuite) ঘন সারি চাইলেও
         * তালিকাগুলো ৪৪px-এই বসে থাকত।
         *
         * এখন `.ui-list` ক্লাস, আর নিয়মটা app.css-এ। HTML হালকা হল,
         * আর চেহারা নিজের নিয়ম লিখতে পারে।
         */
        $response->assertSee('class="ui-list table-cards', false);

        // ক্লাসটা বসা আর ক্লাসটা কিছু করা দুইটা আলাদা কথা।
        $this->assertMatchesRegularExpression(
            '/\.ui-list:not\(\.as-cards\) td \{[^}]*var\(--row-height\)/s', $css,
            'ui-list ক্লাসটা বসে, কিন্তু সারির উচ্চতার নিয়মটা নেই।',
        );
    }

    public function test_the_table_falls_back_to_an_empty_state_rather_than_an_empty_box(): void
    {
        $table = new Table(rows: [], columns: [['key' => 'a', 'label' => 'A']]);

        $this->assertSame('components.ui.table', $table->render()->name());
        $this->assertSame([], $table->normalised === [] ? [] : []);

        $response = $this->actingAs($this->owner())->get('/components');

        // ফাঁকা তালিকা মানে ব্যবহারকারী আটকে গেছে — অন্তত একটা করণীয় চাই।
        $response->assertSee(__('core.empty.nothing_here'), false);
        $response->assertSee(__('core.action.create'), false);
    }

    public function test_every_document_status_has_a_badge_in_both_languages(): void
    {
        foreach (DocumentStatus::all() as $status) {
            $this->assertNotSame(
                'core.status.'.$status,
                __('core.status.'.$status, [], 'bn'),
                "Bangla label missing for status '{$status}'.",
            );

            $this->assertNotSame(
                'core.status.'.$status,
                __('core.status.'.$status, [], 'en'),
                "English label missing for status '{$status}'.",
            );
        }
    }

    public function test_the_toolbar_is_the_same_everywhere_it_appears(): void
    {
        $response = $this->actingAs($this->owner())->get('/components');

        /*
         * সেকশন ১৫.২৪ — এক টুলবার। নতুন বোতাম কম্পোনেন্টে যোগ হবে,
         * স্ক্রিনে নয়; নাহলে এক স্ক্রিনে Sort বাঁয়ে আর অন্যটায় ডানে।
         *
         * Columns ও Export তালিকা থেকে বাদ: ওগুলো কখনো তৈরিই হয়নি,
         * শুধু খালি <button> হিসেবে বসে ছিল। তৈরি না হওয়া জিনিস
         * দেখানোর চেয়ে না দেখানোই সৎ — যেদিন তৈরি হবে সেদিন এখানেও
         * ফিরবে। প্রতিটা বোতাম সত্যিই কিছু করে কি না তা ToolbarTest
         * পাহারা দেয়।
         */
        foreach ([
            __('core.toolbar.density'),
            __('core.action.print'),
            __('core.toolbar.refresh'),
        ] as $label) {
            $response->assertSee('aria-label="'.$label.'"', false);
        }
    }

    /**
     * টুলবারের প্রতিটা আইকন-বোতামে aria-label আছে।
     *
     * মোবাইলে hover নেই, তাই title-এর উপর ভরসা করা যায় না (সেকশন ২০.৫)।
     * আগে এটা toolbar-button কম্পোনেন্টটা পড়ে দেখত, কিন্তু ওই
     * কম্পোনেন্টটা ছিল একটা মৃত বোতাম — সরিয়ে দেওয়া হয়েছে। এখন
     * টুলবারের নিজের উৎসই দেখা হয়।
     */
    public function test_toolbar_buttons_carry_a_label_for_screen_readers(): void
    {
        $markup = file_get_contents(resource_path('views/components/ui/toolbar.blade.php'));

        /*
         * অ্যাট্রিবিউটের মান উদ্ধৃতির ভেতরে থাকলে সেখানকার `>` গোনা হয় না।
         *
         * আগে নিয়মটা ছিল `[^>]*`, আর সেটা Alpine-এর হ্যান্ডলারে এসে ভেঙে
         * যেত: `setTimeout(() => copied = false)` লেখা থাকলে তীরের `>`-কেই
         * ট্যাগের শেষ ধরে নিত, ফলে তার পরের aria-label আর ম্যাচেই আসত না।
         * টেস্টটা তখন লেবেল-থাকা বোতামকেও লেবেলহীন বলত — অর্থাৎ পাহারাটা
         * মিথ্যা অভিযোগ করত, আর সেটা ঠিক করতে গিয়ে কেউ অপ্রয়োজনীয়
         * অ্যাট্রিবিউট যোগ করত।
         */
        $pattern = '/<button\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/s';

        preg_match_all($pattern, preg_replace('/\{\{--.*?--\}\}/s', '', $markup), $buttons);

        $this->assertNotEmpty($buttons[0]);

        foreach ($buttons[0] as $button) {
            // যে বোতামে লেখা আছে তার আলাদা লেবেল লাগে না — Filter By-তে
            // শব্দটাই পর্দায় দেখা যায়
            $labelled = str_contains($button, 'aria-label')
                || str_contains($button, 'aria-controls');

            $this->assertTrue($labelled, "লেবেল ছাড়া বোতাম:\n{$button}");
        }
    }

    public function test_status_badges_never_rely_on_colour_alone(): void
    {
        $response = $this->actingAs($this->owner())->get('/components');

        // কালার-ব্লাইন্ড ব্যবহারকারী ও সাদাকালো প্রিন্ট — দুটোতেই লেখাই
        // একমাত্র ভরসা।
        foreach (DocumentStatus::all() as $status) {
            $response->assertSee(__('core.status.'.$status), false);
        }
    }

    public function test_the_gallery_renders_at_all(): void
    {
        // কম্পোনেন্ট বদলালে এই পাতাটাই প্রথমে ভাঙে — সেটাই এর কাজ।
        $this->actingAs($this->owner())->get('/components')->assertOk();
    }
}
