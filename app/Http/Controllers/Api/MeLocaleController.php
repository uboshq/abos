<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Support\UserLocale;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ⭐ ফোন থেকে নিজের ভাষা বদল — `PATCH /api/v1/me/locale` `{locale: bn|en}` (০.৪.৪৩; fe, ২১ অক্টোবর ২০২৬)।
 * ওয়েবের একই নিয়ম আর একই ঘর ([[UserLocale]]) — তাই ফোনে বদলালে ওয়েবেও, ওয়েবে বদলালে `/me`-তে ফোনেও।
 * ⓘ কেবল নিজের সারি; কোনো চাবি লাগে না (নিজের পছন্দ, ব্যবসার তথ্য নয়)।
 */
final class MeLocaleController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate(UserLocale::rules());

        UserLocale::save($request->user(), $validated['locale']);
        app()->setLocale($validated['locale']);

        return response()->json(['data' => ['locale' => $validated['locale']]]);
    }
}
