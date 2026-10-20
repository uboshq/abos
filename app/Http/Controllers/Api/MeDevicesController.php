<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Engines\Audit\AuditEngine;
use App\Http\Controllers\Controller;
use App\Models\SyncDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * ⭐ "আমার ফোনগুলো" — কোন কোন ফোনে আমি ঢোকা, আর একটা থেকে বের করে দেওয়া (প্রোফাইল মেনু, অ্যাপ ০.৪.৪১; a6, ২০ অক্টোবর ২০২৬)।
 *
 * ── এক সারি = এক ফোন, টোকেন নয় ────────────────────────────────────────
 * প্রতিটা লগইনে [[AuthController::issue()]] দুইটা টোকেন বানায় — `sync:<deviceId>` (access, ৩০ মিনিট) আর
 * `refresh:<deviceId>` (৩০ দিন)। ⓘ নামের `:`-এর পরের অংশটাই ফোনের নিজের id (অ্যাপের `TokenStorage.deviceId()`), তাই
 * ঐ অংশ ধরে জোড়া বাঁধা হয়। ⛔ টোকেন ধরে সারি দিলে এক ফোন দুইবার দেখাত, আর একটা মুছলে অন্যটা বেঁচে থাকত।
 *
 * ── ⛔ id অস্বচ্ছ ─────────────────────────────────────────────────────
 * ফোনের কাঁচা deviceId বা টোকেনের সারি-নম্বর বাইরে যায় না: id = মানুষ + deviceId-র HMAC (অ্যাপের চাবিতে)। ⓘ টোকেনের
 * সারি-নম্বর প্রতিটা নবায়নে বদলায় (পুরনো মুছে নতুন), তাই সেটা id হলে তালিকা খোলা থাকতেই "বের করুন" ৪০৪ দিত।
 *
 * ── ⚠️ নাম কোথা থেকে ──────────────────────────────────────────────────
 * লগইন কেবল `platform` (android/ios) আর `appVersion` পাঠায়, ফোনের মডেল নয় ([[SyncService::register()]] → `sync_devices`)।
 * তাই নাম = "Android ফোন · ০.৪.৪০ · #AB12" — শেষের চার অক্ষর id-র, যাতে একই রকম দুইটা ফোন আলাদা চেনা যায়।
 *
 * ⓘ কেবল ফোনের টোকেন — ওয়েবের সেশন এখানে নয়; সেগুলোর নিজের পাতা আছে (governance, "বের করে দিন"), ছোঁয়া হয়নি।
 */
final class MeDevicesController extends Controller
{
    public function __construct(private readonly AuditEngine $audit) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->staff($request);
        $current = $this->currentDeviceOf($user);

        $rows = $this->devicesOf($user)
            // ⓘ মেয়াদ শেষ জোড়া "ঢোকা" নয় — নবায়নের টোকেনও শেষ হলে ফোনটা আবার লগইন চাইবে
            ->filter(fn (array $device): bool => $device['live'])
            // ⭐ সবচেয়ে সাম্প্রতিক ব্যবহার আগে
            ->sortByDesc(fn (array $device): int => $device['last_used_at']?->getTimestamp() ?? 0)
            ->map(fn (array $device): array => [
                'id' => $device['id'],
                'name' => $device['name'],
                'platform' => $device['platform'],
                'app_version' => $device['app_version'],
                'last_used_at' => $device['last_used_at']?->toIso8601String(),
                'created_at' => $device['created_at']?->toIso8601String(),
                'current' => $current !== null && $device['device'] === $current,
            ])
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * ⛔ একটা ফোন থেকে বের করে দেওয়া — access আর refresh দুইটাই (চুরি যাওয়া ফোনের উত্তর)।
     *
     * ⓘ কেবল নিজের: খোঁজা হয় নিজের টোকেনের মধ্যেই, তাই অন্যের id এখানে মেলেই না → ৪০৪। ⭐ নিজের এই ফোনটাও বের করা
     * যায় — তখন এই ফোনেরই পরের অনুরোধ ৪০১। ⚠️ refresh টোকেনও যায়, নাহলে ঐ ফোন ৪০১ পেয়ে নবায়ন করে আবার ঢুকে পড়ত।
     */
    public function destroy(Request $request, string $device): Response
    {
        $user = $this->staff($request);

        $found = $this->devicesOf($user)->firstWhere('id', $device);

        abort_if($found === null, 404);

        $user->tokens()
            ->whereIn('name', [
                AuthController::ACCESS.':'.$found['device'],
                AuthController::REFRESH.':'.$found['device'],
            ])
            ->delete();

        // ⓘ লগআউটের মতোই — বের হওয়া ফোনে আর ঘণ্টার খবর যায় না ([[AuthController::logout()]])
        SyncDevice::query()->withoutGlobalScopes()
            ->where('device_id', $found['device'])->where('user_id', $user->id)
            ->update(['push_token' => null, 'push_token_at' => null]);

        // ⓘ কারণের ঘরে কেবল ফোনের নাম — টোকেন বা কাঁচা deviceId খাতায় নয়
        $this->audit->recordAction($user, 'device_signed_out', $found['name']);

        return response()->noContent();
    }

