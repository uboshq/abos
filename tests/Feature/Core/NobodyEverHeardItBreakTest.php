<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Services\ErrorJournal;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\ErrorEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * কিছু ভাঙলে কেউ যেন জানতে পারে।
 *
 * ── কেন এই পাহারাটা লাগল, ১ সেপ্টেম্বর ২০২৬ ──────────────────────────
 * ১,২৭,০০০ লাইনে `Log::` কল ছিল ছয়টা, error tracking শূন্য। কিছু ভাঙলে
 * ব্যবহারকারী একটা ৫০০ দেখতেন, আর তারপর কোথাও কোনো চিহ্ন থাকত না।
 *
 * ৩১ আগস্টের নিরীক্ষায় ছয়টা জিনিস **নীরবে** ভাঙা পাওয়া গেছে — স্কিমের
 * পর্দা, খরচের কেন্দ্রের পর্দা, ছয়টা N+1, তিন দিনের মৃত CI। আর সবচেয়ে
 * জোরালোটা: ডিপ্লয়ের পর **লাইভে বিল কাটা প্রায় দুই ঘণ্টা ভাঙা ছিল**,
 * আর জানা গেছে দৈবক্রমে।
 *
 * ── এই ফাইলের দুইটা দাবি ─────────────────────────────────────────────
 * ১. **যা সত্যিই ভুল, তা লেখা হয়** — নাহলে খাতাটা মিথ্যা আশ্বাস।
 * ২. **যা ভুল নয়, তা লেখা হয় না** — নাহলে খাতাটা কয়েক ঘণ্টায় অপঠনীয়,
 *    আর তখন কেউ আর ওটা খোলে না, অর্থাৎ থাকা আর না থাকা এক।
 *
 * দ্বিতীয়টা প্রথমটার চেয়ে কম জরুরি মনে হয়, কিন্তু নয়।
 */
class NobodyEverHeardItBreakTest extends TestCase
{
    use RefreshDatabase;

    private ErrorJournal $journal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->journal = app(ErrorJournal::class);

        /*
         * অনুমতিগুলো বসিয়ে নেওয়া — `RefreshDatabase` প্রতিবার খালি
         * ডাটাবেজ দেয়, আর অনুমতি বসে `abos:sync-permissions` চললে।
         * না বসালে পর্দার পরীক্ষাগুলো "এমন কোনো অনুমতি নেই" বলে পড়ত,
         * অথচ আসল প্রশ্নটা ছিল চাবিটা কাজ করে কি না।
         */
        app(PermissionSyncer::class)->sync();

