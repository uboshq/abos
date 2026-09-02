<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Engines\Sync\SyncService;
use App\Core\Security\CredentialCheck;
use App\Core\Security\MfaCodeRequired;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ফোনের ঢোকা ও বেরোনো — টোকেনে, সেশনে নয়।
 *
 * ── কেন যাচাইটা এখানে লেখা নেই ──────────────────────────────────────
 * পুরোটাই [[CredentialCheck]]-এ, আর ওয়েবের দরজাও ঠিক সেটাই ডাকে। এখানে
 * নকল করলে **তালা, throttle আর `login_history` তিনটাই কেবল ওয়েবের
 * দরজায় থাকত** — অর্থাৎ আক্রমণকারী শুধু প্রথম দরজাটা ব্যবহার করা বন্ধ
 * করে দিতেন। দুইটার একটাতে তালা মানে তালা নেই।
 *
 * ── কেন দুইটা টোকেন, একটা নয় ───────────────────────────────────────
 * একটা লম্বা-মেয়াদি টোকেন সহজ, কিন্তু সেটা ফোনে মাসের পর মাস বসে
 * থাকে আর প্রতিটা অনুরোধে যায়। চুরি গেলে মেয়াদ শেষ না হওয়া পর্যন্ত
 * খোলা।
 *
 * তাই ছোট মেয়াদের **access** টোকেন যায় প্রতিটা অনুরোধে, আর লম্বা
 * মেয়াদের **refresh** টোকেন যায় কেবল নবায়নের সময় — দিনে কয়েকবার।
 * চুরির জানালাটা ৩০ মিনিট, চিরকাল নয়।
 *
 * ── ⚠️ ability কেন অপরিহার্য ───────────────────────────────────────
 * `auth:sanctum` **যেকোনো** বৈধ টোকেন মেনে নেয় — refresh টোকেনও।
 * সেটা না আটকালে চুরি যাওয়া refresh টোকেন দিয়েই সরাসরি সিঙ্কের
 * সব দরজা খোলা যেত, আর নবায়নের পুরো ব্যবস্থাটা অর্থহীন হত।
 *
 * তাই সিঙ্কের রুটগুলো `abilities:sync` চায়, আর নবায়নের রুটটা
 * `abilities:refresh`। **দুইটা টোকেন, দুইটা আলাদা কাজ, কোনোটাই
 * অন্যটার কাজ করতে পারে না।**
 */
class AuthController extends Controller
{
    /** প্রতিটা অনুরোধে যায় — তাই ছোট। */
    private const ACCESS_MINUTES = 30;

    /** কেবল নবায়নে যায় — তাই লম্বা, কিন্তু অসীম নয়। */
    private const REFRESH_DAYS = 30;

    public const ACCESS = 'sync';

    public const REFRESH = 'refresh';

    public function __construct(
        private readonly CredentialCheck $credentials,
        private readonly SyncService $sync,
    ) {}

