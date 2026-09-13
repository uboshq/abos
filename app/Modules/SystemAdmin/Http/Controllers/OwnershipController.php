<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Services\Ownership;
use App\Core\Support\CompanyContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

/**
 * মালিকানা হস্তান্তর — ব্যবস্থার সবচেয়ে বড় চাবিটা হাতবদল।
 *
 * ── কেন এটার নিজের একটা পাতা ─────────────────────────────────────────
 * কাজটা ব্যবহারকারী-সম্পাদনার পর্দাতেও বসানো যেত — ওখানে রোলের একটা
 * চেকবক্স আছে, ওখানেই আরেকটা বসত। ⛔ কিন্তু তাতে সবচেয়ে বড় সিদ্ধান্তটা
 * দেখতে হত বাকি দশটা সিদ্ধান্তের মতোই, আর **যে জিনিস দেখতে সাধারণ,
 * সেটা সাবধানে করা হয় না**।
 *
 * ⓘ তাই আলাদা পাতা, আলাদা নাম, আর পাসওয়ার্ড চাওয়া। কাজটা যত কম
 * "আরেকটা ফর্ম" মনে হয়, ততই ভালো।
 *
 * ── কেন পাসওয়ার্ড ────────────────────────────────────────────────────
 * খোলা রেখে যাওয়া একটা স্ক্রিন, বা ধার করা একটা সেশন — দুইটাই যথেষ্ট
 * হত পুরো প্রতিষ্ঠানটা অন্য কারো হাতে তুলে দিতে। ⓘ ধরনটা `MfaController::
 * destroy()` থেকে নেওয়া, ইচ্ছাকৃতভাবে: একই ব্যবস্থায় দুই রকম পাসওয়ার্ড-
 * প্রম্পট থাকলে মানুষ কোনটা আসল তা বুঝতে পারেন না।
 *
 * ── ⚠️ "মালিক" শব্দটা এখানে আর অর্থ মডিউলে এক নয় ─────────────────────
 * এখানে মালিক = **সিস্টেমের চাবি কার হাতে**। অর্থ মডিউলের
 * `finance::who.owner` = **কার টাকা ব্যবসায় খাটছে** (মূলধনের খাতা)।
 * ⓘ যিনি কেবল টাকা দেন তাঁর কোনো অ্যাকাউন্টই লাগে না — তাঁর নাম যায়
 * অর্থ → মূলধনে, ব্যবহারকারীর তালিকায় নয়।
 */