        $company = Company::create(['code' => 'BRK', 'name_en' => 'Broken Ltd']);
        CompanyContext::set($company->id);
    }

    protected function tearDown(): void
    {
        CompanyContext::clear();
        parent::tearDown();
    }

    public function test_a_real_fault_is_written_down(): void
    {
        $this->journal->record(new RuntimeException('the till would not open'));

        $row = ErrorEvent::query()->firstOrFail();

        $this->assertSame(RuntimeException::class, $row->class);
        $this->assertSame('the till would not open', $row->message);
        $this->assertSame(1, $row->times);
        $this->assertNotNull($row->file);
        $this->assertNotNull($row->line);
        $this->assertNull($row->acknowledged_at);
    }

    /**
     * একই ভুল আবার এলে গোনা বাড়ে, নতুন সারি নয়।
     *
     * একটা ভাঙা পাতা পঞ্চাশ জন রিফ্রেশ করলে পাঁচশো সারি বসত, আর তার
     * নিচে চাপা পড়ত সেই একটা ভিন্ন ভুল যেটা সত্যিই নতুন। গুচ্ছ না
     * করলে খাতাটা নিজেই নিজের শত্রু।
     */
    public function test_the_same_fault_twice_is_one_row_with_a_count(): void
    {
        $again = fn () => new RuntimeException('the same thing again');

        $this->journal->record($again());
        $this->journal->record($again());
        $this->journal->record($again());

        $this->assertSame(1, ErrorEvent::query()->count());
        $this->assertSame(3, ErrorEvent::query()->firstOrFail()->times);
    }

    /** আলাদা ভুল আলাদা সারিই থাকে — নাহলে গুচ্ছ করাটা তথ্য মুছে ফেলত। */
    public function test_two_different_faults_stay_apart(): void
    {
        $this->journal->record(new RuntimeException('one'));
        $this->journal->record(new \LogicException('two'));

        $this->assertSame(2, ErrorEvent::query()->count());
    }

    /**
     * যা ভুল নয়, তা খাতায় ওঠে না।
     *
     * প্রতিটাই **স্বাভাবিক ঘটনা**: ব্যবহারকারী ভুল লিখেছেন, পাহারা কাজ
     * করেছে, বুকমার্ক পুরনো, বা ট্যাব খুলে রেখে চা খেতে যাওয়া হয়েছে।
     * এগুলো লিখলে দিনে হাজার সারি বসত আর আসল ভুলটা তলিয়ে যেত।
     */
    public function test_the_ordinary_refusals_are_not_faults(): void
    {
        $this->journal->record(ValidationException::withMessages(['name' => 'লাগবে']));
        $this->journal->record(new AuthenticationException);
        $this->journal->record(new AuthorizationException);
        $this->journal->record(new ModelNotFoundException);
        $this->journal->record(new TokenMismatchException);
        $this->journal->record(new NotFoundHttpException);

        $this->assertSame(0, ErrorEvent::query()->count(),
            'স্বাভাবিক প্রত্যাখ্যান খাতায় উঠলে আসল ভুল তলিয়ে যেত।');
    }

    /**
     * সীমারেখাটা ৪xx আর ৫xx-এর মাঝখানে।
     *
     * ৪xx মানে "আপনি ভুল চেয়েছেন", ৫xx মানে "আমরা পারিনি"। দ্বিতীয়টাই
     * আমাদের দোষ, আর সেটাই লেখা দরকার।
     */
    public function test_a_server_fault_counts_but_a_client_one_does_not(): void
    {
        $this->journal->record(new HttpException(422, 'you asked wrong'));
        $this->assertSame(0, ErrorEvent::query()->count());

        $this->journal->record(new HttpException(500, 'we could not'));
        $this->assertSame(1, ErrorEvent::query()->count());
    }

    /** রক্ষণাবেক্ষণ কেউ ইচ্ছা করে চালু করেছেন — ওটা ভাঙা নয়। */
    public function test_maintenance_mode_is_not_a_fault(): void
    {
        $this->journal->record(new HttpException(503, 'back soon'));

        $this->assertSame(0, ErrorEvent::query()->count());
    }

    /**
     * খাতাটা নিজে কখনো ছোঁড়ে না — এটাই সবচেয়ে জরুরি দাবি।
     *
     * এই পদ্ধতিটা ডাকা হয় ঠিক তখন যখন কিছু একটা ইতিমধ্যেই ভেঙেছে।
     * এখানে দ্বিতীয় একটা ব্যতিক্রম উঠলে **আসল ভুলটাই ঢাকা পড়ত**, আর
     * ব্যবহারকারী "ভুলের ভিতরে ভুল" জাতীয় একটা পাতা দেখতেন।
     *
     * টেবিলটাই সরিয়ে দিয়ে দেখা হয়, কারণ ওটাই সবচেয়ে খারাপ অবস্থা।
     */
    public function test_writing_the_journal_never_throws_even_with_no_table(): void
    {
        Schema::drop('error_events');

        $this->journal->record(new RuntimeException('and now the journal is gone too'));

        $this->assertTrue(true, 'খাতা লিখতে না পারলেও কিছু ছোঁড়া যাবে না।');
    }

    /**
     * প্রসঙ্গ না থাকলেও লেখা হয় — আর ওগুলোই সবচেয়ে গুরুতর।
     *
     * ভুলটা কোম্পানি বসার **আগেও** ঘটতে পারে: লগইনের পর্দায়, বা
     * প্রসঙ্গ বসানোর ব্যবস্থাটা নিজেই ভাঙলে। কোম্পানি বাধ্যতামূলক
     * করলে ঠিক ওই ভুলগুলোই কখনো লেখা যেত না।
     */
    public function test_a_fault_with_no_company_is_still_written(): void
    {
        CompanyContext::clear();

        $this->journal->record(new RuntimeException('before anyone signed in'));

        $row = ErrorEvent::query()->firstOrFail();

        $this->assertNull($row->company_id);
    }

    /**
     * সত্যিকারের একটা অনুরোধ ভাঙলে খাতায় ওঠে — জোড়াটার পরীক্ষা।
     *
     * ── কেন এই একটা আলাদা করে লাগে ──────────────────────────────────
     * উপরের সবগুলো [[ErrorJournal]]-কে **সরাসরি** ডাকে, তাই ওগুলো
     * প্রমাণ করে খাতাটা ঠিক লেখে। কিন্তু ওগুলোর একটাও প্রমাণ করে না
     * যে খাতাটা **আদৌ ডাকা হয়** — `bootstrap/app.php`-এর
     * `$exceptions->report(...)` লাইনটা মুছে দিলেও ওরা সবুজই থাকত।
     *
     * অর্থাৎ পাহারাটা সবুজ থেকেও অন্ধ হতে পারত, ঠিক যে ফাঁদটা এই
     * রিপোতে বারবার ধরা পড়েছে। এই পরীক্ষাটা তাই একটা সত্যিকারের
     * অনুরোধ ভাঙায় আর দেখে খাতায় কিছু বসল কি না।
     */
    public function test_a_broken_request_reaches_the_journal_by_itself(): void
    {
        Route::get('/__break', function () {
            throw new RuntimeException('a screen fell over');
        })->middleware('web');

        // হ্যান্ডলার চালু থাকতে হবে — নাহলে ব্যতিক্রমটা টেস্টের গায়েই
        // এসে লাগত, আর `report()` কোনোদিন ডাকা হত না
        $this->get('/__break');

        $row = ErrorEvent::query()->where('message', 'a screen fell over')->first();

        $this->assertNotNull($row,
            'অনুরোধ ভাঙল অথচ খাতায় কিছু বসেনি — জোড়াটা ছুটে গেছে।');
        $this->assertSame('/__break', $row->path);
        $this->assertSame('GET', $row->method);
    }

    /**
     * পর্দাটা চাবির পেছনে, আর চাবিটা অডিটের চাবি নয়।
     *
     * অডিট ব্যবসার ভাষায় কথা বলে; এই পর্দা ফাইলের পথ, লাইন নম্বর আর
     * স্ট্যাক ট্রেস দেখায়। হিসাবরক্ষকের প্রথমটা দরকার, দ্বিতীয়টা নয় —
     * তাই দুইটা আলাদা চাবি, আর এই পরীক্ষাটা সেটাই ধরে রাখে।
     */
    public function test_the_screen_needs_its_own_key(): void
    {
        $this->journal->record(new RuntimeException('shown on the screen'));

        $user = User::factory()->create();
        $user->companies()->attach(CompanyContext::id(), ['is_active' => true]);

        $this->actingAs($user)->get(route('governance.error.index'))->assertForbidden();

        $user->givePermissionTo('governance.error.view');

        $this->actingAs($user->fresh())
            ->get(route('governance.error.index'))
            ->assertOk()
            ->assertSee('shown on the screen');
    }

    /**
     * "দেখেছি" বললে সারিটা তালিকা থেকে সরে, কিন্তু থেকে যায়।
     *
     * মোছার বোতাম নেই, আর সেটাই ইচ্ছাকৃত: মুছতে দিলে যে ভুলটা কেউ
     * বুঝতে পারেনি সেটাই সবার আগে মুছে যেত।
     */
    public function test_seen_hides_the_row_without_losing_it(): void
    {
        $this->journal->record(new RuntimeException('one to acknowledge'));
        $row = ErrorEvent::query()->firstOrFail();

        $user = User::factory()->create();
        $user->companies()->attach(CompanyContext::id(), ['is_active' => true]);
        $user->givePermissionTo('governance.error.view');

        $this->actingAs($user->fresh())
            ->post(route('governance.error.acknowledge', $row))
            ->assertRedirect();

        $row->refresh();
        $this->assertNotNull($row->acknowledged_at);
        $this->assertSame($user->id, $row->acknowledged_by);

        // তালিকা থেকে সরেছে…
        $this->actingAs($user->fresh())
            ->get(route('governance.error.index'))
            ->assertDontSee('one to acknowledge');

        // …কিন্তু হারায়নি
        $this->actingAs($user->fresh())
            ->get(route('governance.error.index', ['only' => 'all']))
            ->assertSee('one to acknowledge');
    }

    /**
     * অনুরোধের ইনপুট কখনো খাতায় বসে না।
     *
     * মানুষ প্রায়ই আসল পাসওয়ার্ড ভুল ঘরে টাইপ করে, আর তখন খাতাটাই
     * পাসওয়ার্ডের তালিকা হয়ে যেত। ঠিকানার প্রশ্নাংশও নয় — ওখানে
     * টোকেন বা চাবি থাকতে পারে।
     */
    public function test_the_journal_never_keeps_what_was_typed(): void
    {
        $this->get('/?token=super-secret-value&password=hunter2');

        $this->journal->record(new RuntimeException('something failed mid-request'));

        $row = ErrorEvent::query()->firstOrFail();
        $all = json_encode($row->toArray(), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('super-secret-value', (string) $all);
        $this->assertStringNotContainsString('hunter2', (string) $all);
    }
}
