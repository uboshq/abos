<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Services\SmsOutboxService;
use App\Http\Controllers\Controller;
use App\Models\SmsGatewayPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ⭐ অফিসের SMS ফোনের দরজা — ফোন এসে সারি থেকে SMS নেয়, পাঠিয়ে খবর দেয় (মালিকের "ok", নমুনা U1X687u5MkwVfEL7ci7MGv ধাপ ২)।
 *
 * 🔒 মানুষের লগইন নয় — ফোনের নিজের চাবি (`Authorization: Bearer smsgw_…`), ডাটাবেজে কেবল তার sha256 ([[SmsGatewayPhone::byToken()]])।
 *   চাবি ভুল, বা ফোন বন্ধ করা হলে ৪০১; কোন কারণে, তা বলা হয় না।
 * ⓘ চাবি কেবল তার কোম্পানির সারি খোলে, আর কেবল নিজের নেওয়া SMS-এর খবর দিতে পারে ([[SmsOutboxService]])।
 */
final class SmsGatewayController extends Controller
{
    public function __construct(private readonly SmsOutboxService $outbox) {}

    /** ⭐ সারি থেকে — দিনের সীমার মধ্যে, পুরনোটা আগে; সাথে ফোনের "দেখা গেছে" সময় */
    public function take(Request $request): JsonResponse
    {
        $phone = $this->phone($request);

        if ($phone === null) {
            return response()->json(['message' => 'unauthorised'], 401);
        }

        $version = mb_substr((string) $request->input('app_version', ''), 0, 20);

        return response()->json(['messages' => $this->outbox->take($phone, $version === '' ? null : $version)])->header('Cache-Control', 'no-store');
    }

    /** ⭐ খবর — একসাথে কয়েকটা: [{id, event: sent|delivered|failed, reason?}] */
    public function report(Request $request): JsonResponse
    {
        $phone = $this->phone($request);

        if ($phone === null) {
            return response()->json(['message' => 'unauthorised'], 401);
        }

        $data = $request->validate([
            'events' => ['required', 'array', 'max:100'],
            'events.*.id' => ['required', 'string', 'max:64'],
            'events.*.event' => ['required', 'string', 'in:sent,delivered,failed'],
            'events.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        $accepted = 0;

        foreach ($data['events'] as $event) {
            $accepted += $this->outbox->report($phone, (string) $event['id'], (string) $event['event'], $event['reason'] ?? null) ? 1 : 0;
        }

        return response()->json(['accepted' => $accepted]);
    }

    private function phone(Request $request): ?SmsGatewayPhone
    {
        return SmsGatewayPhone::byToken($request->bearerToken());
    }
}
