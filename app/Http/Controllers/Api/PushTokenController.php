<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SyncDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ফোনের FCM টোকেন নিবন্ধন — `POST /api/v1/devices/push-token` (২ অক্টোবর ২০২৬, [[FcmSender]])।
 *
 * ⛔ কেবল নিজের ফোন: সারিটা এই ব্যবহারকারীর এই `deviceId`-এর — অন্যের ডিভাইসে টোকেন বসানো যায় না (৪০৪)।
 * ⛔ সমন্বয়কের শর্ত ১: একই টোকেন অন্য কোনো সারিতে থাকলে (একই ফোনে আগে অন্যজন ঢুকেছিলেন) সেখান থেকে মোছা —
 * নাহলে আগের জনের বার্তা এই ফোনে আসত। `token` ফাঁকা পাঠালে নিজেরটাও মোছে (বার্তা বন্ধ)।
 */
class PushTokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'deviceId' => ['required', 'string', 'max:64'],
            'token' => ['nullable', 'string', 'max:255'],
        ]);

        $device = SyncDevice::query()->withoutGlobalScopes()
            ->where('device_id', $data['deviceId'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $token = trim((string) ($data['token'] ?? '')) ?: null;

        /*
         * ⛔ অন্যের ফোনের টোকেন নিজের নামে নয় — পুরো ERP অডিট, ৯ অক্টোবর ২০২৬। ⓘ ফোনপ্রতি সারি একটাই (`device_id` অনন্য),
         * আর একই ফোনে লোক বদলালে সারিটাই নতুন জনের হয় — তাই অন্য সারিতে একই টোকেন মানে আরেকটা ইনস্টল। নিজের পুরনো ইনস্টল
         * হলে টোকেন সরে আসে (আগের মতো); আরেকজনের হলে ৪০৯, কিছু বদলায় না। আগে যেকোনো সারি থেকে কেড়ে নেওয়া যেত: কেউ অন্যের
         * টোকেন জানলে তাঁর পুশ বন্ধ করে নিজের দিকে নিতে পারতেন।
         */
        if ($token !== null && SyncDevice::query()->withoutGlobalScopes()
            ->where('push_token', $token)
            ->whereKeyNot($device->id)
            ->where('user_id', '!=', $device->user_id)
            ->exists()) {
            abort(409, __('mobile.push_token_elsewhere'));
        }

        DB::transaction(function () use ($device, $token): void {
            if ($token !== null) {
                SyncDevice::query()->withoutGlobalScopes()
                    ->where('push_token', $token)
                    ->whereKeyNot($device->id)
                    ->update(['push_token' => null, 'push_token_at' => null]);
            }

            $device->forceFill(['push_token' => $token, 'push_token_at' => $token === null ? null : now()])->save();
        });

        return response()->json(['ok' => true]);
    }
}
