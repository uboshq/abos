<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Security\LoginLock;
use App\Core\Security\MfaService;
use App\Core\Services\LoginJournal;
use App\Core\Services\WebInsideApp;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Models\SyncDevice;
use App\Models\User;
use App\Models\WebHandoff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * `POST /api/v1/web-handoff` — অ্যাপের ভিতরে ওয়েবের একটা পর্দা খোলার একবারের চাবি (মালিক, ১৩ অক্টোবর ২০২৬:
 * *"web e na dhuke jar jar kaj 100% app ei korte pare"*)।
 *
 * ── কে পান ────────────────────────────────────────────────────────────────
 * যে কর্মী ওয়েবে ঢুকতে পারেন — চাবি তাঁকে ওয়েবে তাঁর নিজের চেয়ে একটুও বেশি দেয় না: ওয়েবের প্রতিটা পর্দা নিজের পাহারা
 * নিজে দেখে। ⛔ কোম্পানির সুইচ `mobile.web_inside_app` বন্ধ থাকলে কেউ পান না।
 *
 * ── ⛔ চাবির নিয়ম ─────────────────────────────────────────────────────────
 *   ⭐ ২৫৬-বিটের এলোমেলো লেখা, সার্ভারে কেবল sha256; ৬০ সেকেন্ড; একবার।
 *   ⭐ মানুষ + হ্যান্ডসেট (টোকেনের নিজের নাম থেকে, ফোনের পাঠানো লেখা থেকে নয়) + চলতি কোম্পানি + শাখায় বাঁধা।
 *   ⭐ দুই ধাপ চালু থাকলে এই হ্যান্ডসেটকে কোড পেরোতে হয় — লগইনে পেরিয়ে থাকলে আবার নয় ([[WebInsideApp::secondStepFresh()]])।
 *     ভুল কোড ওয়েবের তালায় গোনা হয় ([[LoginLock]]), আর তালা পড়লে চাবিও নয়।
 *   ⭐ গন্তব্য কেবল সাইটের ভিতরের লগইন-চাওয়া পর্দা ([[WebInsideApp::target()]])।
 *   ⭐ রুটে `throttle:30,1` — জনপ্রতি।
 */
class WebHandoffApiController extends Controller
{
    public function __construct(
        private readonly WebInsideApp $inside,
        private readonly MfaService $mfa,
        private readonly LoginLock $lock,
        private readonly LoginJournal $logins,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403, 'staff-token-only');

        $data = $request->validate([
            'path' => ['required', 'string', 'max:512'],
            'code' => ['nullable', 'string', 'max:16'],
        ]);

        if (! $this->inside->isOn()) {
            return $this->refuse(__('mobile.web_inside_app_off'), 'web_inside_app_off', 403);
        }

        $companyId = CompanyContext::id();

        if ($companyId === null) {
            return $this->refuse(__('auth.no_company'), 'no_company', 403);
        }

        $device = $this->deviceOf($request, $user);

        if ($device === null) {
            return $this->refuse(__('mobile.web_handoff_device'), 'device_unknown', 403);
        }

        $target = $this->inside->target($data['path'], $user);

        if ($target === null) {
            return $this->refuse(__('mobile.web_handoff_path'), 'path_refused', 422);
        }

        // ⛔ দুই ধাপ বাধ্যতামূলক অথচ বসানো নেই — ওয়েবও তাঁকে বসানোর পর্দা ছাড়া কিছু দেখায় না
        if ($this->inside->twoStepMissing($user)) {
            return $this->refuse(__('auth.two_step_set_up_on_web_first'), 'two_step_missing', 403);
        }

        if (! $this->inside->secondStepFresh($user, $device)) {
            $refused = $this->secondStep($user, $device, $data['code'] ?? null);

            if ($refused !== null) {
                return $refused;
            }
        }

        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        WebHandoff::query()->create([
            'company_id' => $companyId,
            'branch_id' => CompanyContext::branchId(),
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'device_hash' => hash('sha256', (string) $device->device_id),
            'target' => $target,
            'ip_address' => $request->ip(),
            'expires_at' => now()->addSeconds(WebInsideApp::TTL_SECONDS),
        ]);

        return response()->json([
            'url' => route('web_handoff', ['token' => $plain]),
            'expiresInSeconds' => WebInsideApp::TTL_SECONDS,
        ]);
    }

    /**
     * এই টোকেনের হ্যান্ডসেট — টোকেনের নামে (`sync:<deviceId>`, [[AuthController::issue()]]), আর সেটা এই মানুষেরই।
     */
    private function deviceOf(Request $request, User $user): ?SyncDevice
    {
        $token = $user->currentAccessToken();
        $prefix = AuthController::ACCESS.':';

        if (! $token instanceof PersonalAccessToken || ! str_starts_with((string) $token->name, $prefix)) {
            return null;
        }

        $deviceId = substr((string) $token->name, strlen($prefix));

        if ($deviceId === '') {
            return null;
        }

        return SyncDevice::query()->withoutGlobalScopes()
            ->where('device_id', $deviceId)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * দুই ধাপের কোড — ওয়েবের লগইনের মতোই: তালা আগে, কোড না দিলে ৪০৯ `needsCode`, ভুল হলে ৪০৯ `codeWasWrong`।
     */
    private function secondStep(User $user, SyncDevice $device, ?string $code): ?JsonResponse
    {
        $identifier = WebInsideApp::identifierOf($user);

        if (($minutes = $this->lock->locked($identifier)) !== null) {
            $this->logins->failed($identifier, $user, LoginAttempt::LOCKED);

            return $this->refuse(__('auth.locked', ['minutes' => $minutes]), 'locked', 429);
        }

        $code = trim((string) $code);

        if ($code === '') {
            $this->logins->failed($identifier, $user, LoginAttempt::NEEDS_CODE);

            return response()->json(['needsCode' => true, 'codeWasWrong' => false, 'message' => __('auth.code_needed')], 409);
        }

        if (! $this->mfa->verify($user, $code)) {
            $this->logins->failed($identifier, $user, LoginAttempt::WRONG_CODE);

            return response()->json(['needsCode' => true, 'codeWasWrong' => true, 'message' => __('auth.code_wrong')], 409);
        }

        // ⓘ কোম্পানির স্কোপ ছাড়া — হ্যান্ডসেটের সারি কোম্পানি বদলের পরে আগের কোম্পানিতে থাকতে পারে ([[SyncService::register()]])
        SyncDevice::query()->withoutGlobalScopes()->whereKey($device->getKey())->update(['second_step_at' => now()]);

        return null;
    }

    private function refuse(string $message, string $reason, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'reason' => $reason], $status);
    }
}
