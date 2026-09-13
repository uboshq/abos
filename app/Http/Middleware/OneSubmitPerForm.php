<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Services\FormIsNotSubmittedTwice;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⛔ একটা ফর্ম একবার আঁকা, একবারই জমা — ১৩ সেপ্টেম্বর ২০২৬।
 *
 * ── কেন middleware, প্রতিটা কন্ট্রোলারে নয় ───────────────────────────
 * মেপে দেখা: ২৬৯টা POST রুট, ১৪৭টা ব্লেড ফাইলে ফর্ম। ⓘ কন্ট্রোলারে
 * হাতে বসালে আজ ২৬৯টা সঠিক হত আর **২৭০তমটা হত না** — আর সেটা কেউ
 * জানত না, কারণ পর্দাটা কাজ করত, শুধু পাহারাটা থাকত না।
 *
 * ⚠️ সিদ্ধান্তগুলো এখানে নেই, [[FormIsNotSubmittedTwice]]-এ। এই
 * শ্রেণিটা কেবল **অনুবাদ করে**: সেবাটা বলে "এটা পুনরাবৃত্তি", আর এটা
 * ঠিক করে ব্রাউজারকে কী উত্তর দেওয়া হবে। ⓘ [[CredentialCheck]]-এ একই
 * ভাগ: সেবা একটা ফল ফেরত দেয়, দরজা নিজের ভাষায় উত্তর দেয়।
 */
class OneSubmitPerForm
{
    /**
     * ⓘ যে পদ্ধতিগুলো কিছু বদলায়।
     *
     * `GET` বাদ, কারণ সেখানে দুইবার চাওয়া ক্ষতি করে না — আর ওখানে
     * টোকেন চাইলে প্রতিটা তালিকার পাতাও একটা সারি লিখত।
     *
     * @var list<string>
     */
    private const GUARDED = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly FormIsNotSubmittedTwice $forms) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->input(FormIsNotSubmittedTwice::FIELD, '');

        /*
         * ⚠️ টোকেন না থাকলে কাজ থামে না, আর সেটা ইচ্ছাকৃত।
         *
         * ⛔ পাহারার অভাবে ব্যবহারকারীর কাজ আটকানো ভুল বিনিময়: একটা
         * পুরনো পর্দা বা একটা API ডাক তখন ৫০০ পেত, অথচ তার দোষ নেই।
         *
         * ⭐ "প্রতিটা ফর্ম সত্যিই টোকেন বয়ে আনছে কি না" — সেই প্রশ্নের
         * উত্তর দেয় [[EveryFormCarriesItsOwnTokenTest]], রানটাইমে নয়,
         * CI-তে। যন্ত্র ধরুক, ব্যবহারকারী নয়।
         */
        if ($token === '' || ! in_array($request->method(), self::GUARDED, true)) {
            return $next($request);
        }

        if (! $this->forms->claim($token, $request->user()?->id, $request->route()?->getName())) {
            return $this->alreadyDone($token);
        }

        /*
         * ⚠️ ঘরটা সরিয়ে দেওয়া হয় — যাতে সে কোনো সেবার হাতে না পড়ে।
         *
         * ⓘ বেশিরভাগ কন্ট্রোলার `validate()`-এর ফল ব্যবহার করে, তাই
         * বাড়তি ঘর এমনিতেই বাদ যায়। ⛔ কিন্তু কেউ `$request->all()`
         * দিয়ে কিছু বানালে `_once` **একটা কলামের নাম হয়ে ঢোকার চেষ্টা
         * করত**, আর ভুলটা ধরা পড়ত অন্য কারও পর্দায়।
         */
        $request->request->remove(FormIsNotSubmittedTwice::FIELD);

        $response = $next($request);

        /*
         * ফলটা মনে রাখা — কেবল redirect হলে।
         *
         * ⓘ এই অ্যাপে জমার উত্তর প্রায় সবসময়ই একটা redirect (POST →
         * Redirect → GET)। ⚠️ যেটা নয় — যেমন একটা ফাইল নামানো — তার
         * কোনো "ঠিকানা" নেই যেখানে পাঠানো যায়, তাই কিছু লেখা হয় না,
         * আর পুনরাবৃত্তি নিচের সময়-শেষ পথে যায়।
         */
        if ($response instanceof RedirectResponse) {
            $this->forms->remember($token, $response->getTargetUrl());
        }

        return $response;
    }

    /**
     * এটা পুনরাবৃত্তি — ব্যবহারকারীকে প্রথমবারের ফলেই পাঠানো।
     *
     * ── ⭐ কেন ত্রুটি দেখানো হয় না ──────────────────────────────────
     * তিনি ভুল কিছু করেননি — একটা বোতামে দুইবার চাপা ভুল নয়, আর ধীর
     * নেটে ওটাই স্বাভাবিক আচরণ। ⛔ লাল ত্রুটি দেখালে তিনি ভাবতেন কিছু
     * ভেঙেছে আর **তৃতীয়বার চেষ্টা করতেন**।
     *
     * ⓘ তাই উত্তরটা শান্ত: যে সারিটা সত্যিই তৈরি হয়েছে, তিনি সেটাই
     * দেখেন, সাথে এক লাইনে কী ঘটেছে।
     */
    private function alreadyDone(string $token): RedirectResponse
    {
        $url = $this->forms->resultFor($token);

        if ($url !== null) {
            return redirect()->to($url)->with('saved', __('core.form.already_saved'));
        }

        /*
         * ⚠️ প্রথম অনুরোধটা এখনো শেষ হয়নি (সেবাটা অপেক্ষা করেও ফল পায়নি)।
         *
         * ⛔ এখানে "কিছু হয়নি" ধরনের বার্তা দেওয়া যাবে না — সেটাই
         * তৃতীয় ক্লিক ডেকে আনত। ⭐ বার্তাটা তাই **নিশ্চিত করে যে জমা
         * হয়েছে**, আর কেবল দেখার জায়গাটা এখনো তৈরি হয়নি।
         */
        return back()->with('saved', __('core.form.still_saving'));
    }
}
