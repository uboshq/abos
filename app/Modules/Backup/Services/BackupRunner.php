<?php

declare(strict_types=1);

namespace App\Modules\Backup\Services;

use App\Core\Engines\Backup\DestinationFactory;
use App\Core\Services\BackupService;
use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Models\User;
use App\Modules\Backup\Models\BackupDestination;
use App\Modules\Backup\Models\BackupRun;
use App\Modules\Backup\Models\BackupVerification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * একবার ব্যাকআপ নেওয়া, আর যা হলো তা লিখে রাখা।
 *
 * ── এটা [[BackupService]]-এর বদলি নয়, তার উপরে একটা স্তর ─────────────
 * নিচের ইঞ্জিনটা যা করে তা ভালো করে, আর ৩ সেপ্টেম্বর হাতে-কলমে মিলিয়ে
 * দেখা হয়েছে: ডাম্প নেয়, gzip করে, **সত্যিই একটা ডাটাবেসে ফিরিয়ে এনে
 * টেবিল গোনে**, পুরনো মোছে। ওটা ছোঁয়া হয়নি — `deploy.sh` প্রতিটা
 * deploy-এর আগে ওই একই কমান্ড ডাকে, আর signature বদলালে প্রতিটা
 * deploy ব্যাকআপের ধাপেই থেমে যেত।
 *
 * এই স্তরটা যোগ করে ঠিক দুইটা জিনিস, আর দুইটাই আজ নেই:
 *
 *   ১. **কী হলো তা লেখা** — `bak_runs`, `bak_verifications`
 *   ২. **একাধিক গন্তব্য** — আজ একটাই পথ, আর সেটা `.env`-এ
 *
 * ── ⚠️ কেন একটা গন্তব্য ব্যর্থ হলে গোটা রান ব্যর্থ নয় ─────────────────
 * একটা ব্যাকআপ পাঁচ জায়গায় যেতে পারে, আর **তিনটায় গিয়ে দুইটায় ব্যর্থ
 * হওয়াটাই সবচেয়ে সাধারণ ফল** — পেনড্রাইভ খোলা, নেট বন্ধ, টোকেনের
 * মেয়াদ শেষ।
 *
 * ব্যতিক্রম ছুড়ে থেমে গেলে **যে তিনটা কপি সফল হয়েছে সেগুলোও হারাত**,
 * আর মানুষ আবার চালিয়ে ওই তিনটায় দ্বিতীয়বার পাঠাতেন। তাই প্রতিটা
 * গন্তব্য আলাদা করে চেষ্টা হয়, আর ফল আলাদা করে লেখা হয়।
 */
