<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use App\Models\ErrorEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * কিছু ভাঙলে সেটা লিখে রাখা — যাতে কেউ জানতে পারে।
 *
 * ── কেন এটা লাগল, ১ সেপ্টেম্বর ২০২৬ ──────────────────────────────────
 * এই অ্যাপে `Log::` কল ছিল ছয়টা, error tracking শূন্য। কিছু ভাঙলে
 * ব্যবহারকারী একটা ৫০০ দেখতেন, আর তারপর কোথাও কিছু থাকত না।
 *
 * ৩১ আগস্টের নিরীক্ষায় ছয়টা জিনিস **নীরবে** ভাঙা পাওয়া গেছে, আর
 * তার মধ্যে সবচেয়ে জোরালোটা: ডিপ্লয়ের পর লাইভে বিল কাটা প্রায় দুই
 * ঘণ্টা ভাঙা ছিল, আর জানা গেছে দৈবক্রমে।
 *
 * নিজের একটা ডিপো চালালে পাশের ঘরে গিয়ে দেখে আসা যায়। **দশটা
 * কোম্পানির কাছে বিক্রির পর "কাজ করছে না" ফোনের জবাবে কিছুই থাকে না** —
 * কোন পর্দা, কোন কোম্পানি, কী ব্যতিক্রম, কতবার। এটাই একমাত্র ফাঁক যা
 * গ্রাহক বাড়লে বর্গাকারে খারাপ হয়।
 */
final class ErrorJournal
{
    /**
     * যেগুলো লেখা হয় না — আর প্রতিটার কারণ।
     *
     * ── কেন একটা তালিকা লাগে ────────────────────────────────────────
     * সব ব্যতিক্রম লিখলে খাতাটা কয়েক ঘণ্টায় অপঠনীয় হত, আর তখন কেউ
     * আর ওটা খুলত না — অর্থাৎ খাতাটা থাকা আর না থাকা এক হয়ে যেত।
     *
     * নিচের প্রতিটাই **স্বাভাবিক ঘটনা**, ভুল নয়:
     *
     *   · ভ্যালিডেশন — ব্যবহারকারী ভুল লিখেছেন, ব্যবস্থা ঠিক কাজ করেছে;
     *   · লগইন লাগবে / অনুমতি নেই — পাহারা কাজ করছে, সেটাই উদ্দেশ্য;
     *   · রেকর্ড পাওয়া যায়নি — বেশিরভাগই পুরনো বুকমার্ক বা মোছা সারি;
     *   · CSRF টোকেন — ট্যাব খুলে রেখে চা খেতে গেলে যা হয়;
     *   · throttle — পাহারাটাই তো এটা করার জন্য।
     *
     * ৪xx সাধারণভাবে বাদ, ৫xx সবসময় লেখা — সীমারেখাটা ঠিক ওখানেই:
     * ৪xx মানে "আপনি ভুল চেয়েছেন", ৫xx মানে "আমরা পারিনি"।
     *
     * ⚠️ কিন্তু ৪০৩ আর ৪০৪ কখনো কখনো **আসল ভুলও** হয় — বন্ধ পর্দা,
     * হারানো অনুমতি (৩১ আগস্টে স্কিমের পর্দা ঠিক এভাবেই বন্ধ ছিল, আর
     * কেউ জানত না)। ওগুলো এখানে না ধরে **বইয়ের রোজকার যাচাইয়ে** ধরা
     * হয় (`abos:books-check`), কারণ ওখানে প্রশ্নটা একবার করা যায়,
     * প্রতিটা ক্লিকে নয়।
     */
    private const NOT_A_FAULT = [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        RecordsNotFoundException::class,
        TokenMismatchException::class,
        ThrottleRequestsException::class,
    ];

    /**
     * একটা ভুল খাতায় তোলা।
     *
     * ── কেন কিছুই ছুঁড়ে দেওয়া হয় না ─────────────────────────────────
     * এই পদ্ধতিটা ডাকা হয় ঠিক তখন যখন কিছু একটা ইতিমধ্যেই ভেঙেছে।
     * এখানে দ্বিতীয় একটা ব্যতিক্রম উঠলে আসল ভুলটাই ঢাকা পড়ত, আর
     * ব্যবহারকারী "ভুলের ভিতরে ভুল" জাতীয় একটা পাতা দেখতেন।
     *
     * তাই সবটাই try/catch-এ, আর ডাটাবেজ কাজ না করলে ফাইল-লগে।
     */
    public function record(Throwable $e): void
    {
        if ($this->isNotAFault($e)) {
            return;
        }

        try {
            $this->write($e);
        } catch (Throwable $second) {
            /*
             * খাতাটাই লেখা গেল না — তখন অন্তত ফাইলে।
             *
             * দুইটা বার্তাই লেখা হয়: আসলটা, আর খাতা লিখতে গিয়ে কী হলো।
             * দ্বিতীয়টা ছাড়া কেউ বুঝত না কেন পর্দায় কিছু দেখা যাচ্ছে না।
             */
            logger()->critical('ভুলের খাতায় লেখা যায়নি।', [
                'original' => $e::class.': '.$e->getMessage(),
                'while_writing' => $second::class.': '.$second->getMessage(),
            ]);
        }
    }

