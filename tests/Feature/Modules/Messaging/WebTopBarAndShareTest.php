<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Messaging;

use App\Core\Services\PhonePaperDoor;
use App\Core\Support\CompanyContext;
use App\Core\Support\RowMenu;
use App\Models\Company;
use App\Models\User;
use App\Modules\Messaging\Support\MessengerChrome;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The messenger on every ABOS page (web sample M1UddRxK v7): the top-bar chat icon, the light poll door, the
 * incoming-call popup / call screen / share dialog, and "বার্তায় পাঠান" in a paper's ⋯ row menu.
 *
 * দাবি:
 *   ১. তিন ABOS রূপে (rose · navy · blue) `messaging.use` থাকলে টপবারে চ্যাট (ঘণ্টার আগে), আর পাতার শেষে খোলস-অংশ
 *   ২. SAP (`tiles`) আর Dynamic-এ কিছুই আঁকা হয় না — একটা `msgr` চিহ্নও নয় (মালিক: ওই দুই রূপ ১০০% আসল)
 *   ৩. চাবি ছাড়া ABOS রূপেও কিছু নেই
 *   ৪. হালকা জিজ্ঞাসার দরজা {unread_total, ringing} দেয়
 *   ৫. ফোনের ছাপার দরজা যে কাগজ চেনে, তার ⋯ মেনুতে "বার্তায় পাঠান" — type = মডেলের নাম, id = public_id; SAP-এ নয়
 *   ৬. call.php — bn আর en-এ একই চাবি
 */
