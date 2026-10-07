<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * কিউ বসানো ছিল, আর কেউ কোনোদিন সেটা খালি করেনি।
 *
 * ── ⛔ নিরীক্ষার ফলাফল, ১২ সেপ্টেম্বর ২০২৬ ────────────────────────────
 * *"কিউ নেই"* — কার্যক্ষমতার নম্বর ৭.০-এর একটা কারণ।
 *
 * ⓘ ১৭ সেপ্টেম্বর মেপে দেখা গেছে কথাটা আধা-সত্য: `QUEUE_CONNECTION=database`,
 * `jobs` টেবিল আছে, কনফিগ নিখুঁত। ⛔ **কেবল কোনো ওয়ার্কার কোনোদিন চলেনি।**
 *
 * ── ⚠️ আর সেজন্যই কেউ কিউ ব্যবহারও করেননি ────────────────────────────
 * [[PasswordResetController]]-এ কথাটা হাতে লেখা আছে: চিঠিটা কিউতে দেওয়াই
 * সঠিক হত, কিন্তু দিলে ওটা *"টেবিলে বসে থাকত আর কোনোদিন যেত না"*।
 *
 * ⭐ অর্থাৎ ফাঁকটা কেবল একটা অনুপস্থিত ওয়ার্কার নয় — ওটা একটা **নিষেধাজ্ঞা**।
 * যতক্ষণ কেউ কিউ খালি করে না, ততক্ষণ কিউতে কিছু দেওয়াই যায় না, আর তাই
 * প্রতিটা ধীর কাজ (চিঠি, রিপোর্ট, ইমপোর্ট) ব্যবহারকারীকে অপেক্ষা করায়।
 *
 * ── ⛔ কেন এটা আজকের ব্যাকআপের রোগটারই যমজ ──────────────────────────
 * দুইটাতেই **ব্যবস্থাটা আছে বলে মনে হয়, অথচ কিছুই করে না**। ⚠️ ব্যাকআপ
 * ছয় দিন `DONE` বলেছিল; কিউ বলত "কাজটা জমা হয়েছে" — আর দুইটাই মিথ্যা
 * হত একই কারণে: **ফলটা কেউ মেপে দেখেনি।**
 *
 * ⭐ তাই এই ফাইলটা কনফিগ পড়ে না। একটা কাজ কিউতে ফেলে, ওয়ার্কারটা চালায়,
 * তারপর দেখে **কাজটা সত্যিই হয়েছে কি না**।
 */
final class TheQueueWasSetUpAndNobodyEverDrainedItTest extends TestCase
{
    use RefreshDatabase;

    private function proofFile(): string
    {
        $dir = storage_path('framework/testing/queue');

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir.DIRECTORY_SEPARATOR.'ran.txt';
    }

    /**
     * ⛔ সেটআপের দাবি, আর এটা সবার আগে।
     *
     * ⚠️ পরীক্ষার পরিবেশে `QUEUE_CONNECTION=sync` (phpunit.xml)। ⓘ sync
     * মানে কাজটা **সাথে সাথেই** চলে, কিউতে বসেই না — তাই নিচের দাবিটা
     * ঐ অবস্থায় সবুজ থাকত অথচ কিছুই প্রমাণ করত না।
     *
     * ⭐ সেজন্য দাবিটা নিজেই সংযোগটা `database`-এ বদলে নেয়, আর এই
     * পরীক্ষাটা নিশ্চিত করে বদলটা সত্যিই কাজ করেছে।
     */
    public function test_the_ground_this_file_stands_on_is_really_there(): void
    {
        config(['queue.default' => 'database']);

        $this->assertSame('database', config('queue.default'));

        $this->assertSame(0, DB::table('jobs')->count(),
            '`jobs` টেবিলটা শুরুতেই খালি থাকার কথা।');
    }

