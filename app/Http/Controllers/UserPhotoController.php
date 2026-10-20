<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\AvatarService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ⭐ একজনের মুখ — কেবল সহকর্মীর চোখে (মালিক, a6, ২০ অক্টোবর ২০২৬)।
 *
 * ── কেন এই দরজা ──────────────────────────────────────────────────────
 * মালিকের নিয়ম: *"একই কোম্পানির মানুষই কেবল ফাইলটা দেখতে পারবে"*। ⚠️ আগে ছবি ছিল `public` ডিস্কে, `/storage/avatars/…`
 * — লগইন ছাড়াই খোলা। ⭐ এখন ওয়েব (`users.photo`, সেশন) আর ফোন (`api.users.photo`, টোকেন) দুইটাই এখানে আসে, আর
 * পাহারা একটাই: [[AvatarService::visibleTo()]]।
 *
 * ⛔ "নেই" আর "দেখার অধিকার নেই" — দুটোই ৪০৪, একই শব্দে: ৪০৩ দিলে অন্য কোম্পানির কেউ জেনে যেতেন ঐ id-র মানুষটা আছেন
 * আর তাঁর ছবি আছে।
 *
 * ⓘ ক্যাশ `private` — ফোন আর ব্রাউজার রাখে, মাঝের কোনো প্রক্সি নয়; ETag পথের ছাপ, তাই একই ছবি দ্বিতীয়বার ৩০৪।
 */
final class UserPhotoController extends Controller
{
    public function __construct(private readonly AvatarService $avatars) {}

    public function __invoke(Request $request, string $user): Response
    {
        $viewer = $request->user();

        // ⓘ কেবল কর্মী — পোর্টালের ডিলার বা সরবরাহকারী অন্য মডেল, তাঁদের কাছে কর্মীর মুখ নয়
        abort_unless($viewer instanceof User, 404);

        $owner = User::query()->where('public_id', $user)->first();

        abort_if($owner === null || ! $this->avatars->visibleTo($viewer, $owner), 404);

        $bytes = $this->avatars->bytesOf($owner);

        abort_if($bytes === null, 404);

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        // ⛔ ছবি ছাড়া কিছু এই দরজা দিয়ে যায় না — কলামে যা-ই থাকুক
        abort_unless(in_array($mime, AvatarService::ACCEPTED, true), 404);

        $etag = '"'.sha1((string) $owner->getRawOriginal('avatar_path')).'"';

        $headers = [
            'Cache-Control' => 'private, max-age=86400',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, $headers);
        }

        return response($bytes, 200, $headers + [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline',
        ]);
    }
}
