<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\AvatarService;
use App\Core\Services\MenuBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * নিজের প্রোফাইল — নাম ও ছবি।
 *
 * চেহারা (রং, থিম, ভাষা) থেকে আলাদা: ওটা কে কেমন দেখতে চায়, এটা কে সে।
 * ছবি আপলোড, বদল ও মোছা — তিনটাই এই একটা পাতায়, চেহারায় বা টপবারের
 * মেনুতে ছড়িয়ে নয়।
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly AvatarService $avatars,
    ) {}

    public function edit(Request $request): View
    {
        return view('workspace.profile', [
            'menu' => $this->menu->forUser($request->user()),
            'user' => $request->user(),
            'maxMb' => (int) round(AvatarService::MAX_BYTES / 1024 / 1024),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $request->user()->forceFill($validated)->save();

        return back()->with('saved', true);
    }

    /**
     * ছবি আপলোড।
     *
     * ভ্যালিডেশন দুই স্তরে: এখানে দ্রুত ও পঠনযোগ্য বার্তার জন্য, আর
     * সার্ভিসের ভেতরে ফাইলটা সত্যিই ছবি কি না তা খুলে দেখে। উপরেরটা
     * ফাইলের নাম ও ঘোষিত ধরন দেখে — সেটা পাঠানো যায় বলেই দ্বিতীয়টা
     * থাকতে হয়।
     */
    public function updateAvatar(Request $request): RedirectResponse
    {
        $request->validate([
            'avatar' => [
                'required',
                'file',
                'mimetypes:'.implode(',', AvatarService::ACCEPTED),
                'max:'.(int) (AvatarService::MAX_BYTES / 1024),
            ],
        ]);

        try {
            $this->avatars->store($request->user(), $request->file('avatar'));
        } catch (RuntimeException $e) {
            return back()->withErrors(['avatar' => __('core.'.$e->getMessage())]);
        }

        return back()->with('saved', true);
    }

    public function removeAvatar(Request $request): RedirectResponse
    {
        $this->avatars->remove($request->user());

        return back()->with('saved', true);
    }
}
