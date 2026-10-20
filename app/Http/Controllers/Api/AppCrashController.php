<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Services\ErrorJournal;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * ⭐ `POST /api/v1/app/crash` — ফোনের ক্র্যাশের খবর, নিজের সার্ভারে, ভুলের খাতায় ([[ErrorJournal::recordFromPhone()]])।
 *
 * <p>সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬: মাঠের ফোনে অ্যাপ বন্ধ হয়ে গেলে অফিস জানতেই পারত না। ⭐ বাইরে কিছু যায় না
 * (Crashlytics নয়), তাই মালিকের আলাদা অনুমতি লাগে না (সমন্বয়ক)।
 *
 * <p>⚠️ টোকেন ছাড়াও খোলা — লগইনের পর্দাতেও অ্যাপ ভাঙতে পারে। তাই: সীমা (রুটে `throttle`), প্রতিটা ঘরের আকারের সীমা,
 * আর কেবল চারটা ঘর — সংস্করণ, পর্দা, ভুলের লেখা, stack। টোকেন থাকলে মানুষ আর কোম্পানি জোড়া হয়; না থাকলে খালি।
 * ব্যবসার কোনো ডেটা ফেরে না — ২০২, খালি।
 *
 * <p>⭐ ১৯ অক্টোবর ২০২৬ (ec, অ্যাপ এন্টারপ্রাইজ মান): ফোন এখন খবর জমিয়ে রাখে আর নেট পেলে একসাথে পাঠায় —
 * `{"reports": [...]}`, এক ডাকে সর্বোচ্চ [[self::BATCH]]টা। প্রতিটায় আগের পাঁচ ঘরের সাথে Android সংস্করণ, ফোনের মডেল,
 * ফোনের ছাপ (`fingerprint`, sha1) আর কতবার (`times`)। ⓘ পুরনো বিল্ডের একটা-করে খবরও আগের মতোই চলে।
 * ⛔ মাইগ্রেশন নেই: Android আর মডেল `file` ঘরে বসে ("app … · Android … · মডেল · প্রথম ফ্রেম"), ছাপ `fingerprint`-এ।
 */
final class AppCrashController extends Controller
{
    /** এক ডাকে সর্বোচ্চ — ফোনের CrashReporter.batchSize-এর সমান */
    public const BATCH = 20;

    /**
     * ⛔ টোকেন ছাড়া এক ডাকে সর্বোচ্চ — fe, ২০ অক্টোবর ২০২৬ ([[TheCrashDoorCouldBeFloodedTest]])। ⓘ খোলা দরজায় ২০টার ঝাঁক
     * মানে এক আইপি থেকে মিনিটে ২০০ সারি; লগইনের আগে ফোনে এত খবর জমে না।
     */
    public const BATCH_WITHOUT_TOKEN = 5;

    /** ⛔ এক আইপির দিনে সর্বোচ্চ খবর — পেরোলে চুপচাপ বাদ, শুধু দিনের গোনা থাকে (`abos.app_crash.daily_per_ip`) */
    public const DAILY_PER_IP = 500;

    /** দিনের বাদ-পড়া গোনার চাবি — পরে দেখার জন্য (`Cache::get(AppCrashController::droppedKey())`) */
    public static function droppedKey(?string $day = null): string
    {
        return 'app-crash:dropped:'.($day ?? now()->format('Ymd'));
    }

    public function __invoke(Request $request, ErrorJournal $journal): JsonResponse
    {
        // ⓘ টোকেন থাকলে কে — কিন্তু টোকেন না থাকা বা মেয়াদ ফুরানো ভুল নয়
        $user = auth('sanctum')->user();
        $user = $user instanceof User ? $user : null;

        $one = [
            'version' => ['required', 'string', 'max:32'],
            'screen' => ['nullable', 'string', 'max:191'],
            'error' => ['required', 'string', 'max:191'],
            'message' => ['nullable', 'string', 'max:2000'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'android' => ['nullable', 'string', 'max:32'],
            'device' => ['nullable', 'string', 'max:64'],
            'fingerprint' => ['nullable', 'string', 'regex:/^[0-9a-f]{40}$/'],
            'times' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];

        if ($request->has('reports')) {
            $rules = ['reports' => ['required', 'array', 'min:1', 'max:'.($user === null ? self::BATCH_WITHOUT_TOKEN : self::BATCH)]];
            foreach ($one as $field => $rule) {
                $rules['reports.*.'.$field] = $rule;
            }
            $reports = $request->validate($rules)['reports'];
        } else {
            $reports = [$request->validate($one)];
        }

        $cap = (int) config('abos.app_crash.daily_per_ip', self::DAILY_PER_IP);
        $day = now()->format('Ymd');
        $ipKey = 'app-crash:ip:'.$day.':'.sha1((string) $request->ip());

        foreach ($reports as $data) {
            // ⛔ আইপির দিনের ছাদ — পেরোলে লেখা নয়, কেবল গোনা (ভুলের খাতা ভরানো ঠেকাতে)
            Cache::add($ipKey, 0, now()->addDays(2));
            if (Cache::increment($ipKey) > $cap) {
                Cache::add(self::droppedKey($day), 0, now()->addDays(7));
                Cache::increment(self::droppedKey($day));

                continue;
            }

            $journal->recordFromPhone(
                class: $data['error'],
                message: (string) ($data['message'] ?? ''),
                screen: (string) ($data['screen'] ?? ''),
                version: $data['version'],
                stack: (string) ($data['stack'] ?? ''),
                userId: $user?->id === null ? null : (int) $user->id,
                companyId: $user?->current_company_id === null ? null : (int) $user->current_company_id,
                android: (string) ($data['android'] ?? ''),
                device: (string) ($data['device'] ?? ''),
                phoneFingerprint: (string) ($data['fingerprint'] ?? ''),
                times: (int) ($data['times'] ?? 1),
            );
        }

        return response()->json([], 202);
    }
}