    /**
     * `POST /auth/login`
     *
     * ⚠️ রুটে `throttle` বসানো আছে, আর সেটা এখানে সরানো যায় না —
     * throttle রুটের জিনিস। ওয়েবের দরজায় `throttle:10,1`; এই দরজাটা
     * তার চেয়ে ঢিলা হলে আক্রমণকারী শুধু এটাই ব্যবহার করতেন।
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'max:16'],
            'deviceId' => ['required', 'string', 'max:64'],
            'appVersion' => ['nullable', 'string', 'max:32'],
            'platform' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $user = $this->credentials->verify(
                $data['identifier'],
                $data['password'],
                $data['code'] ?? null,
            );
        } catch (MfaCodeRequired $needsCode) {
            /*
             * ৪০৯, ৪২২ নয় — অনুরোধটা ভুল ছিল না, **অসম্পূর্ণ** ছিল।
             *
             * `needsCode` পতাকাটা দিয়ে অ্যাপ কোডের পর্দাটা খোলে। বার্তার
             * লেখা পড়ে সিদ্ধান্ত নিলে অনুবাদ বদলানোর দিন পর্দাটা নীরবে
             * ভেঙে যেত।
             */
            return response()->json([
                'needsCode' => true,
                'codeWasWrong' => $needsCode->wasWrong,
                'message' => $needsCode->getMessage(),
            ], 409);
        }

        /*
         * ⚠️ ঢোকার সাথে সাথেই কোম্পানির প্রসঙ্গ — টোকেন বানানোর আগেই।
         *
         * [[SyncService::register()]] `sync_devices`-এ লেখে, আর ওই
         * মডেলটা `BelongsToCompany`। প্রসঙ্গ ছাড়া লিখতে গেলে সেটা
         * ব্যতিক্রম ছুঁড়ত — যা ঠিকই করত, কিন্তু লগইনটা ৫০০ হয়ে যেত।
         *
         * ওয়েবে এই কাজটা করে `ResolveCompanyContext` মিডলওয়্যার, কিন্তু
         * সেটা `auth:sanctum`-এর পরে চলে — আর এই রুটে লগইনের আগে কোনো
         * টোকেনই নেই।
         */
        CompanyContext::set(
            $user->current_company_id,
            $user->current_branch_id,
        );

        $this->sync->register(
            $user,
            $data['deviceId'],
            $data['appVersion'] ?? null,
            $data['platform'] ?? null,
        );

        $this->credentials->recordSuccess($data['identifier'], $user);

        return response()->json($this->issue($user, $data['deviceId']));
    }

    /**
     * `POST /auth/refresh` — নতুন জোড়া, পুরনোটা বাতিল।
     *
     * ── কেন পুরনো refresh টোকেনটা মুছে ফেলা হয় (rotation) ────────────
     * নবায়নের পরেও পুরনোটা বৈধ থাকলে চুরি যাওয়া একটা টোকেন **চিরকাল**
     * নতুন access টোকেন বানাতে পারত — লম্বা মেয়াদটা তখন কার্যত অসীম।
     *
     * ঘোরানোর দ্বিতীয় লাভ: একটা টোকেন দুইবার ব্যবহার হলে সেটা চুরির
     * চিহ্ন, আর এখন সেটা ধরা পড়ে (দ্বিতীয়বার ৪০১)।
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();
        /*
         * বার্তাসহ, কারণ `abilities` মিডলওয়্যারও ৪০৩ দেয় — আর দুইটা
         * খালি ৪০৩ দেখতে হুবহু এক। ব্যর্থ টেস্ট তখন বলে "কোথাও একটা
         * দেয়াল", কোনটা তা বলে না; আর ওই অস্পষ্টতাই মাপা আটকে দেয়।
         */
        abort_unless($user instanceof User, 403, 'staff-token-only');

        $data = $request->validate([
            'deviceId' => ['required', 'string', 'max:64'],
        ]);

        $current = $request->user()->currentAccessToken();

        if ($current instanceof PersonalAccessToken) {
            $current->delete();
        }

        return response()->json($this->issue($user, $data['deviceId']));
    }

    /**
     * `POST /auth/logout` — এই ফোনের সব টোকেন বাতিল।
     *
     * ── কেন এই ডিভাইসেরগুলোই, সবগুলো নয় ────────────────────────────
     * একজনের দুইটা ফোন থাকতে পারে। একটা থেকে বেরোলে অন্যটাও বেরিয়ে
     * যাওয়া মানে গুদামের ট্যাবটা বন্ধ হয়ে যেত কারণ সেলসম্যান নিজের
     * ফোনে লগআউট করেছেন।
     *
     * ⚠️ `sync_devices`-এর সারিটা **থাকে**। ওটা পরিচয় নয়, একটা
     * হ্যান্ডসেট — আর ওয়াটারমার্কটা ওই হ্যান্ডসেটের, ব্যক্তির নয়।
     * মুছে দিলে পরের লগইনে পুরো ক্যাটালগ আবার নামত।
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        /*
         * বার্তাসহ, কারণ `abilities` মিডলওয়্যারও ৪০৩ দেয় — আর দুইটা
         * খালি ৪০৩ দেখতে হুবহু এক। ব্যর্থ টেস্ট তখন বলে "কোথাও একটা
         * দেয়াল", কোনটা তা বলে না; আর ওই অস্পষ্টতাই মাপা আটকে দেয়।
         */
        abort_unless($user instanceof User, 403, 'staff-token-only');

        $deviceId = trim((string) $request->input('deviceId', ''));

        $tokens = $user->tokens();

        if ($deviceId !== '') {
            /*
             * ⚠️ `whereIn`, দুইটা শর্ত `orWhere` দিয়ে নয়।
             *
             * ── কী ভাঙত ─────────────────────────────────────────────
             * `$user->tokens()` মানে `tokenable_id = X`। তার সাথে
             * `->where(A)->orWhere(B)` জুড়লে SQL দাঁড়ায়
             *
             *     WHERE tokenable_id = X AND name = A OR name = B
             *
             * আর `AND`-এর অগ্রাধিকার বেশি, তাই এটা পড়া হয়
             *
             *     (tokenable_id = X AND name = A) OR (name = B)
             *
             * অর্থাৎ **`orWhere`-টা ব্যবহারকারীর সীমানা ছাড়িয়ে যেত**:
             * একজন লগআউট করলে ওই একই deviceId-র নামে অন্য যেকোনো
             * ব্যবহারকারীর refresh টোকেনও মুছে যেত। একটা ফোন হাতবদল
             * হলে ঠিক ওই নামটাই আবার ব্যবহার হয়, তাই ঘটনাটা কল্পনা নয়।
             *
             * লেখার সময়ই ধরা পড়েছে, চলার আগে — কিন্তু ধরনটা মনে রাখার
             * মতো: **`orWhere` সবসময় একটা গোষ্ঠীর ভেতরে**, নাহলে সে
             * উপরের প্রতিটা শর্ত বাতিল করে দেয়।
             */
            $tokens->whereIn('name', [
                $this->tokenName($deviceId, self::ACCESS),
                $this->tokenName($deviceId, self::REFRESH),
            ]);
        }

        $tokens->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * একটা নতুন জোড়া।
     *
     * টোকেনের নামে `deviceId` বসানো হয় যাতে লগআউট ঠিক ওই ফোনেরগুলোই
     * বাতিল করতে পারে — Sanctum টোকেনের সাথে ডিভাইসের কোনো সম্পর্ক
     * নিজে থেকে রাখে না।
     *
     * @return array<string, mixed>
     */
    private function issue(User $user, string $deviceId): array
    {
        /*
         * একই ডিভাইসের পুরনো জোড়াটা আগে সরানো হয়।
         *
         * নাহলে প্রতিটা লগইনে দুইটা করে সারি জমত, আর ছয় মাস পরে একটা
         * ফোনের পঞ্চাশটা বৈধ টোকেন থাকত — যার প্রতিটাই চুরির একটা
         * সুযোগ, অথচ একটাও ব্যবহার হচ্ছে না।
         */
        $user->tokens()
            ->whereIn('name', [
                $this->tokenName($deviceId, self::ACCESS),
                $this->tokenName($deviceId, self::REFRESH),
            ])
            ->delete();

        $access = $user->createToken(
            $this->tokenName($deviceId, self::ACCESS),
            [self::ACCESS],
            now()->addMinutes(self::ACCESS_MINUTES),
        );

        $refresh = $user->createToken(
            $this->tokenName($deviceId, self::REFRESH),
            [self::REFRESH],
            now()->addDays(self::REFRESH_DAYS),
        );

        return [
            /*
             * নামগুলো `api_client.dart` যা পড়ে — `accessToken` আর
             * `refreshToken`। বদলালে ফোন নবায়ন করতে পারবে না, আর
             * ব্যর্থতাটা দেখাবে "সেশন শেষ" হিসেবে, যা কারণ লুকায়।
             */
            'accessToken' => $access->plainTextToken,
            'refreshToken' => $refresh->plainTextToken,
            'expiresInSeconds' => self::ACCESS_MINUTES * 60,
            'user' => [
                'id' => (string) $user->public_id,
                'name' => $user->name,
                'email' => $user->email,

                /*
                 * রোল ও অনুমতি — অ্যাপ এগুলো দিয়ে **মেনু** সাজায়,
                 * সিদ্ধান্ত নেয় না।
                 *
                 * ⚠️ ফোনে লুকানো একটা সারি নিরাপত্তা নয়। আসল ছাঁকনি
                 * সার্ভারে: প্রতিটা `SyncsToDevices::requiredPermission()`
                 * আর প্রতিটা মডিউলের নিজের নিয়ম। এটা কেবল যাতে
                 * সেলসম্যানকে এমন বোতাম দেখানো না হয় যেটা চাপলে ৪০৩।
                 */
                'roles' => $user->getRoleNames()->all(),
                'permissions' => $user->getAllPermissions()->pluck('name')->all(),
            ],
        ];
    }

    private function tokenName(string $deviceId, string $kind): string
    {
        return $kind.':'.$deviceId;
    }
}
