<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\LiveStamp;
use Illuminate\Http\JsonResponse;

/**
 * ⭐ "নতুন কিছু?" — খোলা পাতার কুড়ি সেকেন্ডের জিজ্ঞাসা (রিয়েল-টাইম সিঙ্ক, মালিক, ১০ অক্টোবর ২০২৬; [[LiveStamp]])।
 *
 * ⛔ `web` বাদ — সেশন নয়, কুকি নয় ([[LiveStamp]]-এর কারণ)। উত্তরে কেবল সময়-চিহ্ন।
 */
final class LiveController extends Controller
{
    /** IP-প্রতি মিনিটে — একই অফিসের অনেক পাতা এক IP থেকে আসে, তাই উদার। */
    public const PER_MINUTE = 240;

    public function __invoke(string $key, LiveStamp $stamps): JsonResponse
    {
        $company = $stamps->companyOf($key);

        abort_if($company === null, 404);

        return response()->json(['s' => $stamps->read($company)])
            ->header('Cache-Control', 'no-store');
    }
}
