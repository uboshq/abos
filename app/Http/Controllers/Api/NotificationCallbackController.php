<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Notifications\ProviderCallbacks;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ⭐ প্রোভাইডারের ফেরত-খবরের দরজা — লগইন নেই, পাহারা স্বাক্ষরে ([[ProviderCallbacks]]); কিছু ফেরত দেয় না, কেবল কয়টা নেওয়া হলো।
 */
class NotificationCallbackController extends Controller
{
    public function __invoke(Request $request, string $company, string $channel): JsonResponse
    {
        // ⓘ বড় অনুরোধ নয় — ফেরত-খবর ছোট
        abort_if(strlen($request->getContent()) > 256 * 1024, 413);

        $result = app(ProviderCallbacks::class)->receive(
            $company, $channel, $request->getContent(),
            $request->header('X-ABOS-Timestamp'), $request->header('X-ABOS-Signature'),
        );

        return response()->json(['data' => ['accepted' => $result['accepted']]], $result['status']);
    }
}
