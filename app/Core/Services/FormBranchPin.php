<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * ⛔ ফর্ম যে শাখায় খোলা, জমাও সেই শাখায় — RCV-0143 / মালিক: "কোনোভাবেই অন্য শাখায় নয়" (ec, ১৯ অক্টোবর ২০২৬)।
 *
 * ── কী ঘটেছিল ────────────────────────────────────────────────────────
 * উপরের শাখা-বাছাই একজন মানুষের সব ট্যাবে একটাই (`users.current_branch_id`)। এক ট্যাবে Lion-এ রসিদ খুলে আরেক ট্যাবে
 * Super বাছলে, প্রথম ট্যাবের Save সেবার হাতে `CompanyContext::branchId()` = Super দিত — কাগজ বসত Super-এ।
 *
 * ── ⭐ যা করে ──────────────────────────────────────────────────────────
 * `@csrf` এখন `_once`-এর পাশে `_branch` বসায় — পাতা আঁকার মুহূর্তের কাজের শাখার `public_id` (শাখা না থাকলে
 * [[NONE]])। ⓘ ১৪৭টা ফর্মের কোনোটায় হাতে কিছু লিখতে হয় না, `_once`-এর একই যুক্তি ([[FormIsNotSubmittedTwice]])।
 * [[App\Http\Middleware\KeepsAFormInItsOwnBranch]] জমার সময় মেলায়; না মিললে থামায় আর "X-এই জমা দিন" বোতাম দেয়।
 *
 * ⚠️ "সব শাখা" দেখা কাজের শাখা বদলায় না ([[WorkspaceController::switchBranch()]]) — সেবাগুলো কাজের শাখাই নেয়, তাই
 * ছাপটাও কাজের শাখার। দেখা বদলালে কাগজের শাখা বদলায় না, তাই সেটা অমিলও নয়।
 */
final class FormBranchPin
{
    /** ফর্মে যে ঘরটা শাখার ছাপ বয়ে আনে। */
    public const FIELD = '_branch';

    /** "খোলার শাখাতেই জমা দিন" বোতামের সই-করা চিরকুট। */
    public const CONFIRM = '_branch_confirm';

    /** কাজের কোনো শাখা ছিল না — খালি ঘর নয়, কারণ খালি ঘর `null` হয়ে "ছাপ নেই"-র সাথে গুলিয়ে যেত। */
    public const NONE = 'none';

    /** থামানোর পর পাতায় যা দেখানো হয় (বার্তা, বোতাম) — [[shell.branch-pin]]। */
    public const OFFER = 'branch_pin_offer';

    /** এক লাইনের খবর — ছাড়ের জানালায় ছাপহীন জমা, বা খোলার শাখায় জমার ফল। */
    public const NOTICE = 'branch_pin_notice';

    /** বোতামটা কতক্ষণ চলে। */
    public const CONFIRM_MINUTES = 30;

    /**
     * ⛔ যে ঘরগুলো কখনো পাতায় ফেরে না — `bootstrap/app.php`-এর `dontFlash` আর Laravel-এর নিজের তালিকা।
     * ⓘ বোতামের ফর্ম আগের ইনপুট লুকানো ঘরে বসায়; পাসওয়ার্ড সেখানে গেলে পাতার উৎসেই পড়া যেত।
     */
    public const NEVER_FLASHED = ['password', 'password_confirmation', 'current_password', 'approver_password'];

    /** যন্ত্রের ঘর — ইনপুট নয়, তাই বোতামের ফর্মে আবার যায় না (নিজের নতুন `@csrf` নিজেই বসায়)। */
    private const META = ['_token', FormIsNotSubmittedTwice::FIELD, self::FIELD, self::CONFIRM, '_method'];

    public function __construct(private readonly FormIsNotSubmittedTwice $forms) {}

    /** ফর্মে বসার লুকানো ঘরটা — `@csrf` এটাই ডাকে। */
    public function field(): string
    {
        return '<input type="hidden" name="'.self::FIELD.'" value="'.e($this->stamp()).'">';
    }

    /**
     * এই মুহূর্তের কাজের শাখার ছাপ।
     *
     * ⓘ কর্মী ছাড়া (লগইন পাতা, গ্রাহকের পোর্টাল) [[NONE]] — পাহারাটা কেবল কর্মীর, আর তাতে কোনো কোয়েরিও যায় না।
     * ⓘ খোলসের ছাপার মাথা এমনিতেই `currentBranch` পড়ে, তাই সাধারণ পাতায় এটা বাড়তি কোয়েরি আনে না।
     */
    public function stamp(): string
    {
        $branchId = CompanyContext::branchId();
        $user = auth()->user();

        if ($branchId === null || ! $user instanceof User) {
            return self::NONE;
        }

        if ((int) $user->current_branch_id === $branchId) {
            $branch = $user->currentBranch;

            if ($branch !== null && (int) $branch->id === $branchId) {
                return (string) $branch->public_id;
            }
        }

        return (string) (Branch::acrossAllCompanies()->whereKey($branchId)->value('public_id') ?? self::NONE);
    }

