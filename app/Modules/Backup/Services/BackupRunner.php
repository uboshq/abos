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
     * রাতের ব্যাকআপ — খাতায় তোলা আর গন্তব্যে পাঠানো।
     *
     * ── ⛔ রাতের ব্যাকআপ কোনো চিহ্ন রাখত না, ১৯ সেপ্টেম্বর ২০২৬ ──────────
     * আগে এখানে লেখা ছিল `if (! $hasAny) continue` — গন্তব্য বসানো না থাকলে
     * কোম্পানিটা বাদ। ⚠️ লাইভে কোনো গন্তব্য নেই, তাই প্রতিটা রাতের ব্যাকআপ
     * নেওয়া হত, যাচাইও হত — আর **ডাটাবেজে একটা সারিও পড়ত না**। পর্দা বলত
     * "কিছুই হয়নি", অথচ ডাম্পটা ডিস্কে বসে ছিল। ⓘ ধরা পড়েছে যাচাইয়ের পর্দা
     * বানাতে গিয়ে: দেখানোর মতো কিছু ছিল না।
     *
     * ⭐ এখন প্রতিটা রাত প্রতিটা কোম্পানির খাতায় ওঠে। গন্তব্য না থাকলে অবস্থা
     * `local_only` — "ব্যাকআপ আছে, কিন্তু একই সার্ভারে"। আর যাচাইয়ের ফলটাও
     * সারির সাথে থাকে ([[BackupVerification]]), কেবল লগ ফাইলে নয়।
     *
     * @param  array{file: string, bytes: int}  $made
     * @param  array{tables?: int, rows?: int}|null  $verified  রাতের যাচাইয়ের ফল — বাদ দেওয়া হলে null
     */
    public function recordAndCopy(array $made, ?Command $console = null, ?array $verified = null, int $verifyMs = 0): void
    {
        $file = (string) $made['file'];

        foreach (Company::query()->pluck('id') as $companyId) {
            CompanyContext::set((int) $companyId);

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

            if ($verified !== null) {
                BackupVerification::create([
                    'run_id' => $run->id,
                    'kind' => 'test_restore',
                    'status' => ($verified['tables'] ?? 0) > 0 ? 'passed' : 'failed',
                    'detail' => $verified,
                    'duration_ms' => $verifyMs,
                    'verified_at' => Carbon::now(),
                ]);
            }

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

            if ($ok !== [] || $failed !== []) {
                $console?->line(sprintf(
                    '  গন্তব্য: %dটায় গেছে, %dটায় যায়নি',
                    count($ok),
                    count($failed),
                ));
            }

            foreach ($failed as $f) {
                $console?->warn("    {$f['name']} — ".__($f['reason']));
            }
        }

        CompanyContext::clear();
    }

    /**
     * ব্যর্থ রাত — কারণসহ, হুবহু।
     *
     * ⛔ আগে ব্যর্থতা কেবল লগ ফাইলে যেত; খাতায় কিছুই না। ⚠️ ১৯ সেপ্টেম্বরের
     * ১৬:৩০-এ যাচাই ব্যর্থ হয়েছিল (*"Access denied … to database
     * 'univerbd_abos_verify'"*) — মালিক সেটা জানতে পারতেন কেবল সার্ভারে ঢুকে
     * লগ পড়ে। ⭐ এখন সারিটা লাল হয়ে পর্দায় আসে, আর বার্তাটা যেমন ছিল তেমন।
     *
     * @param  array{file: string, bytes: int}|null  $made  ডাম্প নেওয়া গিয়েছিল কি না (যাচাইয়ে ভাঙলে আছে)
     */
    public function recordFailure(?array $made, Throwable $failure, bool $whileVerifying, int $verifyMs = 0): void
    {
        $file = $made !== null ? (string) $made['file'] : null;

        foreach (Company::query()->pluck('id') as $companyId) {
            CompanyContext::set((int) $companyId);

            $run = BackupRun::create([
                'company_id' => (int) $companyId,
                'started_at' => now(),
                'finished_at' => now(),
                'status' => 'failed',
                'backup_type' => 'full',
                'scope' => 'all',
                'file' => $file !== null ? basename($file) : null,
                'bytes' => $made !== null ? (int) $made['bytes'] : null,
                'checksum' => $file !== null && is_file($file) ? hash_file('sha256', $file) : null,
                'error' => mb_substr($failure->getMessage(), 0, 2000),
                'triggered_by' => 'schedule',
            ]);

            if ($whileVerifying) {
                BackupVerification::create([
                    'run_id' => $run->id,
                    'kind' => 'test_restore',
                    'status' => 'failed',
                    'detail' => ['error' => mb_substr($failure->getMessage(), 0, 300)],
                    'duration_ms' => $verifyMs,
                    'verified_at' => Carbon::now(),
                ]);
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
