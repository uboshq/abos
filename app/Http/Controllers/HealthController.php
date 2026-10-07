<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\Backup\BackupFreshness;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * সাইট সত্যিই কাজ করতে পারে কি না — কেবল জেগে আছে কি না নয়।
 *
 * ── ⛔ কী ছিল, ২৭ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────
 * `infra/deploy.sh` ফেরার সিদ্ধান্ত নিত `/up` দেখে, আর `/up` Laravel-এর
 * নিজের: সে কেবল বলে PHP একটা উত্তর বানাতে পেরেছে। ⚠️ ডাটাবেস বন্ধ,
 * ডিস্ক ভরা, ব্যাকআপ তিন দিন বাসি — তিনটাতেই `/up` ২০০ দিত, আর ডিপ্লয়
 * লিখত "উঠেছে" যখন কাউন্টারে প্রতিটা পাতা ৫০০।
 *
 * ── ⭐ দুই দরজা, দুই প্রশ্ন ───────────────────────────────────────────
 *   `/up`      বেঁচে আছে কি না (liveness) — সস্তা, কিছুই ছোঁয় না
 *   `/health`  কাজ নিতে পারে কি না (readiness) — এই ক্লাস
 *
 * ⓘ `/up` রেখে দেওয়া ইচ্ছাকৃত: কোনো পাহারাদার যদি "মরে গেছে" ধরে
 * প্রক্রিয়া আবার চালু করে, ডাটাবেসের এক মুহূর্তের হোঁচটে সে PHP-কে
 * মারবে না — ওটা PHP-র দোষ নয়। ডিপ্লয় আর নজরদারি দেখে `/health`।
 *
 * ── ⛔ উত্তরে কেবল ok / fail ─────────────────────────────────────────
 * দরজাটা লগইনের বাইরে, তাই উত্তরটা ইন্টারনেটের যে কেউ পড়ে। ⚠️ ঠিকানা,
 * হোস্ট, সংস্করণ বা ভুলের লেখা বাইরে গেলে আক্রমণকারী বিনা পরিশ্রমে জানত
 * কোথায় কী আছে। কারণটা যায় লগে — যিনি সারাবেন তিনি সেখানেই দেখেন।
 *
 * ── ⓘ `web` মিডলওয়্যার কেন নেই ──────────────────────────────────────
 * সেশন, কোম্পানির প্রসঙ্গ, লাইসেন্সের তালা — ওগুলো নিজেরাই ডাটাবেস
 * ছোঁয়। ⚠️ ডাটাবেস বন্ধ থাকলে ওরা এই ক্লাসে পৌঁছানোর **আগেই** ৫০০
 * ছুঁড়ত, আর উত্তরটা JSON থাকত না। তালাবদ্ধ সার্ভারও সুস্থ হতে পারে —
 * তালা একটা ব্যবসার প্রশ্ন, স্বাস্থ্যের নয়।
 */
class HealthController extends Controller
{
    /**
     * ⓘ মিনিটে ত্রিশ: ডিপ্লয় তিনবার দেখে, নজরদারি মিনিটে একবার — দুইটাই
     * অনেক নিচে। ⚠️ প্রতিটা ডাক একটা কোয়েরি আর একটা ফাইল লেখে, তাই খোলা
     * দরজায় সীমা না থাকলে এটাই সস্তা চাপের পথ হত।
     */
    public const PER_MINUTE = 30;

    public function __construct(private readonly BackupFreshness $backups) {}

    public function __invoke(): JsonResponse
    {
        $checks = [
            'db' => $this->check('db', fn (): bool => $this->databaseAnswers()),
            'disk' => $this->check('disk', fn (): bool => $this->diskCanTakeWrites()),
            'backup' => $this->check('backup', fn (): bool => ! $this->backups->isStale()),
        ];

        $healthy = ! in_array('fail', $checks, true);

        return response()
            ->json(['status' => $healthy ? 'ok' : 'fail', 'checks' => $checks], $healthy ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * ⛔ প্রতিটা প্রশ্ন নিজের ঘেরায় — একটা ছুঁড়লে বাকিগুলোর উত্তর হারাত
     * না, আর ছোঁড়াটা "fail" হয়, ৫০০ নয়।
     *
     * @param  callable(): bool  $probe
     */
    private function check(string $name, callable $probe): string
    {
        try {
            if ($probe()) {
                return 'ok';
            }

            Log::warning("health: {$name} failed");
        } catch (Throwable $e) {
            Log::warning("health: {$name} failed", ['exception' => $e::class, 'message' => $e->getMessage()]);
        }

        return 'fail';
    }

    /**
     * ⓘ `select 1`, কোনো টেবিল নয় — প্রশ্নটা "সংযোগ আছে কি না", আর
     * টেবিল পড়লে মাইগ্রেশনের অবস্থাও জড়িয়ে যেত।
     */
    private function databaseAnswers(): bool
    {
        return (int) DB::connection()->selectOne('select 1 as ok')->ok === 1;
    }

    /**
     * লেখা যায় কি না, আর জায়গা আছে কি না।
     *
     * ⚠️ `is_writable()` নয়, সত্যিকারের একটা লেখা: ভরা ডিস্কে বা ভুল
     * মালিকানায় `is_writable()` প্রায়ই "হ্যাঁ" বলে, আর প্রথম আসল লেখাটা
     * ব্যর্থ হয় — সেশন, লগ, ক্যাশ সবই এই ফোল্ডারে লেখে।
     *
     * ⛔ `disk_free_space()` উত্তর না দিলে (কিছু হোস্টে বন্ধ) "fail" —
     * না-জানা আর সুস্থ এক জিনিস নয়।
     */
    private function diskCanTakeWrites(): bool
    {
        $probe = storage_path('framework'.DIRECTORY_SEPARATOR.'health-'.bin2hex(random_bytes(6)));

        try {
            if (@file_put_contents($probe, 'ok') !== 2) {
                return false;
            }
        } finally {
            @unlink($probe);
        }

        $free = @disk_free_space(storage_path());

        return $free !== false
            && $free >= (int) config('abos.health.min_free_mb') * 1024 * 1024;
    }
}
