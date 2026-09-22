<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Core\Support\NotificationKinds;
use App\Models\Company;
use App\Models\Notification as Bell;
use App\Models\NotificationChoice;
use App\Models\User;
use App\Notifications\NewsByMail;
use Database\Seeders\DemoSeeder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * খবরটা ভবনের বাইরেও যায়।
 *
 * ── ⛔ ২২ সেপ্টেম্বর ২০২৬ পর্যন্ত যেত না ────────────────────────────
 * ABOS তিনটা চিঠি পাঠাত, তিনটাই অ্যাকাউন্ট নিয়ে (পাসওয়ার্ড, ইমেইল
 * বদল)। ⚠️ ব্যবসার একটা ঘটনাও নয় — অনুমোদন ফেরত এল, জমার মেয়াদ
 * ফুরাচ্ছে, সূচিমতো রিপোর্ট তৈরি — সবগুলোই কেবল ঘণ্টায় একটা সারি।
 *
 * ⓘ সবচেয়ে ধারালো উদাহরণ সূচিমতো রিপোর্ট: **সূচির গোটা মানেই** হলো
 * কেউ না চাইতেই জিনিসটা এসে পৌঁছাবে, অথচ ওটা private ডিস্কে বসে
 * থাকত কেউ খুঁজতে আসার অপেক্ষায়।
 *
 * ── ⭐ এই ফাইলের সবচেয়ে দামি দাবিটা শেষেরটা ─────────────────────────
 * বাকিগুলো মাপে চিঠি যায় কি না। ⚠️ শেষেরটা মাপে চিঠিটা **কিউয়ে গিয়ে
 * চিরকাল বসে থাকবে না** — কারণ লাইভে একটাও `queue:work` চলে না, আর
 * ঐ অবস্থায় `ShouldQueue` বসালে কোড সফল ফেরত দিত আর চিঠি যেত না।
 */
final class TheNewsLeavesTheBuildingTest extends TestCase
{
    use RefreshDatabase;

    private User $her;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($company->id, $company->defaultBranch()?->id);

        $this->her = User::query()->where('email', 'sales@abos.test')->firstOrFail();

        /*
         * ⚠️ অভিনয় করছেন **অন্য একজন**, ইচ্ছাকৃতভাবে।
         *
         * ⓘ [[NotificationService]]-এর নিয়ম: নিজের কাজের খবর নিজে পান
         * না। ⛔ প্রাপক হিসেবেই লগইন করলে প্রতিটা `send()` নীরবে `null`
         * ফেরত দিত, আর গোটা ফাইলটা সবুজ থাকত **কিছুই না মেপে**।
         */
        $this->actingAs(User::query()->where('email', 'owner@abos.test')->firstOrFail());

