<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Services\ErrorJournal;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ⭐ `POST /api/v1/app/crash` — ফোনের ক্র্যাশের খবর, নিজের সার্ভারে, ভুলের খাতায় ([[ErrorJournal::recordFromPhone()]])।
 *
 * <p>সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬: মাঠের ফোনে অ্যাপ বন্ধ হয়ে গেলে অফিস জানতেই পারত না। ⭐ বাইরে কিছু যায় না
 * (Crashlytics নয়), তাই মালিকের আলাদা অনুমতি লাগে না (সমন্বয়ক)।
 *
 * <p>⚠️ টোকেন ছাড়াও খোলা — লগইনের পর্দাতেও অ্যাপ ভাঙতে পারে। তাই: সীমা (রুটে `throttle`), প্রতিটা ঘরের আকারের সীমা,
 * আর কেবল চারটা ঘর — সংস্করণ, পর্দা, ভুলের লেখা, stack। টোকেন থাকলে মানুষ আর কোম্পানি জোড়া হয়; না থাকলে খালি।
 * ব্যবসার কোনো ডেটা ফেরে না — ২০২, খালি।
 */
final class AppCrashController extends Controller
{
    public function __invoke(Request $request, ErrorJournal $journal): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'string', 'max:32'],
            'screen' => ['nullable', 'string', 'max:191'],
            'error' => ['required', 'string', 'max:191'],
            'message' => ['nullable', 'string', 'max:2000'],
            'stack' => ['nullable', 'string', 'max:8000'],
        ]);

        // ⓘ টোকেন থাকলে কে — কিন্তু টোকেন না থাকা বা মেয়াদ ফুরানো ভুল নয়
        $user = auth('sanctum')->user();
        $user = $user instanceof User ? $user : null;

        $journal->recordFromPhone(
            class: $data['error'],
            message: (string) ($data['message'] ?? ''),
            screen: (string) ($data['screen'] ?? ''),
            version: $data['version'],
            stack: (string) ($data['stack'] ?? ''),
            userId: $user?->id === null ? null : (int) $user->id,
            companyId: $user?->current_company_id === null ? null : (int) $user->current_company_id,
        );

        return response()->json([], 202);
    }
}