    private function isNotAFault(Throwable $e): bool
    {
        foreach (self::NOT_A_FAULT as $ignored) {
            if ($e instanceof $ignored) {
                return true;
            }
        }

        /*
         * HTTP ব্যতিক্রম — ৫xx হলে আমাদের দোষ, ৪xx হলে নয়।
         *
         * ৫০৩ (রক্ষণাবেক্ষণ) বাদ, কারণ ওটা কেউ ইচ্ছা করে চালু করেছেন।
         */
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return $status < 500 || $status === 503;
        }

        return false;
    }

    private function write(Throwable $e): void
    {
        $fingerprint = ErrorEvent::fingerprintFor($e::class, $e->getFile(), $e->getLine());
        $company = $this->companyId();
        $now = now();

        /*
         * আগেরটা থাকলে গোনা বাড়ে, নতুন সারি নয়।
         *
         * একটা ভাঙা পাতা পঞ্চাশ জন রিফ্রেশ করলে পাঁচশো সারি বসত, আর
         * তার নিচে চাপা পড়ত সেই একটা ভিন্ন ভুল যেটা সত্যিই নতুন।
         */
        $existing = ErrorEvent::query()
            ->where('fingerprint', $fingerprint)
            ->where('company_id', $company)
            ->first();

        if ($existing !== null) {
            $existing->forceFill([
                'times' => $existing->times + 1,
                'last_seen_at' => $now,
                'message' => $this->trim($e->getMessage(), 2000),
            ])->save();

            return;
        }

        ErrorEvent::create([
            'company_id' => $company,
            'user_id' => $this->userId(),
            'fingerprint' => $fingerprint,
            'class' => $this->trim($e::class, 191),
            'message' => $this->trim($e->getMessage(), 2000),
            'file' => $this->trim((string) $e->getFile(), 500),
            'line' => $e->getLine(),
            'route' => $this->trim((string) (request()?->route()?->getName() ?? ''), 191) ?: null,
            'method' => request()?->method(),

            /*
             * কেবল পথ, প্রশ্নাংশ নয়।
             *
             * `?token=…` বা `?key=…` ঠিকানায় বসতে পারে, আর সেটা খাতায়
             * তুলে রাখা মানে গোপন জিনিস একটা পড়ার-পর্দায় নিয়ে আসা।
             */
            'path' => $this->trim('/'.ltrim((string) (request()?->path() ?? ''), '/'), 500),

            'trace' => $this->shortTrace($e),
            'times' => 1,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    /**
     * প্রসঙ্গ থাকলে কোম্পানি, না থাকলে খালি।
     *
     * `CompanyContext::id()` প্রসঙ্গ ছাড়া null ফেরে, আর সেটাই দরকার —
     * এখানে ব্যতিক্রম ছুঁড়লে ভুল লেখার চেষ্টাটাই আরেকটা ভুল হত।
     */
    private function companyId(): ?int
    {
        try {
            return CompanyContext::id();
        } catch (Throwable) {
            return null;
        }
    }

    private function userId(): ?int
    {
        try {
            return auth()->id();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * ট্রেসের উপরের অংশটুকু — যেখানে আসল কথা থাকে।
     *
     * নিচের ফ্রেমগুলো প্রায় সবসময় ফ্রেমওয়ার্কের ভিতরের, আর ওগুলো
     * প্রতিটা সারিতে কয়েক কিলোবাইট যোগ করত অথচ কিছুই বলত না।
     */
    private function shortTrace(Throwable $e): string
    {
        $lines = [];

        foreach ($e->getTrace() as $frame) {
            if (count($lines) >= 15) {
                break;
            }

            $file = $frame['file'] ?? '[internal]';
            $line = $frame['line'] ?? '';
            $fn = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');

            $lines[] = $file.':'.$line.' '.$fn;
        }

        return $this->trim(implode("\n", $lines), 5000);
    }

    private function trim(string $value, int $max): string
    {
        return mb_substr($value, 0, $max);
    }
}
