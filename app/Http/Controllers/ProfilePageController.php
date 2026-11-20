<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\ProfileFacts;
use App\Http\Controllers\Api\MeDevicesController;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ⭐ ওয়েবের "আমার প্রোফাইল" — Facebook-ধাঁচের মলাট আর বড় গোল ছবি (মালিক, ২০ নভেম্বর ২০২৬: প্রোফাইলের নমুনার গ-তে "ok";
 *   নকশা হুবহু, কিছু যোগ নয়)।
 *
 * ⓘ তথ্য অ্যাপের একই উৎস থেকে ([[ProfileFacts]]); ছবি আর মলাট বদলানো পুরনো দরজায় ([[ProfileController]] ছবি,
 *   [[ProfileCoverController]] মলাট); "প্রোফাইল ঠিক করুন" পুরনো `/profile`-এ।
 * ⛔ অন্যের প্রোফাইল: সহকর্মী না হলে ৪০৪; ফোন, কাজের সংখ্যা, লগইন করা ফোন, নিরাপত্তা লুকানো ([[ProfileFacts::HIDDEN]])।
 */
final class ProfilePageController extends Controller
{
    public function __construct(
        private readonly ProfileFacts $facts,
        private readonly MenuBuilder $menu,
    ) {}

    public function me(Request $request): View
    {
        $user = $this->user($request);

        return $this->page($request, $user, $user);
    }

    public function show(Request $request, string $user): View|RedirectResponse
    {
        $viewer = $this->user($request);
        $owner = User::findByPublicId($user);

        abort_if($owner === null || ! $this->facts->opens($viewer, $owner), 404);

        // ⓘ নিজের লিংক খুললে নিজের পাতা — "অন্যরা যেমন দেখে" বোতাম `?as=other` দিয়ে আসে
        if ((int) $owner->getKey() === (int) $viewer->getKey() && $request->query('as') !== 'other') {
            return redirect()->route('me.profile');
        }

        return $this->page($request, $viewer, $owner, $request->query('as') === 'other');
    }

    /** ⛔ নিজের একটা ফোন থেকে বের করা — অ্যাপের `DELETE /me/devices/{id}`-এর একই পথ */
    public function forgetDevice(Request $request, string $device, MeDevicesController $devices): RedirectResponse
    {
        abort_unless($devices->signOut($this->user($request), $device), 404);

        return redirect()->route('me.profile')->with('saved', __('profile.device_removed'));
    }

    private function page(Request $request, User $viewer, User $owner, bool $asOther = false): View
    {
        // ⓘ "অন্যরা যেমন দেখে" — নিজের পাতা, কিন্তু সহকর্মীর চোখে (মালিক নিজে দেখলেও সাধারণ সহকর্মীর মতো)
        $profile = $asOther
            ? array_merge($this->facts->for($viewer, $owner), ['self' => false, 'can_edit' => false, 'about' => null, 'month' => null, 'hidden' => ProfileFacts::HIDDEN])
            : $this->facts->for($viewer, $owner);

        $self = (bool) $profile['self'];

        return view('workspace.me', [
            'menu' => $this->menu->forUser($viewer),
            'profile' => $profile,
            'asOther' => $asOther,
            'devices' => $self ? app(MeDevicesController::class)->listFor($owner) : [],
            'security' => $self ? $this->security($owner) : null,
        ]);
    }

    /** @return array{password_url: ?string, two_step: bool, two_step_url: ?string, sessions_url: ?string} */
    private function security(User $user): array
    {
        $route = fn (string $name): ?string => \Illuminate\Support\Facades\Route::has($name) ? route($name) : null;

        return [
            // ⓘ পাসওয়ার্ড বদল পুরনো প্রোফাইলের পাতায় — সেখানে নিয়ে যায়
            'password_url' => $route('profile') !== null ? $route('profile').'#password' : null,
            'two_step' => $user->getAttribute('mfa_confirmed_at') !== null,
            'two_step_url' => $route('mfa'),
            'sessions_url' => $route('governance.session.index') ?? $route('session.index'),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