    /**
     * ⭐ আসল দাবি: কাজটা কিউতে বসে, ওয়ার্কার এসে **সত্যিই সেটা করে**।
     *
     * ⓘ প্রমাণটা টেবিল খালি হওয়া নয় — খালি তো ব্যর্থ কাজও হতে পারে।
     * ⚠️ প্রমাণ হলো কাজটার **ফল**: ফাইলটা লেখা হয়েছে কি না।
     */
    public function test_a_queued_job_actually_runs_when_the_worker_comes(): void
    {
        config(['queue.default' => 'database']);

        $proof = $this->proofFile();
        @unlink($proof);

        $marker = 'কিউ-প্রমাণ-'.uniqid();

        dispatch(function () use ($proof, $marker) {
            file_put_contents($proof, $marker);
        });

        /*
         * ⛔ প্রথমে দেখা দরকার কাজটা সত্যিই **অপেক্ষায় বসেছে**।
         *
         * ⓘ না বসলে নিচের "কাজ হয়েছে" দাবিটা sync-এর কারণেও সত্য হত,
         * আর তখন ওয়ার্কার নিয়ে এই পরীক্ষার কোনো মানে থাকত না।
         */
        $this->assertSame(1, DB::table('jobs')->count(),
            'কাজটা কিউতে বসেনি — তাহলে ওয়ার্কার নিয়ে দাবিটা অর্থহীন।');

        $this->assertFileDoesNotExist($proof,
            'ওয়ার্কার আসার আগেই কাজটা হয়ে গেছে — অর্থাৎ সংযোগটা এখনো sync।');

        $this->artisan('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => 30,
            '--tries' => 1,
        ])->assertSuccessful();

        $this->assertFileExists($proof,
            'ওয়ার্কার চলল, অথচ কাজটা হয়নি — ঠিক এই নীরবতাই সারাতে বসেছি।');

        $this->assertSame($marker, (string) file_get_contents($proof));

        $this->assertSame(0, DB::table('jobs')->count(),
            'কাজ শেষ হয়েছে, তবু সারিটা কিউতে পড়ে আছে।');

        @unlink($proof);
    }

    /**
     * ⭐ আর ওয়ার্কারটা সত্যিই **নিয়মিত আসে** কি না।
     *
     * ── ⛔ কেন এই দাবিটা আলাদা করে লাগে ────────────────────────────────
     * উপরের দাবিটা প্রমাণ করে ওয়ার্কার **কাজ করে**। ⚠️ কিন্তু কেউ যদি
     * ওটাকে কোনোদিন না ডাকে, তাহলে কিউ ঠিক আগের মতোই পড়ে থাকত — আর
     * সেটাই ছিল এতদিনের অবস্থা।
     *
     * ⓘ cPanel-এ supervisor নেই, তাই ভরসা শিডিউলারই। ⛔ শিডিউল থেকে
     * লাইনটা কেউ তুলে দিলে এই দাবিটা লাল হবে — আর সেটাই উদ্দেশ্য।
     */
    public function test_somebody_is_scheduled_to_come_and_drain_it(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => (string) $event->command)
            ->filter(fn (string $c) => str_contains($c, 'queue:work'));

        $this->assertNotEmpty($commands,
            'কেউ কিউ খালি করতে আসে না — কাজগুলো `jobs` টেবিলে চিরকাল বসে থাকত।');

        $line = (string) $commands->first();

        /*
         * ⚠️ `--stop-when-empty` ছাড়া ওয়ার্কারটা **বেরোয় না**।
         *
         * ⓘ শেয়ার্ড হোস্টিংয়ে দীর্ঘ প্রক্রিয়া হোস্ট নিজেই মেরে ফেলে, আর
         * প্রতি মিনিটে একটা করে জমতে থাকলে অ্যাকাউন্টটাই বন্ধ হয়ে যেত।
         */
        $this->assertStringContainsString('--stop-when-empty', $line,
            'ওয়ার্কারটা বেরোবে না — শেয়ার্ড হোস্টিংয়ে প্রতি মিনিটে একটা করে জমত।');

        $this->assertStringContainsString('--max-time', $line,
            'সময়ের সীমা নেই — একটা আটকে যাওয়া কাজ পরের ওয়ার্কারদের উপর চড়ে বসত।');
    }

    /**
     * ⓘ ব্যর্থ কাজ যেন নীরবে হারিয়ে না যায়।
     *
     * ⚠️ `--tries=1` হলে সাময়িক ব্যর্থতাতেই (SMTP এক সেকেন্ড আটকানো)
     * চিঠিটা চিরতরে যেত। ⛔ আর সেটা কেউ জানত না, কারণ `failed_jobs`
     * এমন একটা টেবিল যা কেউ খোলে না।
     */
    public function test_a_stumble_is_not_the_end_of_the_job(): void
    {
        $line = collect(app(Schedule::class)->events())
            ->map(fn ($event) => (string) $event->command)
            ->first(fn (string $c) => str_contains($c, 'queue:work'));

        $this->assertStringContainsString('--tries=3', (string) $line,
            'একবার হোঁচট খেলেই কাজটা শেষ — সাময়িক ব্যর্থতার জন্য আরেকটা সুযোগ দরকার।');
    }

    protected function tearDown(): void
    {
        Queue::getFacadeRoot();
        @unlink($this->proofFile());

        parent::tearDown();
    }
}
