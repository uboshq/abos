<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Core\Services\AvatarService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * ⭐ ফোনের "আমার ছবি" — বসানো আর সরানো (প্রোফাইল মেনু, অ্যাপ ০.৪.৪১; a6, ২০ অক্টোবর ২০২৬)।
 *
 * ⓘ কাজটা পুরোটাই [[AvatarService]]-এর, ওয়েবের প্রোফাইল পাতা যেটা ডাকে — একই কলাম (`users.avatar_path`), একই ৫১২
 * বিন্দুর বর্গ ([[ImageEngine::FACE_EDGE]]), একই নিরীক্ষার সারি। ⭐ তাই ওয়েব আর ফোনে একই মুখ।
 *
 * ── ⛔ কেবল JPG আর PNG, আর সেটা ফাইলের ভেতর দেখে ───────────────────────
 * মালিক বলেছেন ফোনে JPG/PNG — WebP ওয়েবে চলে, এখানে নয়। ⚠️ নাম বা ফোনের বলা ধরন বিশ্বাস করা হয় না: `mimetypes`
 * নিয়ম ফাইলের বাইট পড়ে (finfo), আর তারপর `getimagesize()` আবার দেখে। ".jpg" নামের লেখা-ফাইল দুটোতেই আটকায় — ৪২২।
 */
final class MePhotoController extends Controller
{
    /** @var list<string> */
    private const PHONE_ACCEPTS = ['image/jpeg', 'image/png'];

    public function __construct(private readonly AvatarService $avatars) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403, 'staff-token-only');

        $request->validate(self::rules());

        $file = $request->file('photo');

        if (! self::isJpegOrPng((string) $file->getRealPath())) {
            throw ValidationException::withMessages(['photo' => __('core.avatar.phone_jpg_png_only')]);
        }

        try {
            $this->avatars->store($user, $file);
        } catch (RuntimeException $refused) {
            throw ValidationException::withMessages(['photo' => __('core.'.$refused->getMessage())]);
        }

        $user->refresh();

        return response()->json([
            'photo_url' => $user->avatarApiUrl(),
            'updated_at' => $user->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * ⛔ প্রথম স্তর — Laravel-এর নিয়ম, `mimetypes` ফাইলের বাইট finfo দিয়ে পড়ে। ⓘ নিজের নামে, দ্বিতীয় স্তরের একই কারণে:
     * দাবি যেন এই স্তরকে একা ছোঁয়, নাহলে পেছনের স্তর এর ভাঙন ঢেকে দিত।
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'photo' => [
                'required',
                'file',
                'mimetypes:'.implode(',', self::PHONE_ACCEPTS),
                'max:'.(int) (AvatarService::MAX_BYTES / 1024),
            ],
        ];
    }

    /**
     * ⛔ দ্বিতীয় স্তর — ফাইলের ভেতরটা GD-র চোখে JPG বা PNG কি না (a6, ২০ অক্টোবর ২০২৬)।
     *
     * ⓘ প্রথম স্তর `mimetypes` (finfo); দুইটা আলাদা পাঠক, তাই একটার ফাঁক অন্যটায় আটকায়। ⚠️ নিজের নামে আলাদা, যাতে
     * দাবিটা এই স্তরকেই সরাসরি ছোঁয় — দরজা দিয়ে গেলে প্রথম স্তর আগেই ফেরায়, আর এই স্তর ভাঙলেও কেউ টের পেত না।
     */
    public static function isJpegOrPng(string $path): bool
    {
        $sniffed = $path === '' ? false : @getimagesize($path);

        return $sniffed !== false && in_array($sniffed['mime'] ?? '', self::PHONE_ACCEPTS, true);
    }

    public function destroy(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403, 'staff-token-only');

        $this->avatars->remove($user);

        return response()->noContent();
    }
}