class OwnershipController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly MenuBuilder $menu,
        private readonly Ownership $ownership,
    ) {}

    /**
     * ⛔ এই লাইনটা প্রথম খসড়ায় ছিল না — ১৩ সেপ্টেম্বর ২০২৬।
     *
     * ── কী ছিল, আর কী ছিল না ─────────────────────────────────────────
     * ⓘ পর্দাটা অরক্ষিত **ছিল না**: নিচের `mustBeTheOwner()` দুইটা
     * পদ্ধতিতেই ডাকা হয়, আর অ-মালিক ৪০৩ পান। অর্থাৎ ঠিকানা টাইপ করে
     * কেউ হস্তান্তর ঘটাতে পারতেন না।
     *
     * ⛔ কিন্তু রুট দুইটা কোনো **অনুমতি ঘোষণা করত না**, আর এই রিপোতে
     * সেটা নিজেই একটা নিয়ম — `EveryRouteIsGuardedTest` প্রতিটা পর্দার
     * কাছে ঘোষিত অনুমতি চায়। ⚠️ কারণটা ব্যবহারিক: পাহারা যদি কেবল
     * কন্ট্রোলারের ভেতরের একটা কলে থাকে, তবে কেউ একদিন রিফ্যাক্টর করতে
     * গিয়ে ঐ কলটা সরালে **কিছুই বলবে না** — না গেট, না পরীক্ষা।
     *
     * ⭐ তাই দুইটা স্তর, আর দুইটার কাজ আলাদা:
     * · `can:` — ভূমিকার প্রশ্ন, আর ঘোষিত বলে যন্ত্র সেটা পড়তে পারে
     * · `mustBeTheOwner()` — ব্যক্তির প্রশ্ন, যা অনুমতি দিয়ে বলা যায় না
     *   (অনুমতিটা অন্য রোলেও বসানো যায়, কিন্তু মালিক একজনই)
     *
     * ⓘ মেনুতে সারিটা লুকানো থাকাও এর বিকল্প নয় — মেনু সৌজন্য, তালা নয়।
     */
    public static function middleware(): array
    {
        return [new Middleware('can:system_admin.ownership.transfer')];
    }

    /**
     * এই পাতাটা কেবল বর্তমান মালিকের।
     *
     * ── কেন অনুমতি যথেষ্ট নয় ─────────────────────────────────────────
     * `system_admin.ownership.transfer` অনুমতিটা মেনুর সারিটা লুকিয়ে
     * রাখে, কিন্তু অনুমতি একটা **ভূমিকার** কথা বলে, ব্যক্তির নয়। ⛔ কেউ
     * চাইলে ওটা অন্য রোলেও বসাতে পারেন, আর তখন একজন অ-মালিক পাতাটা
     * খুলে ফেলতেন।
     *
     * ⓘ ৪০৩, কারণ পাতাটার অস্তিত্ব লুকানোর কিছু নেই — মেনুতে ওটা
     * মালিকের চোখে দেখাই যায়, আর "আপনি এটা পারবেন না" এখানে সৎ উত্তর।
     */
    private function mustBeTheOwner(Request $request): int
    {
        $companyId = (int) CompanyContext::id();

        abort_unless(
            $request->user() !== null
                && $this->ownership->isOwnerIn($request->user(), $companyId),
            403,
        );

        return $companyId;
    }

    public function show(Request $request): View
    {
        $companyId = $this->mustBeTheOwner($request);

        return view('system_admin::ownership.show', [
            'menu' => $this->menu->forUser($request->user()),
            'currentOwner' => $this->ownership->activeOwnersIn($companyId)->first(),

            /*
             * ── তালিকায় কারা ─────────────────────────────────────────
             * এই কোম্পানিতে ঢুকতে পারেন এমন সক্রিয় ব্যবহারকারীরা, বর্তমান
             * মালিক বাদে। ⓘ যিনি কোম্পানিটাই খুলতে পারেন না, তাঁকে মালিক
             * বানালে নিয়ম মতে মালিক থাকতেন, বাস্তবে কেউ থাকত না।
             */
            'candidates' => User::query()
                ->where('is_active', true)
                ->whereHas('companies', fn ($q) => $q->whereKey($companyId))
                ->whereNot('id', $this->ownership->activeOwnersIn($companyId)->first()?->id ?? 0)
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $companyId = $this->mustBeTheOwner($request);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'password' => ['required', 'string'],
        ]);

        /*
         * ⚠️ পাসওয়ার্ড আগে, তারপর বাকি সব।
         *
         * উল্টো ক্রমে লিখলে ভুল পাসওয়ার্ড দিয়েও জানা যেত কে মালিক আর কে
         * নন — ভুলের বার্তাগুলোই তথ্য ফাঁস করত।
         */
        if (! Hash::check($data['password'], (string) $request->user()?->password)) {
            return back()->withErrors(['password' => __('auth.password_wrong')]);
        }

        /*
         * ⛔ ছাঁকনিটা এখানেই, দূরের কোনো যাচাইয়ের ভরসায় নয়।
         *
         * আগে লেখা ছিল `User::query()->findOrFail(...)` — অন্য কোম্পানির
         * একটা id পাঠালে সেই মানুষটাকে খুঁজে আনা যেত। ⓘ `transfer()`
         * নিজেও যাচাই করে, তাই বাস্তবে হস্তান্তরটা আটকাত — কিন্তু
         * **সেটাই সমস্যা**: নিয়মটা দূরে, আর কোয়েরিটা অন্ধ। কেউ একদিন
         * `transfer()` রিফ্যাক্টর করলে এই লাইনটা নীরবে খুলে যেত।
         *
         * ⚠️ আর ৪০৪ আর ৪২২ দুইটা আলাদা কথা বলে: এখন অন্য কোম্পানির id
         * পাঠালে "এমন কেউ নেই" — যা সত্যি, **এই কোম্পানিতে**। আগে
         * বলত "এই মানুষটা মালিক হতে পারেন না", অর্থাৎ তাঁর অস্তিত্বটাই
         * ফাঁস করত।
         */
        $to = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey($companyId))
            ->findOrFail($data['user_id']);

        $this->ownership->transfer($request->user(), $to, $companyId);

        /*
         * ⓘ হস্তান্তরের পর এই পাতায় ফেরত পাঠানো হয় না — যিনি এইমাত্র
         * চাবিটা দিয়ে দিলেন, তিনি আর এখানে ঢুকতে পারেন না, আর একটা ৪০৩
         * দিয়ে কাজটা শেষ হওয়া বিভ্রান্তিকর হত।
         */
        return redirect()
            ->route('dashboard')
            ->with('saved', __('system_admin::message.ownership_transferred', ['name' => $to->name]));
    }
}
