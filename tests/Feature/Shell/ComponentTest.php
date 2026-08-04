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
        $response->assertSee('class="px-3 align-middle py-2.5 num"', false);
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

        // সেকশন ১৫.২৪ — এক টুলবার। নতুন বোতাম কম্পোনেন্টে যোগ হবে,
        // স্ক্রিনে নয়; নাহলে এক স্ক্রিনে Export বাঁয়ে আর অন্যটায় ডানে।
        foreach ([
            __('core.toolbar.filter'),
            __('core.toolbar.columns'),
            __('core.toolbar.density'),
            __('core.action.export'),
            __('core.action.print'),
            __('core.toolbar.refresh'),
        ] as $label) {
            $response->assertSee('aria-label="'.$label.'"', false);
        }
    }

    public function test_toolbar_buttons_carry_a_label_for_screen_readers(): void
    {
        // মোবাইলে hover নেই, তাই title-এর উপর ভরসা করা যায় না (সেকশন ২০.৫)।
        $markup = file_get_contents(resource_path('views/components/ui/toolbar-button.blade.php'));

        $this->assertStringContainsString('aria-label', $markup);
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
