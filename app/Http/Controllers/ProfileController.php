<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Panels\FactRegistry;
use App\Core\Services\AvatarService;
use App\Core\Services\MenuBuilder;
use App\Models\User;
use App\Notifications\EmailChangeLink;
use App\Notifications\EmailChangeWarning;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * নিজের প্রোফাইল — কে আপনি, কোথায় আপনাকে পাওয়া যাবে, আর চাবিটা।
 *
 * চেহারা (রং, থিম, ভাষা) থেকে আলাদা: ওটা কে কেমন দেখতে চায়, এটা কে সে।
 *
 * ── ⭐ ১৪ সেপ্টেম্বর ২০২৬: পাতাটা প্রায় খালি ছিল ─────────────────────
 * মালিক পাতাটা খুলে বললেন: *"Nam ache email ache Designation nai user id
 * nai, mobile no nai secondary mobile nai, address nai, Passworad
 * poriborton nai"*। ⓘ সাতটার মধ্যে তিনটার ঘরই ডাটাবেজে ছিল না
 * ([[2026_11_11_100000_the_profile_page_could_not_say_how_to_reach_anyone]]),
 * আর পাসওয়ার্ড বদলের কোনো পথই ছিল না।
 */
class ProfileController extends Controller
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly AvatarService $avatars,
        private readonly FactRegistry $facts,
    ) {}

    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('workspace.profile', [
            'menu' => $this->menu->forUser($user),
            'user' => $user,
            'maxMb' => (int) round(AvatarService::MAX_BYTES / 1024 / 1024),

            /*
             * ⭐ পদবি — দেখানো হয়, বদলানো যায় না, আর সেটাই ঠিক।
             *
             * ⓘ পদবি থাকে `hr_employees`-এ, অর্থাৎ HR মডিউলে। ⚠️ এই
             * কন্ট্রোলারটা কোরের, আর কোর `App\Modules\` চেনে না — এই
             * রিপোর মাপা নিয়ম। তাই প্রশ্নটা ঘুরিয়ে দেওয়া হয়:
             * [[FactRegistry]]-কে জিজ্ঞেস করা হয় "এই ব্যবহারকারী
             * সম্পর্কে কার কী বলার আছে", আর HR উত্তর দেয়
             * ([[App\Modules\Hr\Panels\EmployeeFacts]])।
             *
             * ⛔ সম্পাদনার ঘর করা হয়নি: পদবি কে ঠিক করবেন সেটা HR-এর
             * সিদ্ধান্ত, নিজের নয়। কেউ নিজের পদবি "ব্যবস্থাপনা পরিচালক"
             * লিখে দিলে ওটা পর্দায় সত্যির মতোই দেখাত।
             *
             * ⚠️ একই কোড ফুটারেও আছে ([[components/shell/statusbar]]) —
             * ⓘ ওটা এক লাইন, আর দুইটার একটাও অন্যটার উপর দাঁড়িয়ে নেই।
             */
            'designation' => $user
                ? ($this->facts->forRecord('user', $user->id)[0]->value ?? null)
                : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        /*
         * ⓘ তিনটা নতুন ঘরই ঐচ্ছিক — জোর করে নম্বর চাইলে মানুষ `000`
         * বসিয়ে দেন, আর তখন ঘরটা ভরা থাকে কিন্তু কাজে লাগে না।
         *
         * ⚠️ `max` মাইগ্রেশনের দৈর্ঘ্যের সাথে **মিলিয়ে** রাখা (২৫/২৫)।
         * ⛔ না মিললে ডাটাবেজ নিজে ছুঁড়ত, আর সেই বার্তাটা ব্যবহারকারীর
         * পড়ার মতো নয় — ভ্যালিডেশন আগে ধরলে বার্তাটা তাঁর ভাষায় আসে।
         */
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],

            /*
             * ⭐ লগইন আইডি — মালিকের সিদ্ধান্ত, ১৪ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ নিয়মগুলো আলগা নয়, প্রতিটার কারণ আছে:
             *
             *   `unique`   → এটা এখন একটা **পরিচয়**। দুইজনের এক আইডি
             *                মানে লগইনে "কোনটা ফিরবে জানি না" — ঠিক যে
             *                ভুলটা আজ `name` থেকে সরানো হলো।
             *   `regex`    → ছোট হরফ, আর `@` নেই। ⛔ `@` থাকলে একজন
             *                আরেকজনের ইমেইল নিজের আইডি বানিয়ে নিতে
             *                পারতেন, আর ফাঁকটা নতুন ঘরে ফিরে আসত।
             *   শুরুতে অক্ষর → `2024` জাতীয় আইডি পরে সংখ্যার সাথে গুলিয়ে
             *                যেত, আর মুখে বলাও কঠিন।
             *   `min:3`    → এক-অক্ষরের আইডি টাইপের ভুলে অন্যেরটা হয়ে যায়।
             */
            'login_id' => [
                'required', 'string', 'min:3', 'max:40',
                'regex:/^[a-z][a-z0-9._-]*$/',
                Rule::unique('users', 'login_id')->ignore($request->user()->id),
            ],

            'mobile' => ['nullable', 'string', 'max:25'],
            'mobile_alt' => ['nullable', 'string', 'max:25'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);

        $request->user()->forceFill($validated)->save();

        return back()->with('saved', true);
    }

    /**
     * ইমেইল বদলানোর **অনুরোধ** — এখানে কিছুই বদলায় না।
     *
     * ── ⭐ কেন দুই ধাপ ────────────────────────────────────────────────
     * মালিকের বাছাই, ১৪ সেপ্টেম্বর ২০২৬: যাচাই করে বদলানো। ⓘ ইমেইলটা
     * লগইনের পরিচয় **আর** পাসওয়ার্ড ফিরে পাওয়ার একমাত্র পথ। ⛔ সাথে
     * সাথে বদলে দিলে একটা অক্ষর ভুল লিখলেই মানুষ নিজের অ্যাকাউন্ট থেকে
     * চিরতরে বেরিয়ে যেতেন — ঢুকতে পারতেন না, আর রিসেট লিংকও ভুল
     * ঠিকানায় যেত।
     *
     * ⭐ তাই নতুন ঠিকানাটা কেবল **অপেক্ষায়** বসে, আর পুরনোটা শেষ
     * মুহূর্ত পর্যন্ত কাজ করতে থাকে।
     */
    public function requestEmailChange(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            /*
             * ⚠️ পাসওয়ার্ড চাওয়া হয়, আর সেটা এই পর্দার সবচেয়ে জরুরি
             * শর্ত। ⓘ ডিপোতে একজন লগআউট না করে উঠে গেলে খোলা সেশনটা
             * যে কেউ পেতে পারেন, আর ইমেইল বদলে নেওয়া মানে অ্যাকাউন্টটা
             * নিয়ে নেওয়া — পরের রিসেট লিংক তাঁর ইনবক্সে যেত।
             */
            'current_password' => ['required', 'string'],

            'email' => [
                'required', 'email', 'max:191',
                Rule::unique('users', 'email')->ignore($user->id),

                /*
                 * ⛔ অন্য কারো **অপেক্ষমাণ** ঠিকানাও নেওয়া যায় না।
                 * ⚠️ নাহলে দুইজন একই ঠিকানা অপেক্ষায় রেখে দুইজনই লিংক
                 * পেতেন, আর দ্বিতীয়জনের নিশ্চিতকরণ unique সূচকে ধাক্কা
                 * খেয়ে একটা কাঁচা ডাটাবেজ ত্রুটি হয়ে পর্দায় আসত।
                 */
                Rule::unique('users', 'pending_email')->ignore($user->id),
            ],
        ]);

        if (! Hash::check($validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('core.profile.password_wrong'),
            ]);
        }

        if (strcasecmp($validated['email'], (string) $user->email) === 0) {
            throw ValidationException::withMessages([
                'email' => __('core.profile.email_same'),
            ]);
        }

        /*
         * ⚠️ টোকেনটা একবারই দেখা যায় — ডাটাবেজে যায় তার **হ্যাশ**।
         * ⛔ সাদা রাখলে ব্যাকআপ ফাইল পড়তে পারে এমন যে কেউ লিংকটা নিজে
         * বানিয়ে ফেলতে পারতেন। ⓘ পাসওয়ার্ড রিসেটের টোকেনও একইভাবে রাখা।
         */
        $token = Str::random(64);

        $user->forceFill([
            'pending_email' => $validated['email'],
            'pending_email_token' => hash('sha256', $token),
            'pending_email_at' => now(),
        ])->save();

        /* অনুমতি চাওয়া — নতুন ঠিকানায়। */
        Notification::route('mail', $validated['email'])
            ->notify(new EmailChangeLink($token));

        /* খবর দেওয়া — পুরনো ঠিকানায়। ⓘ এখনো `email` বদলায়নি, তাই
           `$user`-কে সরাসরি পাঠালেই সেটা পুরনো ঠিকানাতেই যায়। */
        $user->notify(new EmailChangeWarning($validated['email']));

        return back()->with('email_sent', $validated['email']);
    }

    /**
     * চিঠির লিংকে চাপ — এখানেই ঠিকানাটা সত্যিই বদলায়।
     *
     * ── ⓘ কেন এই পথটা লগইন ছাড়াই খোলা ───────────────────────────────
     * চিঠিটা যায় নতুন ঠিকানায়, আর সেটা হয়তো তিনি ফোনে খুলবেন যেখানে
     * ABOS-এ লগইন করা নেই। ⚠️ লগইন বাধ্যতামূলক করলে তাঁকে আগে **পুরনো**
     * ঠিকানা দিয়ে ঢুকতে হত, আর যিনি ইমেইল বদলাচ্ছেন কারণ পুরনোটা আর
     * খোলেন না, তাঁর পক্ষে ওটা অসম্ভব।
     *
     * ⭐ প্রমাণটা টোকেনেই আছে — ওটা কেবল ঐ ইনবক্সেই গেছে।
     */
    public function confirmEmailChange(string $token): RedirectResponse
    {
        $minutes = (int) config('abos.email_change_expire', 60);

        $user = User::query()
            ->where('pending_email_token', hash('sha256', $token))
            ->whereNotNull('pending_email')
            ->where('pending_email_at', '>=', now()->subMinutes($minutes))
            ->first();

        /*
         * ⓘ ব্যর্থতার কারণ বলা হয় না — মেয়াদ শেষ, ভুল টোকেন, নাকি
         * অনুরোধটাই বাতিল, তিনটার উত্তর এক। ⚠️ আলাদা বার্তা দিলে কেউ
         * টোকেন আন্দাজ করতে করতে বুঝে ফেলতেন কোনটা কাছাকাছি।
         */
        if ($user === null) {
            return redirect()->route(auth()->check() ? 'profile' : 'login')
                ->withErrors(['email' => __('core.profile.email_link_dead')]);
        }

        /*
         * ⛔ শেষ মুহূর্তে আরেকবার দেখা — লিংকটা এক ঘণ্টা পুরনো হতে পারে,
         * আর ততক্ষণে ঐ ঠিকানাটা অন্য কেউ নিয়ে নিয়ে থাকতে পারেন।
         * ⚠️ না দেখলে unique সূচকে ধাক্কা লেগে একটা কাঁচা SQL ত্রুটি
         * ব্যবহারকারীর পর্দায় আসত।
         */
        $taken = User::query()
            ->where('email', $user->pending_email)
            ->whereKeyNot($user->getKey())
            ->exists();

        if ($taken) {
            $user->forceFill([
                'pending_email' => null,
                'pending_email_token' => null,
                'pending_email_at' => null,
            ])->save();

            return redirect()->route(auth()->check() ? 'profile' : 'login')
                ->withErrors(['email' => __('core.profile.email_taken_now')]);
        }

        $user->forceFill([
            'email' => $user->pending_email,

            /* ⓘ নতুন ঠিকানাটা এইমাত্র নিজেকে প্রমাণ করল — তাই
               যাচাইয়ের সময়টা এখনই বসে, খালি হয়ে থাকে না। */
            'email_verified_at' => now(),

            'pending_email' => null,
            'pending_email_token' => null,
            'pending_email_at' => null,
        ])->save();

        /*
         * ⚠️ লগইন করা না থাকলে প্রোফাইলে পাঠানো যায় না — ওটা `auth`-এর
         * ভেতরে, আর মাঝপথে লগইনে ছুঁড়ে দিলে সফলতার খবরটা হারিয়ে যেত।
         * ⓘ তখন খবরটা লগইনের পাতাতেই বসে, কারণ পরের কাজটা ওটাই: নতুন
         * ঠিকানা দিয়ে ঢোকা।
         */
        return auth()->check()
            ? redirect()->route('profile')->with('email_changed', $user->email)
            : redirect()->route('login')->with('status', __('core.profile.email_changed', ['email' => $user->email]));
    }

    /**
     * পাসওয়ার্ড বদল।
     *
     * ── ⚠️ পুরনোটা কেন চাওয়া হয় ─────────────────────────────────────
     * ⓘ ডিপোতে একটা কম্পিউটার কয়েকজন ভাগ করে ব্যবহার করেন, আর কেউ
     * লগআউট না করে উঠে যান। ⛔ পুরনো পাসওয়ার্ড না চাইলে পাশের চেয়ারের
     * যে কেউ দুই ক্লিকে অ্যাকাউন্টটা নিজের করে নিতে পারতেন, আর আসল
     * মালিক জানতেন কেবল যেদিন ঢুকতে পারতেন না।
     *
     * ── ⓘ নিয়মটা প্রশাসকের পর্দার সাথে এক ───────────────────────────
     * অক্ষর **আর** সংখ্যা, অন্তত ৮ — [[App\Modules\SystemAdmin\Http\Controllers\UserController]]
     * যা চায় হুবহু তাই। ⚠️ দুই জায়গায় দুই নিয়ম থাকলে একজন এমন
     * পাসওয়ার্ড বসাতেন যেটা অন্য পর্দা মানত না।
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => [
                'required',
                'string',
                'max:191',
                'confirmed',
                Password::min(8)->letters()->numbers(),
            ],
        ]);

        $user = $request->user();

        /*
         * ⚠️ ভুল বার্তাটা `current_password` ঘরের উপরেই বসে, সাধারণ
         * তালিকায় নয় — ⓘ তিনটা ঘরের ফর্মে "ভুল পাসওয়ার্ড" উপরে ভাসলে
         * কোনটা ভুল তা বোঝা যায় না।
         */
        if (! Hash::check($validated['current_password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('core.profile.password_wrong'),
            ]);
        }

        /*
         * ⛔ পুরনোটাই আবার বসানো ঠেকানো — ⓘ নাহলে "পাসওয়ার্ড বদলান"
         * বলার পর কেউ একই চাবি বসিয়ে দিতেন আর ব্যবস্থাটা বলত "সংরক্ষিত",
         * অর্থাৎ কিছুই না করে সফল হত।
         */
        if (Hash::check($validated['password'], (string) $user->password)) {
            throw ValidationException::withMessages([
                'password' => __('core.profile.password_same'),
            ]);
        }

        $user->forceFill(['password' => $validated['password']])->save();

        return back()->with('password_saved', true);
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
