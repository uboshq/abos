<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Services\CoverService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * ⭐ ফোনের "আমার মলাট" — বসানো আর সরানো (প্রোফাইল পাতা, ২০ নভেম্বর ২০২৬)।
 *
 * ⓘ [[MePhotoController]]-এর যমজ: কাজটা [[CoverService]]-এর, ওয়েবের প্রোফাইল পাতা যেটা ডাকে — একই কলাম, একই ১৫০০×৫০০।
 * ⛔ কেবল JPG আর PNG, আর সেটা ফাইলের ভেতর দেখে — দুই স্তর (finfo `mimetypes`, তারপর `getimagesize()`)।
 */
final class MeCoverController extends Controller
{
    public function __construct(private readonly CoverService $covers) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403, 'staff-token-only');

        $request->validate(self::rules(), [
            'cover.max' => __('core.cover.too_large'),
            'cover.mimetypes' => __('core.cover.jpg_png_only'),
        ]);

        $file = $request->file('cover');

        if (! MePhotoController::isJpegOrPng((string) $file->getRealPath())) {
            throw ValidationException::withMessages(['cover' => __('core.cover.jpg_png_only')]);
        }

        try {
            $this->covers->store($user, $file);
        } catch (RuntimeException $refused) {
            throw ValidationException::withMessages(['cover' => __('core.'.$refused->getMessage())]);
        }

        $user->refresh();

        return response()->json([
            'cover_url' => $user->coverApiUrl(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ]);
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'cover' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png',
                'max:'.(int) (CoverService::MAX_BYTES / 1024),
            ],
        ];
    }

    public function destroy(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403, 'staff-token-only');

        $this->covers->remove($user);

        return response()->noContent();
    }
}
