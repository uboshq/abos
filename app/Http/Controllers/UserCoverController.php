<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\CoverService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ⭐ একজনের মলাট ছবি — কেবল সহকর্মীর চোখে (প্রোফাইল পাতা, ২০ নভেম্বর ২০২৬)।
 *
 * ⓘ [[UserPhotoController]]-এর যমজ: ওয়েব (`users.cover`, সেশন) আর ফোন (`api.users.cover`, টোকেন) দুইটাই এখানে, পাহারা একটাই —
 * [[UserPolicy::seeFace()]] → [[AvatarService::visibleTo()]]। ⛔ "নেই" আর "দেখার অধিকার নেই" দুটোই ৪০৪।
 */
final class UserCoverController extends Controller
{
    public function __construct(private readonly CoverService $covers) {}

    public function show(Request $request, string $user): Response
    {
        $viewer = $request->user();

        // ⓘ কেবল কর্মী — পোর্টালের ডিলার বা সরবরাহকারী অন্য মডেল
        abort_unless($viewer instanceof User, 404);

        $owner = User::findByPublicId($user);

        abort_if($owner === null, 404);

        // ⛔ মুখের একই পাহারা — সহকর্মী না হলে ৪০৪
        $this->authorize('seeFace', $owner);

        $bytes = $this->covers->bytesOf($owner);

        abort_if($bytes === null, 404);

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        abort_unless(in_array($mime, CoverService::ACCEPTED, true), 404);

        $etag = '"'.sha1((string) $owner->getRawOriginal('cover_path')).'"';

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