    private function staff(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403, 'staff-token-only');

        return $user;
    }

    /**
     * @return Collection<int, array{id: string, device: string, name: string, platform: ?string, app_version: ?string,
     *     last_used_at: ?Carbon, created_at: ?Carbon, live: bool}>
     */
    private function devicesOf(User $user): Collection
    {
        $tokens = $user->tokens()
            ->where(fn ($q) => $q->where('name', 'like', AuthController::ACCESS.':%')
                ->orWhere('name', 'like', AuthController::REFRESH.':%'))
            ->get(['id', 'name', 'last_used_at', 'created_at', 'expires_at']);

        $byDevice = $tokens->groupBy(fn (PersonalAccessToken $token): string => (string) $this->deviceIn((string) $token->name))
            ->forget('');

        if ($byDevice->isEmpty()) {
            return collect();
        }

        $phones = SyncDevice::query()->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->whereIn('device_id', $byDevice->keys()->all())
            ->get(['device_id', 'platform', 'app_version'])
            ->keyBy('device_id');

        // ⚠️ সংখ্যার মতো deviceId-কে PHP চাবিতে int বানায় — তাই এখানে আবার লেখা-রূপে
        return $byDevice->map(function (Collection $pair, int|string $device) use ($user, $phones): array {
            $device = (string) $device;
            $id = substr(hash_hmac('sha256', $user->id.'|'.$device, (string) config('app.key')), 0, 32);
            $phone = $phones->get($device);

            $used = $pair->pluck('last_used_at')->filter()->max();

            return [
                'id' => $id,
                'device' => $device,
                'name' => $this->nameOf($phone?->platform, $phone?->app_version, $id),
                'platform' => $phone?->platform,
                'app_version' => $phone?->app_version,
                // ⓘ কখনো ব্যবহার হয়নি (এইমাত্র ঢোকা) — তখন টোকেন বানানোর সময়, যাতে সাজানো আর "শেষ কবে" দুটোই অর্থ রাখে
                'last_used_at' => $used ?? $pair->pluck('created_at')->filter()->max(),
                // ⚠️ নবায়নে জোড়া নতুন হয়, তাই এটা "শেষ লগইন বা নবায়ন" — প্রথম লগইনের দিন কোথাও লেখা নেই (মাইগ্রেশন ছাড়া)
                'created_at' => $pair->pluck('created_at')->filter()->min(),
                'live' => $pair->contains(fn (PersonalAccessToken $token): bool => $token->expires_at === null || $token->expires_at->isFuture()),
            ];
        })->values();
    }

    private function deviceIn(string $name): ?string
    {
        foreach ([AuthController::ACCESS, AuthController::REFRESH] as $kind) {
            if (str_starts_with($name, $kind.':') && strlen($name) > strlen($kind) + 1) {
                return substr($name, strlen($kind) + 1);
            }
        }

        return null;
    }

    private function currentDeviceOf(User $user): ?string
    {
        $token = $user->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $this->deviceIn((string) $token->name) : null;
    }

    private function nameOf(?string $platform, ?string $version, string $id): string
    {
        $kind = match (strtolower((string) $platform)) {
            'android' => __('core.devices.android'),
            'ios' => __('core.devices.ios'),
            default => __('core.devices.phone'),
        };

        $parts = [$kind];

        if (filled($version)) {
            $parts[] = (string) $version;
        }

        $parts[] = '#'.strtoupper(substr($id, 0, 4));

        return implode(' · ', $parts);
    }
}