final class WebTopBarAndShareTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);
        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);
    }

    public function test_each_abos_look_draws_the_chat_icon_before_the_bell_and_the_chrome(): void
    {
        foreach (MessengerChrome::LOOKS as $look) {
            $html = $this->pageIn($this->owner, $look);

            $this->assertStringContainsString('data-msgr-topbar', $html, "⛔ {$look}: টপবারে চ্যাট নেই");
            $this->assertStringContainsString('href="'.route('messaging.index').'"', $html, "⛔ {$look}: চ্যাট বার্তার পাতায় যায় না");
            $this->assertStringContainsString('data-msgr-root', $html, "⛔ {$look}: কল আসার পপআপ / কলের পর্দা নেই");
            $this->assertStringContainsString('data-msgr-incoming', $html);
            $this->assertStringContainsString('data-msgr-call', $html);
            $this->assertStringContainsString('data-msgr-share-dialog', $html);

            // ⓘ ঘণ্টার আগে — নমুনার ক্রম
            $chat = strpos($html, 'data-msgr-topbar');
            $bell = strpos($html, 'data-notify-bell-root');
            $this->assertNotFalse($bell, "⛔ {$look}: ঘণ্টা নেই");
            $this->assertLessThan($bell, $chat, "⛔ {$look}: চ্যাট ঘণ্টার পরে বসেছে");

            // ⓘ ঠিকানাগুলো খোলসে — জিজ্ঞাসা আর কলের দরজা
            $this->assertStringContainsString(e(json_encode(route('messaging.x.unread'), JSON_UNESCAPED_SLASHES)), $html);
        }
    }

    public function test_sap_and_dynamic_draw_nothing_of_the_messenger(): void
    {
        foreach (['tiles', 'dynamic'] as $look) {
            $html = $this->pageIn($this->owner, $look);

            $this->assertStringNotContainsString('data-msgr-', $html, "⛔ {$look}: বার্তার কিছু আঁকা হয়েছে — এই রূপ ১০০% আসল থাকার কথা");
            $this->assertStringNotContainsString('msgr-', $html, "⛔ {$look}: বার্তার ক্লাস পাতায় এসেছে");
        }
    }

    /** ⭐ owner, 24 Nov 2026 — no key to hand out: every active account gets the messenger's pieces in the ABOS looks */
    public function test_every_active_account_gets_the_messenger_in_an_abos_look(): void
    {
        $plain = $this->person('No Messenger', withKey: false);

        foreach (MessengerChrome::LOOKS as $look) {
            $html = $this->pageIn($plain, $look);
            $this->assertStringContainsString('data-msgr-topbar', $html, "⛔ {$look}: চাবি ছাড়া সক্রিয় মানুষ বার্তা পান না");
        }
    }

    public function test_the_light_poll_door_answers_the_badge_and_the_ringing_call(): void
    {
        $talker = $this->person('Poll Person', withKey: true);
        $this->actingAs($talker);

        $this->getJson(route('messaging.x.unread'))
            ->assertOk()
            ->assertJsonStructure(['unread_total', 'ringing'])
            ->assertJsonPath('ringing', null);

        // ⭐ owner, 24 Nov 2026 — open for every active account; a guest gets 401
        $this->actingAs($this->person('Keyless', withKey: false));
        $this->getJson(route('messaging.x.unread'))->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson(route('messaging.x.unread'))->assertUnauthorized();
    }

    public function test_a_printable_paper_row_menu_offers_send_in_a_message(): void
    {
        $paper = $this->printablePaper();
        $type = class_basename($paper);

        foreach (MessengerChrome::LOOKS as $look) {
            $html = $this->rowMenuIn($look, $paper);

            $this->assertStringContainsString('data-msgr-share', $html, "⛔ {$look}: ⋯ মেনুতে \"বার্তায় পাঠান\" নেই ({$type})");
            $this->assertStringContainsString(__('messaging::call.share.menu'), $html);

            preg_match('/data-msgr-share="([^"]*)"/', $html, $m);
            $info = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true);
            $this->assertSame($type, $info['type'] ?? null, '⛔ ধরন ফোনের ছাপার দরজার নাম নয়');
            $this->assertSame((string) $paper->getAttribute('public_id'), $info['id'] ?? null, '⛔ id কাগজের public_id নয়');
        }

        foreach (['tiles', 'dynamic'] as $look) {
            $this->assertStringNotContainsString('data-msgr-share', $this->rowMenuIn($look, $paper), "⛔ {$look}: মেনু বদলেছে");
        }
    }

    public function test_the_share_row_sits_in_the_print_and_send_group(): void
    {
        $share = ['label' => 'S', 'icon' => 'chat', 'share' => ['type' => 'X', 'id' => '1']];
        $items = [
            RowMenu::head('INV-1', 'ok'),
            RowMenu::group('look'),
            ['label' => 'view', 'url' => '/v'],
            RowMenu::group('paper'),
            ['label' => 'print', 'url' => '/p'],
            RowMenu::group('work'),
            ['label' => 'edit', 'url' => '/e'],
        ];

        $placed = MessengerChrome::placeIn($items, $share);
        $this->assertSame($share, $placed[5], '⛔ ছাপা ও পাঠানোর ভাগের শেষে বসেনি');
        $this->assertSame(RowMenu::group('work'), $placed[6]);

        // ⓘ ছাপার ভাগ না থাকলে (বাতিল কাগজ) — "কাজ"-এর আগে নতুন ভাগে
        $noPaper = [RowMenu::head('INV-1', 'x'), RowMenu::group('look'), ['label' => 'view', 'url' => '/v'], RowMenu::group('work'), ['label' => 'e', 'url' => '/e']];
        $placed = MessengerChrome::placeIn($noPaper, $share);
        $this->assertSame(RowMenu::group('paper'), $placed[3]);
        $this->assertSame($share, $placed[4]);
    }

    public function test_the_call_words_have_the_same_keys_in_both_languages(): void
    {
        $bn = array_keys(Arr::dot(require base_path('app/Modules/Messaging/Resources/lang/bn/call.php')));
        $en = array_keys(Arr::dot(require base_path('app/Modules/Messaging/Resources/lang/en/call.php')));
        sort($bn);
        sort($en);

        $this->assertSame($bn, $en);
    }

    public function test_every_icon_the_messenger_draws_is_in_the_set(): void
    {
        foreach (['chat', 'chat_fill', 'phone_fill', 'video', 'video_off', 'mic', 'mic_off', 'screen_share', 'speaker', 'send', 'paper', 'check', 'lock', 'people', 'x', 'search', 'phone'] as $name) {
            $this->assertNotSame('', trim(Blade::render('<x-ui.icon name="'.$name.'" />')), "⛔ আইকন \"{$name}\" সেটে নেই");
        }
    }

    private function pageIn(User $user, string $look): string
    {
        $user->forceFill(['ui' => $look])->save();
        $this->app['auth']->forgetGuards();

        return (string) $this->actingAs($user->fresh())->get(route('dashboard'))->assertOk()->getContent();
    }

    private function rowMenuIn(string $look, Model $paper): string
    {
        $this->owner->forceFill(['ui' => $look])->save();
        $this->actingAs($this->owner->fresh());
        // ⓘ এক অনুরোধে উত্তর মনে রাখা হয় — রূপ বদলালে নতুন প্রশ্ন
        request()->attributes->remove('abos.messenger.chrome');

        return Blade::render('<x-ui.row-actions :items="$items" :label="$label" :paper="$paper" />', [
            'items' => [
                RowMenu::head('NO-1', 'ok'),
                RowMenu::group('look'),
                ['label' => 'view', 'icon' => 'eye', 'url' => '/v'],
                RowMenu::group('paper'),
                ['label' => 'print', 'icon' => 'printer', 'url' => '/p'],
            ],
            'label' => 'menu',
            'paper' => $paper,
        ]);
    }

    /** a paper the phone print door knows — a real row when the demo has one, else an unsaved one with a public_id */
    private function printablePaper(): Model
    {
        $types = app(PhonePaperDoor::class)->printable();
        $this->assertNotEmpty($types, '⛔ ফোনের ছাপার দরজা কোনো কাগজ চেনে না');

        foreach ($types as [, $class]) {
            try {
                $row = $class::query()->whereNotNull('public_id')->first();
            } catch (\Throwable) {
                $row = null;
            }
            if ($row instanceof Model) {
                return $row;
            }
        }

        [, $class] = reset($types);
        $paper = new $class;
        $paper->forceFill(['public_id' => 'test-public-id-1', 'document_no' => 'TEST-0001']);

        return $paper;
    }

    private function person(string $name, bool $withKey): User
    {
        $branch = $this->company->defaultBranch()?->id;
        $user = User::factory()->create(['name' => $name, 'current_company_id' => $this->company->id, 'current_branch_id' => $branch, 'is_active' => true]);
        $user->companies()->attach($this->company->id, ['is_active' => true]);
        if ($withKey) {
            CompanyContext::forCompany($this->company->id, fn () => $user->givePermissionTo(Permission::findOrCreate('messaging.use', 'web')));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }
}