        config(['mail.default' => 'smtp']);
    }

    /**
     * ⭐ যে খবরটা তাঁর উপর অপেক্ষা করছে, সেটা ইনবক্সেও যায়।
     */
    public function test_news_that_waits_on_you_reaches_your_inbox(): void
    {
        Notification::fake();

        $this->news('approval.rejected');

        /*
         * ⚠️ `assertSentTo()`-র তৃতীয় ঘরটা একটা **কলব্যাক**, বার্তা নয়।
         *
         * ⛔ প্রথম চালে ওখানে বাংলা বাক্যটা বসিয়েছিলাম, আর PHP সেটাকে
         * ফাংশনের নাম ধরে ডাকতে গিয়ে ভেঙেছে। ⓘ ভাগ্য ভালো যে ওটা জোরে
         * ভেঙেছে — চুপচাপ উপেক্ষা করলে দাবিটা কিছু না মেপেই সবুজ থাকত।
         *
         * ⭐ তাই `sent()` ধরে নিজে গোনা হয়, আর কারণটা ব্যর্থতার সাথে যায়।
         */
        $this->assertNotEmpty(Notification::sent($this->her, NewsByMail::class),
            '⛔ ফেরত আসা কাগজের খবর ইনবক্সে যায়নি — যিনি লগইন করেন না, তিনি কোনোদিন জানবেন না।');
    }

    /**
     * ⭐ আর ঘণ্টাটা তবু বাজে — চিঠি ঘণ্টার বদলি নয়, বাড়তি।
     *
     * ⛔ এই দাবিটা না থাকলে কেউ একদিন "চিঠি তো গেছেই" বলে ঘণ্টার সারিটা
     * বাদ দিয়ে দিতে পারতেন, আর তখন অ্যাপের ভিতরে খবরটা আর থাকত না।
     */
    public function test_the_bell_still_rings(): void
    {
        Notification::fake();

        $bell = $this->news('approval.rejected');

        $this->assertNotNull($bell, '⛔ ঘণ্টার সারিটাই তৈরি হয়নি।');
        $this->assertSame(1, Bell::query()->where('user_id', $this->her->id)->count(),
            '⛔ চিঠি গেছে কিন্তু ঘণ্টায় কিছু নেই — অ্যাপে ঢুকে মানুষ খবরটা আর খুঁজে পাবেন না।');
    }

    /**
     * ⭐ যে খবর কারো উপর অপেক্ষা করছে না, সেটা চিঠি পায় না।
     *
     * ⚠️ এটাই এই ফিচারের লাগাম। ⛔ সব খবর চিঠিতে গেলে মানুষ এক সপ্তাহে
     * ABOS-এর ঠিকানা স্প্যামে ফেলেন — আর তারপর **পাসওয়ার্ড রিসেটের
     * চিঠিটাও** সেখানে পড়ে। ⓘ অর্থাৎ বেশি পাঠানো কেবল বিরক্তিকর নয়,
     * সেটা লগইনের পথটাই নষ্ট করে।
     */
    public function test_news_that_waits_on_nobody_stays_in_the_bell(): void
    {
        Notification::fake();

        $this->news('approval.approved');

        Notification::assertNothingSentTo($this->her);

        $this->assertSame(1, Bell::query()->where('user_id', $this->her->id)->count(),
            'ⓘ ঘণ্টায় তবু থাকা উচিত — কেবল চিঠিটা যাওয়ার কথা নয়।');
    }

    /**
     * ⭐ দুইটা প্রশ্ন আলাদা: "খবরটা চাই" আর "ইনবক্সেও চাই"।
     *
     * ⓘ সবচেয়ে সাধারণ চাওয়াটাই এটা — ঘণ্টায় আসুক, ইনবক্সে নয়। ⛔ একটা
     * সুইচে দুইটা কাজ করালে এই কথাটা বলার কোনো উপায়ই থাকত না।
     */
    public function test_she_can_keep_the_bell_and_refuse_the_letter(): void
    {
        Notification::fake();

        NotificationChoice::query()->create([
            'user_id' => $this->her->id,
            'type' => 'approval.rejected',
            'enabled' => true,
            'by_email' => false,
        ]);

        $this->news('approval.rejected');

        Notification::assertNothingSentTo($this->her);

        $this->assertSame(1, Bell::query()->where('user_id', $this->her->id)->count(), implode("\n", [
            '⛔ চিঠি বন্ধ করতে গিয়ে ঘণ্টাটাও বন্ধ হয়ে গেছে।',
            '',
            '⚠️ তখন তিনি খবরটা কোথাও পেতেন না — অথচ তিনি কেবল ইনবক্স',
            'বাঁচাতে চেয়েছিলেন।',
        ]));
    }

    /** ⭐ আর উল্টোটাও — যে ধরনটা ডিফল্টে চিঠি পায় না, সেটা চেয়ে নেওয়া যায়। */
    public function test_she_can_ask_for_a_letter_the_kind_does_not_send(): void
    {
        Notification::fake();

        NotificationChoice::query()->create([
            'user_id' => $this->her->id,
            'type' => 'approval.approved',
            'enabled' => true,
            'by_email' => true,
        ]);

        $this->news('approval.approved');

        $this->assertNotEmpty(Notification::sent($this->her, NewsByMail::class),
            '⛔ তিনি নিজে চেয়েছেন, তবু চিঠি যায়নি — ধরনের ডিফল্ট মানুষের কথার উপরে বসে গেছে।');
    }

    /** ঘণ্টা বন্ধ থাকলে চিঠিও নেই — নাহলে সুইচটা মিথ্যা বলত। */
    public function test_silencing_the_kind_silences_the_letter_too(): void
    {
        Notification::fake();

        NotificationChoice::query()->create([
            'user_id' => $this->her->id,
            'type' => 'approval.rejected',
            'enabled' => false,
            'by_email' => true,
        ]);

        $this->news('approval.rejected');

        Notification::assertNothingSentTo($this->her);
        $this->assertSame(0, Bell::query()->where('user_id', $this->her->id)->count());
    }

    /**
     * ⭐ মেইলার নীরব হলে চেষ্টাই করা হয় না — কিন্তু ঘণ্টা তবু বাজে।
     *
     * ⓘ `MAIL_MAILER=log` অবস্থায় Laravel চিঠিটা **সফলভাবে** ফাইলে লেখে।
     * ⚠️ পাঠানোর ভান করলে কোড ভাবত কাজটা হয়েছে ([[MailReach]])।
     */
    public function test_a_silent_mailer_stops_the_letter_and_keeps_the_bell(): void
    {
        Notification::fake();
        config(['mail.default' => 'log']);

        $bell = $this->news('approval.rejected');

        Notification::assertNothingSentTo($this->her);
        $this->assertNotNull($bell, '⛔ মেইলার নীরব বলে ঘণ্টার সারিটাও হারিয়ে গেছে।');
    }

    /**
     * ⭐ SMTP ভেঙে পড়লে ডাকা কাজটা ভাঙে না।
     *
     * ⛔ এটা না থাকলে জমার মেয়াদের ক্রন মাঝপথে থামত, আর একটা অনুমোদনের
     * সিদ্ধান্ত সংরক্ষিত হয়েও পর্দায় ৫০০ দেখাত। ⚠️ চিঠি না যাওয়া একটা
     * **কম খারাপ** ব্যর্থতা — খবরটা তবু ঘণ্টায় আছে।
     */
    public function test_a_broken_smtp_does_not_break_the_work(): void
    {
        /*
         * ⓘ `Notification::fake()` নয় — ওটা পাঠানোটাকেই সরিয়ে দেয়, তাই
         * ব্যতিক্রমটা কোনোদিন উঠত না আর দাবিটা **কিছুই মাপত না**।
         * ⭐ তাই মেইলারকেই ভাঙা হয়, আর আসল পথটা ধরে চলা হয়।
         */
        Exceptions::fake();
        Mail::shouldReceive('mailer')->andThrow(new RuntimeException('smtp down'));

        $bell = $this->news('approval.rejected');

        /*
         * ⛔ এই লাইনটাই দাবিটাকে অন্ধ হওয়া থেকে বাঁচায়।
         *
         * ⚠️ মক-টা যদি ভুল জায়গায় বসে (চিঠি পাঠানোর পথ `Mail::mailer()`
         * দিয়ে না গেলে), তাহলে কোনো ব্যতিক্রমই উঠত না — আর নিচের দাবিটা
         * **সবুজ থাকত কিছুই না মেপে**। ⓘ ধরা পড়ল ব্যতিক্রমটা সত্যিই
         * উঠেছিল আর সত্যিই গিলে ফেলা হয়েছিল, এটা দেখিয়ে।
         */
        Exceptions::assertReported(RuntimeException::class);

        $this->assertNotNull($bell, implode("\n", [
            '⛔ SMTP ভেঙে পড়ায় গোটা কাজটাই ভেঙে গেছে।',
            '',
            '⚠️ অর্থাৎ মেইল সার্ভার বন্ধ থাকলে অনুমোদন দেওয়াই যেত না,',
            'আর কেউ বুঝতেও পারত না কারণটা চিঠি।',
        ]));
    }

    /**
     * ⭐ চিঠিটা তাঁর নিজের ভাষায়, অনুরোধের ভাষায় নয়।
     *
     * ⓘ খবরগুলো প্রায়ই ক্রন থেকে যায়, আর ক্রনের কোনো ভাষা নেই।
     */
    public function test_the_letter_speaks_her_language_not_the_senders(): void
    {
        $this->her->forceFill(['locale' => 'bn'])->save();
        app()->setLocale('en');

        $letter = (new NewsByMail($this->bell('approval.rejected')))->toMail($this->her->fresh());

        $this->assertStringContainsString(__('core.notify.mail_button', [], 'bn'),
            (string) $letter->render(),
            '⛔ বাংলায় কাজ করা একজনের চিঠি ইংরেজিতে গেছে।');
    }

    /**
     * ⛔ সবচেয়ে দামি দাবি — চিঠিটা কিউয়ে গিয়ে বসে থাকবে না।
     *
     * ── ⚠️ মেপে দেখা, ২২ সেপ্টেম্বর ২০২৬ ────────────────────────────
     * লাইভে `QUEUE_CONNECTION=database`, আর `queue:work` চলছে **শূন্যটা**।
     * ⓘ অর্থাৎ `ShouldQueue` বসানো যেকোনো বিজ্ঞপ্তি `jobs` টেবিলে গিয়ে
     * বসত আর কোনোদিন যেত না — অথচ কোড সফল ফেরত দিত।
     *
     * ⭐ ওয়ার্কার বসানোর দিন এই দাবিটা লাল হবে, আর সেটাই ঠিক: তখন কেউ
     * এখানে এসে পড়বেন, দেখবেন **কেন** নিষেধটা ছিল, আর জেনেশুনে তুলে
     * দেবেন। ⓘ এটা পাহারা, বাধা নয়।
     */
    public function test_no_letter_waits_for_a_worker_that_is_not_running(): void
    {
        $queued = [];

        foreach (glob(app_path('Notifications/*.php')) as $file) {
            $class = 'App\\Notifications\\'.basename($file, '.php');

            if ((new ReflectionClass($class))->implementsInterface(ShouldQueue::class)) {
                $queued[] = $class;
            }
        }

        $this->assertSame([], $queued, implode("\n", [
            '⛔ এই বিজ্ঞপ্তিগুলো কিউয়ের অপেক্ষায় বসবে: '.implode(', ', $queued),
            '',
            '⚠️ লাইভে একটাও `queue:work` চলে না (২২ সেপ্টেম্বর ২০২৬-এ মাপা),',
            'তাই চিঠিটা `jobs` টেবিলে গিয়ে বসবে আর কোনোদিন যাবে না —',
            'অথচ কোড সফল ফেরত দেবে।',
            '',
            'ⓘ ওয়ার্কার বসানো থাকলে এই দাবিটা তুলে দিন, আর তুলে দেওয়ার',
            'আগে লাইভে `ps aux | grep queue:work` দিয়ে নিজে দেখে নিন।',
        ]));
    }

    /**
     * পাহারাটা সত্যিই তাকায়।
     *
     * ⓘ উপরের দাবিটা সবুজ থাকত যদি `glob()` একটা ফাইলও না পেত — তখন
     * তালিকাটা খালি, আর খালি তালিকায় কোনো ভুলও নেই।
     */
    public function test_there_really_are_notifications_to_check(): void
    {
        $this->assertGreaterThanOrEqual(4, count(glob(app_path('Notifications/*.php'))),
            'বিজ্ঞপ্তির ফাইল প্রায় কিছুই পাওয়া গেল না — পথটা পড়া হচ্ছে না।');
    }

    /** ⭐ আর ধরনের তালিকাটা সত্যিই দুই দিকে ভাগ হয়েছে, সব এক দিকে নয়। */
    public function test_the_kinds_really_split_two_ways(): void
    {
        $kinds = array_keys(NotificationKinds::all());
        $mailed = array_filter($kinds, fn ($k) => NotificationKinds::mailedByDefault($k));

        $this->assertNotEmpty($mailed, '⛔ একটা ধরনও ডিফল্টে চিঠি পায় না — ফিচারটা কার্যত নেই।');

        $this->assertNotSame(count($kinds), count($mailed), implode("\n", [
            '⛔ প্রতিটা ধরনই ডিফল্টে চিঠি পাচ্ছে।',
            '',
            '⚠️ তাহলে লাগামটা নেই, আর মানুষ এক সপ্তাহে ABOS-এর ঠিকানা',
            'স্প্যামে ফেলবেন — পাসওয়ার্ড রিসেটের চিঠিসহ।',
        ]));
    }

    private function news(string $type): ?Bell
    {
        return app(NotificationService::class)
            ->send($this->her, $type, 'পরীক্ষার খবর', 'বিস্তারিত', 'https://abos.test/kaj');
    }

    private function bell(string $type): Bell
    {
        return Bell::query()->create([
            'company_id' => CompanyContext::id(),
            'user_id' => $this->her->id,
            'type' => $type,
            'title' => 'পরীক্ষার খবর',
            'body' => 'বিস্তারিত',
            'url' => 'https://abos.test/kaj',
        ]);
    }
}
