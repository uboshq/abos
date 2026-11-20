<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\CoverService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * ⭐ ওয়েবের "আমার মলাট" — বসানো আর সরানো (প্রোফাইল পাতা, ২০ নভেম্বর ২০২৬)।
 *
 * ⓘ [[ProfileController::updateAvatar()]]-এর ছাঁচ: দুই স্তরে যাচাই — এখানে দ্রুত, পঠনযোগ্য বার্তা; সেবার ভেতরে
 * ইঞ্জিন ছবিটা সত্যিই খুলে দেখে। ওয়েবে WebP-ও চলে (ছবির মতোই), ফোনে কেবল JPG/PNG।
 */
final class ProfileCoverController extends Controller
{
    public function __construct(private readonly CoverService $covers) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $request->validate([
            'cover' => [
                'required',
                'file',
                'mimetypes:'.implode(',', CoverService::ACCEPTED),
                'max:'.(int) (CoverService::MAX_BYTES / 1024),
            ],
        ], [
            'cover.max' => __('core.cover.too_large'),
            'cover.mimetypes' => __('core.cover.not_an_image'),
        ]);

        try {
            $this->covers->store($user, $request->file('cover'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['cover' => __('core.'.$e->getMessage())]);
        }

        return back()->with('saved', true)->with('status', __('core.cover.saved'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $this->covers->remove($user);

        return back()->with('saved', true)->with('status', __('core.cover.removed'));
    }
}
