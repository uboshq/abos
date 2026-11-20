<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Services\ProfileFacts;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ⭐ প্রোফাইল — অ্যাপের "আমি" আর ওয়েবের "আমার প্রোফাইল" একই তথ্য ([[ProfileFacts]]; মালিক, ২০ নভেম্বর ২০২৬, নমুনা ক/গ-তে "ok")।
 *
 * `GET /api/v1/me/profile?limit=4` — নিজের; `GET /api/v1/users/{public_id}/profile` — সহকর্মীর (লুকানো ঘর null, `hidden` তালিকায়)।
 * ⓘ `/me`-র মতোই `can:` নেই — নিজের জিনিস; অন্যেরটা সহকর্মী না হলে ৪০৪ (ছবির একই নিয়ম)। চুক্তি a3b-র সাথে (fe/app-profile)।
 */
final class ProfileApiController extends Controller
{
    public function __construct(private readonly ProfileFacts $facts) {}

    public function me(Request $request): JsonResponse
    {
        $user = $this->staff($request);

        return response()->json($this->facts->for($user, $user, $this->limit($request), true));
    }

    public function show(Request $request, string $user): JsonResponse
    {
        $viewer = $this->staff($request);
        $owner = User::findByPublicId($user);

        abort_if($owner === null || ! $this->facts->opens($viewer, $owner), 404);

        return response()->json($this->facts->for($viewer, $owner, $this->limit($request), true));
    }

    private function limit(Request $request): int
    {
        $limit = (int) $request->query('limit', (string) ProfileFacts::RECENT_APP);

        return max(1, min(20, $limit));
    }

    private function staff(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403, 'staff-token-only');

        return $user;
    }
}
