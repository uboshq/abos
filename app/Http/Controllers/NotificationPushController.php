<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Notifications\ChannelRegistry;
use App\Core\Services\NotificationAudit;
use App\Core\Support\CompanyContext;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * ⭐ এই ব্রাউজারে Web Push — নিজের সাবস্ক্রিপশন বসানো আর তোলা (মালিকের স্পেক §৭ "Permission, Subscription, Expiry,
 * Revocation"; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ২)।
 *
 * ⓘ নিজের ব্রাউজার, নিজের পছন্দ — কারও চাবি লাগে না, আর অনুমতিটা ব্রাউজার নিজে চায়। একই ব্রাউজারে অন্য কেউ লগইন করে
 * সাবস্ক্রাইব করলে সারিটা তাঁর নামে চলে যায় — একটা ব্রাউজারে একজনের খবরই আসে।
 * ⛔ ব্রাউজারের চাবি এনক্রিপ্ট করা থাকে; ঠিকানা কেবল https।
 */
class NotificationPushController extends Controller
{
    public function subscribe(Request $request): JsonResponse
    {
        // ⓘ ওয়েবের পথে ভুল ইনপুট ফেরত-পাঠানো (redirect) হয় — ব্রাউজারের fetch সেটাকে সফল ভাবত; তাই এখানে সরাসরি ৪২২ JSON
        $check = Validator::make($request->all(), [
            'endpoint' => ['required', 'string', 'max:1000', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:200'],
            'keys.auth' => ['required', 'string', 'max:100'],
            'expirationTime' => ['nullable', 'integer'],
        ]);

        if ($check->fails()) {
            return response()->json(['errors' => $check->errors()], 422);
        }

        $data = $check->validated();

        $connected = app(ChannelRegistry::class)->connected((int) CompanyContext::id(), NotificationChannel::WEB_PUSH);
        abort_unless($connected, 409, __('core.notify.push_not_connected'));

        $hash = hash('sha256', $data['endpoint']);

        $row = NotificationSubscription::query()->withoutGlobalScopes()->firstOrNew(['endpoint_hash' => $hash]);
        $row->forceFill([
            'company_id' => CompanyContext::id(),
            'user_id' => $request->user()->id,
            'endpoint' => $data['endpoint'],
            'keys' => ['p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']],
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 191),
            'expires_at' => isset($data['expirationTime']) ? Carbon::createFromTimestampMs((int) $data['expirationTime'], 'UTC') : null,
            'revoked_at' => null,
        ])->save();

        app(NotificationAudit::class)->record('push_subscribe', $row);

        return response()->json(['data' => ['subscribed' => true]]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:1000']]);

        $row = NotificationSubscription::query()->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->where('user_id', $request->user()->id)->first();

        if ($row !== null && $row->revoked_at === null) {
            $row->forceFill(['revoked_at' => now()])->save();
            app(NotificationAudit::class)->record('push_unsubscribe', $row);
        }

        return response()->json(['data' => ['subscribed' => false]]);
    }
}