final class BackupRunner
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly DestinationFactory $factory,
    ) {}

    /**
     * এখনই একটা ব্যাকআপ, আর তার পুরো হিসাব।
     *
     * @param  string  $trigger  `manual` · `schedule` · `deploy`
     */
    public function runNow(?User $user, string $trigger = 'manual'): BackupRun
    {
        $run = BackupRun::create([
            'company_id' => CompanyContext::id(),
            'started_at' => now(),
            'status' => 'running',
            'backup_type' => 'full',
            'scope' => 'all',
            'triggered_by' => $trigger,
            'user_id' => $user?->id,
        ]);

        try {
            $made = $this->backups->run(now());
        } catch (Throwable $e) {
            /*
             * ⚠️ ব্যর্থতাও লেখা হয়, আর এটাই সবচেয়ে দরকারি সারি।
             *
             * সফল রানগুলো কেউ পড়ে না; মানুষ তালিকাটা খোলে যখন কিছু
             * একটা ভুল হয়েছে। ব্যর্থ রান না লিখলে ওই দিনটা ইতিহাসে
             * **একটা ফাঁক** হয়ে থাকত, আর ফাঁক দেখে কেউ বলতে পারত না
             * ব্যাকআপ ব্যর্থ হয়েছিল না কেউ চালায়ইনি।
             */
            $run->update([
                'finished_at' => now(),
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $file = (string) $made['file'];

        $run->update([
            'file' => basename($file),
            'bytes' => (int) $made['bytes'],
            'checksum' => is_file($file) ? hash_file('sha256', $file) : null,
        ]);

        /*
         * নিচের ইঞ্জিন প্রতিটা রানে **সত্যিকারের test restore** করে —
         * ফিরিয়ে এনে টেবিল গোনে। ফলটা এতদিন কোথাও লেখা হত না, তাই
         * "শেষ কবে সত্যিকারের restore পরীক্ষা হয়েছিল" প্রশ্নের উত্তর
         * কারও কাছে ছিল না। এখন থাকে।
         */
        $this->recordVerification($run, $file);

        [$ok, $failed] = $this->copyEverywhere($file);

        $run->update([
            'finished_at' => now(),
            'destinations_ok' => $ok,
            'destinations_failed' => $failed,

            /*
             * ⚠️ চারটা অবস্থা, আর `failed` তাদের একটা নয়।
             *
             * ── প্রথম পরীক্ষায় যা ভুল ছিল (৩ সেপ্টেম্বর ২০২৬) ─────────
             * এখানে লেখা ছিল: কোনো গন্তব্যে না পৌঁছালে `failed`। আর
             * প্রথম রানেই সেটা **মিথ্যা বলল** — ডাম্পটা নিখুঁতভাবে
             * তৈরি হয়েছে (৮৭ KB), যাচাইও পাশ করেছে, কেবল পেনড্রাইভের
             * ফোল্ডারটা তখনো ছিল না।
             *
             * "ব্যর্থ" পড়ে মানুষ ধরে নিতেন **কোনো ব্যাকআপই হয়নি**, আর
             * আবার চালাতেন — অথচ সার্ভারে একটা ভালো কপি পড়ে আছে।
             *
             * ডাম্প নিজে ব্যর্থ হলে সেটা উপরের `catch`-এ ধরা পড়ে, আর
             * সেখানেই `failed` লেখা হয়। এখানে পৌঁছানো মানে **ফাইলটা
             * আছে** — প্রশ্ন কেবল সেটা কোথায় কোথায় গেছে।
             *
             * `local_only` তাই ব্যর্থতা নয়, একটা **সতর্কতা**: কপিটা
             * আছে, কিন্তু ওই মেশিনেই — ৩-২-১-এর একটা শর্তও পূরণ হয়নি।
             */
            'status' => match (true) {
                $failed === [] && $ok !== [] => 'success',
                $failed !== [] && $ok !== [] => 'partial',
                default => 'local_only',
            },
        ]);

        return $run->fresh();
    }

    /**
     * কনসোল থেকে ডাকা — ডাম্পটা ইতিমধ্যেই হয়ে গেছে, কেবল কপি ও হিসাব।
     *
     * ── কেন এই আলাদা প্রবেশপথ ────────────────────────────────────────
     * রোজকার ব্যাকআপ আর deploy-এর ব্যাকআপ দুইটাই `abos:backup` দিয়ে
     * চলে, আর সেই কমান্ডটার signature `deploy.sh:93` ধরে আছে —
     * বদলালে প্রতিটা deploy ব্যাকআপের ধাপেই থেমে যেত।
     *
     * তাই ডাম্পের পথটা অপরিবর্তিত রেখে কপি ও হিসাবটা পরে যোগ হয়।
     *
     * ⚠️ কনসোলে কোনো লগইন নেই, কোনো কোম্পানি-প্রসঙ্গও নেই। তাই
     * **প্রতিটা কোম্পানির গন্তব্য আলাদা করে দেখা হয়** — নাহলে
     * বহু-কোম্পানির ইনস্টলে কেবল একটার গন্তব্যে কপি যেত, আর বাকিরা
     * চুপচাপ বাদ পড়ত।
     *
     * @param  array{file: string, bytes: int, mirrored: ?string}  $made
     */
    public function recordAndCopy(array $made, ?Command $console = null): void
    {
        $file = (string) $made['file'];

        foreach (Company::query()->pluck('id') as $companyId) {
            CompanyContext::set((int) $companyId);

            $hasAny = BackupDestination::query()->where('is_active', true)->exists();

            /*
             * যে কোম্পানির কোনো গন্তব্য নেই, তার জন্য একটা সারিও নয়।
             *
             * ⓘ নাহলে দশ-কোম্পানির ইনস্টলে রোজ দশটা `local_only` সারি
             * জমত, আর তালিকাটা এত ভরে যেত যে আসল ব্যর্থতাগুলো তার
             * ভেতরে হারিয়ে যেত। **যে সারি কেউ পড়ে না, সেটা লগ নয়,
             * আবর্জনা।**
             */
            if (! $hasAny) {
                continue;
            }

            $run = BackupRun::create([
                'company_id' => (int) $companyId,
                'started_at' => now(),
                'status' => 'running',
                'backup_type' => 'full',
                'scope' => 'all',
                'file' => basename($file),
                'bytes' => (int) $made['bytes'],
                'checksum' => is_file($file) ? hash_file('sha256', $file) : null,
                'triggered_by' => 'schedule',
            ]);

            [$ok, $failed] = $this->copyEverywhere($file);

            $run->update([
                'finished_at' => now(),
                'destinations_ok' => $ok,
                'destinations_failed' => $failed,
                'status' => match (true) {
                    $failed === [] && $ok !== [] => 'success',
                    $failed !== [] && $ok !== [] => 'partial',
                    default => 'local_only',
                },
            ]);

            $console?->line(sprintf(
                '  গন্তব্য: %dটায় গেছে, %dটায় যায়নি',
                count($ok),
                count($failed),
            ));

            foreach ($failed as $f) {
                $console?->warn("    {$f['name']} — ".__($f['reason']));
            }
        }

        CompanyContext::clear();
    }

    /**
     * প্রতিটা সক্রিয় গন্তব্যে কপি — একটার ব্যর্থতা অন্যটাকে থামায় না।
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function copyEverywhere(string $file): array
    {
        $ok = [];
        $failed = [];

        $destinations = BackupDestination::query()
            ->where('is_active', true)
            ->get();

        foreach ($destinations as $destination) {
            try {
                $driver = $this->factory->make($destination->driver, $destination->config ?? []);

                $health = $driver->health();

                if (! $health->reachable) {
                    throw new \RuntimeException($health->reason ?? 'backup::health.unknown');
                }

                /*
                 * ⚠️ জায়গা আগে দেখা, লেখার আগে।
                 *
                 * মাঝপথে ডিস্ক ভরে গেলে একটা **অর্ধেক ফাইল** পড়ে
                 * থাকে — দেখতে ব্যাকআপের মতোই, আকারও প্রায় ঠিক, আর
                 * ফেরে না।
                 */
                if (! $health->hasRoomFor((int) filesize($file))) {
                    throw new \RuntimeException('backup::health.no_room');
                }

                $driver->put($file, basename($file));

                $destination->forceFill([
                    'last_checked_at' => now(),
                    'last_ok_at' => now(),
                    'last_error' => null,
                ])->save();

                $ok[] = ['id' => $destination->id, 'name' => $destination->name];
            } catch (Throwable $e) {
                $destination->forceFill([
                    'last_checked_at' => now(),
                    'last_error' => mb_substr($e->getMessage(), 0, 500),
                ])->save();

                $failed[] = [
                    'id' => $destination->id,
                    'name' => $destination->name,
                    'reason' => mb_substr($e->getMessage(), 0, 200),
                ];
            }
        }

        return [$ok, $failed];
    }

    /**
     * যাচাইয়ের ফল লিখে রাখা।
     *
     * ⚠️ **"সফল" লেখা একটা সারি প্রমাণ নয়; সংখ্যাটাই প্রমাণ।**
     *
     * ৩ সেপ্টেম্বর ২০২৬-এ এটা হাতে-কলমে শেখা: একটা restore যাচাই
     * "০ বনাম ০" মিলিয়ে সবুজ দেখিয়েছিল — একটাও কোয়েরি চলেনি, কিন্তু
     * দুই দিকই খালি বলে তুলনাটা মিলে গিয়েছিল। তাই এখানে টেবিলের
     * সংখ্যাটাও লেখা হয়, আর [[BackupVerification::sawSomething()]]
     * সেটাই দেখে — কাঁচা `status` নয়।
     */
    private function recordVerification(BackupRun $run, string $file): void
    {
        $started = microtime(true);

        try {
            $result = $this->backups->verify($file);

            BackupVerification::create([
                'run_id' => $run->id,
                'kind' => 'test_restore',
                'status' => ($result['tables'] ?? 0) > 0 ? 'passed' : 'failed',
                'detail' => $result,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'verified_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            BackupVerification::create([
                'run_id' => $run->id,
                'kind' => 'test_restore',
                'status' => 'failed',
                'detail' => ['error' => mb_substr($e->getMessage(), 0, 300)],
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'verified_at' => Carbon::now(),
            ]);
        }
    }
}
