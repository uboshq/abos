<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Controllers;

use App\Core\Services\SettingsService;
use App\Http\Controllers\Controller;
use App\Modules\Mail\Models\MailFestival;
use App\Modules\Mail\Models\MailProfile;
use App\Modules\Mail\Services\MailDefaults;
use App\Modules\Mail\Services\MailSignatures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * ⭐ মেইলের সই (নমুনা v4 পর্দা ৪) — 20টা নকশা + উৎসবের সই; নতুন মেইল আর উত্তরে আলাদা; নিজের ছবি; লিংক।
 * ⓘ প্রশাসক (`mail.manage`) চাইলে একটা নকশা সবার জন্য বাধ্যতামূলক করেন (`mail.signature_forced`) — তখন প্রত্যেকের নিজের ছবি আর নাম বসে, নকশা একই।
 * ⛔ ছবি ব্যক্তিগত ডিস্কে (local) — খোলা ঠিকানায় নয়; মেইলে বসে ভিতরে (cid)।
 */
final class MailSignatureController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MailSignatures $signatures, private readonly SettingsService $settings) {}

    public static function middleware(): array
    {
        return [new Middleware('can:mail.use')];
    }

    public function edit(Request $request, MailDefaults $defaults): View
    {
        $defaults->ensure();
        $user = $request->user();
        $profile = $this->signatures->profile($user);
        $forced = (int) $this->settings->get('mail.signature_forced', 0);
        $previews = [];

        foreach (range(1, MailSignatures::COUNT) as $n) {
            $previews[$n] = $this->signatures->inline($user, $this->signatures->html($user, $n, null, false), true);
        }

        $festivals = MailFestival::query()->where('active', true)->orderBy('on_date')->get()
            ->map(fn (MailFestival $f) => ['f' => $f, 'html' => $this->signatures->inline($user,
                '<table cellpadding="0" cellspacing="0" role="presentation"><tr><td style="padding-right:14px">'.$this->signatures->html($user, 9, null, false)
                .'</td><td>'.$this->signatures->festivalBanner($f, $this->signatures->facts($user)['company']).'</td></tr></table>', true)]);

        return view('mail::signature.edit', [
            'profile' => $profile,
            'facts' => $this->signatures->facts($user),
            'previews' => $previews,
            'festivals' => $festivals,
            'forced' => $forced,
            'canForce' => $user->can('mail.manage'),
            'hasPhoto' => $this->signatures->photoBytes($user) !== null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'style_new' => 'required|integer|min:1|max:'.MailSignatures::COUNT,
            'style_reply' => 'required|integer|min:1|max:'.MailSignatures::COUNT,
            'festival' => 'nullable|boolean',
            'title' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:40',
            'links' => 'nullable|array', 'links.*' => 'nullable|string|max:191',
            'photo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'remove_photo' => 'nullable|boolean',
            'force' => 'nullable|integer|min:0|max:'.MailSignatures::COUNT,
        ]);

        $forced = (int) $this->settings->get('mail.signature_forced', 0);
        $profile = $this->signatures->profile($user);
        $photo = $profile->photo_path;

        if ($request->boolean('remove_photo') && $photo) {
            Storage::disk('local')->delete($photo);
            $photo = null;
        }

        if ($request->hasFile('photo')) {
            if ($photo) {
                Storage::disk('local')->delete($photo);
            }
            $photo = $request->file('photo')->store('mail/photos', 'local');
        }

        $profile->fill([
            'style_new' => $forced > 0 ? $forced : (int) $data['style_new'],
            'style_reply' => (int) $data['style_reply'],
            'festival' => $request->boolean('festival'),
            'title' => $data['title'] ?? null,
            'phone' => $data['phone'] ?? null,
            'links' => array_intersect_key(array_filter((array) ($data['links'] ?? []), fn ($v) => is_string($v) && trim($v) !== ''), array_flip(MailProfile::LINKS)),
            'photo_path' => $photo,
        ])->save();

        if ($user->can('mail.manage') && $request->has('force')) {
            $this->settings->set('mail.signature_forced', (int) $data['force']);
        }

        return back()->with('mail_flash', __('mail::message.saved'));
    }

    /** ⓘ পর্দায় একটা নকশার বড় ছবি */
    public function preview(Request $request, int $style): JsonResponse
    {
        abort_unless($style >= 1 && $style <= MailSignatures::COUNT, 404);

        return response()->json(['html' => $this->signatures->preview($request->user(), $style)]);
    }
}