    /**
     * শাখাহীন `*.store` — উপরের শাখা কখনো পড়ে না: সংযুক্তি নিজের কাগজের (আর ফাইল বোতামে আবার যায় না), তালিকার
     * সংরক্ষিত দৃশ্য মানুষের নিজের।
     */
    public const BRANCHLESS = ['attachment.store', 'views.store'];

    /** পাহারা কোন অনুরোধে — কেবল নতুন কাগজের POST (`*.store`); সম্পাদনা (PUT/PATCH) কাগজের নিজের শাখায় থাকে। */
    public function guards(Request $request): bool
    {
        $route = (string) $request->route()?->getName();

        return $request->isMethod('POST') && str_ends_with($route, '.store') && ! in_array($route, self::BRANCHLESS, true);
    }

    /**
     * ছাপহীন জমার ছাড় এখনো খোলা কি না — `abos.branch_pin.grace_until` (ISO তারিখ-সময়)।
     *
     * ⓘ খালি = কড়া, আর সেটাই ডিফল্ট। ⚠️ ভুল লেখা তারিখও কড়া — ছাড় কেবল স্পষ্ট করে দিলে।
     */
    public function inGrace(): bool
    {
        $until = trim((string) config('abos.branch_pin.grace_until', ''));

        if ($until === '') {
            return false;
        }

        try {
            return now()->lessThan(Carbon::parse($until));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * বোতামের চিরকুট — এই মানুষ, এই কোম্পানি, এই দরজা, এই শাখা; একবারই, ৩০ মিনিট।
     *
     * ⭐ এনক্রিপ্ট করা (Laravel-এর AES + MAC, `APP_KEY`) — বদলালে খোলে না, তাই অন্য শাখার নাম বসিয়ে বানানো যায় না।
     * একবারের নিশ্চয়তা `submitted_forms`-এর ইউনিক টোকেনে, `_once`-এর একই যন্ত্র ([[spend()]])।
     */
    public function offer(User $user, int $companyId, int $branchId, string $route, string $path): string
    {
        return Crypt::encryptString((string) json_encode([
            'u' => (int) $user->id,
            'c' => $companyId,
            'b' => $branchId,
            'r' => $route,
            'p' => $path,
            'n' => $this->forms->newToken(),
            'e' => now()->addMinutes(self::CONFIRM_MINUTES)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * চিরকুটটা এই অনুরোধের কি না — হলে তার ভিতরটা, নইলে `null`। ⚠️ খরচ করে না; সেটা [[spend()]]।
     *
     * @return array{u: int, c: int, b: int, r: string, p: string, n: string, e: int}|null
     */
    public function read(string $token, User $user, int $companyId, string $route, string $path): ?array
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($payload) || ! is_int($payload['b'] ?? null) || ! is_string($payload['n'] ?? null)) {
            return null;
        }

        $mine = (int) ($payload['u'] ?? 0) === (int) $user->id
            && (int) ($payload['c'] ?? 0) === $companyId
            && ($payload['r'] ?? null) === $route
            && ($payload['p'] ?? null) === $path
            && (int) ($payload['e'] ?? 0) >= now()->getTimestamp();

        /** @var array{u: int, c: int, b: int, r: string, p: string, n: string, e: int} $payload */
        return $mine ? $payload : null;
    }

    /** চিরকুট খরচ — প্রথমবার `true`, আবার এলে `false`। */
    public function spend(array $payload, User $user, string $route): bool
    {
        return $this->forms->claim((string) $payload['n'], (int) $user->id, 'branch_pin:'.$route);
    }

    /**
     * আগের ইনপুট লুকানো ঘর হিসেবে — `lines[0][qty]` ধাঁচের নাম, যাতে PHP ঠিক একই গড়ন ফিরে পায়।
     *
     * @param  array<array-key, mixed>  $input
     * @return list<array{0: string, 1: string}>
     */
    public function hiddenFields(array $input, string $prefix = ''): array
    {
        $fields = [];

        foreach ($input as $key => $value) {
            if ($prefix === '' && (in_array($key, self::META, true) || in_array($key, self::NEVER_FLASHED, true))) {
                continue;
            }

            $name = $prefix === '' ? (string) $key : $prefix.'['.$key.']';

            if (is_array($value)) {
                array_push($fields, ...$this->hiddenFields($value, $name));

                continue;
            }

            if ($value === null || is_scalar($value)) {
                $fields[] = [$name, is_bool($value) ? ($value ? '1' : '0') : (string) $value];
            }
        }

        return $fields;
    }

    /** শাখার নাম মানুষের ভাষায় — শাখা না থাকলে "কোনো শাখা নয়"। */
    public function nameOf(?int $branchId): string
    {
        $branch = $branchId === null ? null : Branch::acrossAllCompanies()->whereKey($branchId)->first();

        return $branch?->name() ?? __('core.branch_pin.no_branch');
    }
}
