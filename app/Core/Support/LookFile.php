<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Models\LookSkin;
use Illuminate\Validation\ValidationException;

/**
 * একটা রূপ যখন একটা ফাইল — থিম ইঞ্জিনের ধাপ ৪ (অংশ ৯)।
 *
 * ── কেন এটা লাগে ─────────────────────────────────────────────────────
 * আজ পর্যন্ত একটা রূপ যে ডাটাবেজে জন্মেছে সেখানেই বন্দী। তার মানে:
 *
 *   · আমরা গ্রাহকের জন্য একটা রূপ বানিয়ে **পাঠাতে পারি না** — হয়
 *     তাঁর সার্ভারে বসে বানাতে হয়, নয় ফোনে ষাটটা রঙের কোড বলতে হয়
 *   · ডেভ মেশিনে বানিয়ে লাইভে নেওয়ার কোনো পথ নেই, তাই রূপ নিয়ে
 *     পরীক্ষা করতে হয় সরাসরি লাইভে
 *   · চারটা টেন্যান্টের একই রূপ চাইলে চারবার হাতে লিখতে হয়
 *
 * ── কেন ফাইলটা সবসময় একটা কোড-রূপের উপর দাঁড়ায় ───────────────────────
 * রপ্তানির সময় পুরো চেইনটা **সমতল** করা হয়: কোম্পানির রূপ আরেকটা
 * কোম্পানির রূপের উপর দাঁড়ালেও ফাইলে লেখা থাকে গোড়ার কোড-রূপের নাম
 * (`apps`, `navy`), আর সাথে জমা হওয়া সব পার্থক্য।
 *
 * কারণ `public_id` অন্য ইনস্টলে কিছুই বোঝায় না। ওটা লিখে রাখলে ফাইলটা
 * আমদানির সময় হয় ভাঙত, নয় নীরবে ভুল কিছুর উপর দাঁড়াত — আর দ্বিতীয়টা
 * অনেক খারাপ, কারণ রংগুলো তখন "প্রায় ঠিক" দেখাত।
 *
 * সমতল করেও ফাইলটা ছোট থাকে: কোড-রূপের সাথে যেটুকু পার্থক্য কেবল
 * সেটুকুই লেখা হয়, ষাটটা টোকেন নয়।
 *
 * ── আমদানি সবসময় খসড়া ─────────────────────────────────────────────────
 * বাইরে থেকে আসা একটা ফাইল কখনো সরাসরি সবার পর্দায় যায় না। সে খসড়া
 * হিসেবে বসে, আর প্রকাশের সেই একই গেট পার হয়ে তবেই দেখা যায়।
 */
final class LookFile
{
    /**
     * ফাইলের গড়নের সংস্করণ।
     *
     * ── কেন এটা লেখা থাকে ───────────────────────────────────────────
     * গড়নটা একদিন বদলাবে — নতুন একটা ঘর, বা টোকেনের অন্যরকম বিন্যাস।
     * সংখ্যাটা না থাকলে পুরনো একটা ফাইল নতুন কোডে নীরবে ভুল পড়া হত,
     * আর ফল হত একটা রূপ যা দেখতে প্রায় ঠিক।
     *
     * এখানে "প্রায় ঠিক" সবচেয়ে খারাপ ফল: কেউ ধরতে পারত না।
     */
    public const FORMAT = 1;

    /**
     * ফাইলটা আদৌ একটা রূপ কি না, তার চিহ্ন।
     *
     * JSON ফাইল সবই দেখতে এক। এটা না থাকলে কেউ ভুল করে একটা রপ্তানি
     * করা তালিকা তুলে দিলে ভুলের বার্তা হত "tokens ঘরটা নেই" — যা
     * পড়ে কেউ বুঝত না তিনি ভুল ফাইল দিয়েছেন।
     */
    public const KIND = 'abos.look';

    /**
     * একটা রূপ, ফাইলে বসার মতো।
     *
     * ── কেন খসড়াটা, প্রকাশিতটা নয় ───────────────────────────────────
     * সম্পাদকের পর্দায় বোতামটা থাকে, আর তিনি যা দেখছেন সেটাই নামে।
     * প্রকাশিতটা নামালে অর্ধেক লেখা একটা রূপ অন্য মেশিনে নিয়ে শেষ
     * করার পথটাই বন্ধ হত — অথচ ওটাই এই ফাইলের সবচেয়ে সাধারণ ব্যবহার।
     *
     * @return array<string, mixed>
     */
    public static function from(LookSkin $skin): array
    {
        $root = $skin->rootLook();

        return [
            'kind' => self::KIND,
            'format' => self::FORMAT,
            'name' => $skin->name,
            'stands_on' => $root,
            'tokens' => self::flatten($skin, $root),

            /*
             * কোথা থেকে এল — আমদানির সময় এটা পড়া হয় না।
             *
             * তবু লেখা থাকে, কারণ ছয় মাস পরে একটা ফাইল হাতে পেয়ে
             * প্রথম প্রশ্নটাই হয় "এটা কার, আর কবেকার"। উত্তরটা
             * ফাইলের ভিতরে না থাকলে আর কোথাও নেই।
             */
            'exported' => [
                'at' => now()->toIso8601String(),
                'from' => config('app.name').' v'.config('app.version', '0.1.0'),
                'version' => $skin->live()?->version,
            ],
        ];
    }

    /**
     * ফাইল থেকে একটা খসড়া রূপ।
     *
     * @param  array<string, mixed>  $said
     *
     * @throws ValidationException
     */
    public static function into(array $said, ?int $by = null): LookSkin
    {
        self::refuseIfNotOurs($said);

        $parent = (string) ($said['stands_on'] ?? '');

        if (! in_array($parent, Ui::keys(), true)) {
            throw ValidationException::withMessages([
                'file' => __('core.look.file_unknown_parent', ['name' => $parent]),
            ]);
        }

        /** @var array{light?: array<string, string>, dark?: array<string, string>} $tokens */
        $tokens = $said['tokens'] ?? [];

        $light = self::strings($tokens['light'] ?? []);
        $dark = self::strings($tokens['dark'] ?? []);

        $complaints = [
            ...LookSchema::complaints($light),
            ...LookSchema::complaints($dark),
        ];

        if ($complaints !== []) {
            throw ValidationException::withMessages([
                'file' => array_values(array_unique($complaints)),
            ]);
        }

        return LookSkin::query()->create([
            'name' => self::freeName((string) ($said['name'] ?? '')),
            'parent' => $parent,
            'tokens' => ['light' => $light, 'dark' => $dark],
            'created_by' => $by,

            /*
             * `published_at` ইচ্ছাকৃতভাবে অনুপস্থিত।
             *
             * বাইরে থেকে আসা একটা ফাইল কখনো সরাসরি সবার পর্দায় যায় না।
             * গেটটা প্রকাশের পথে বসানো, আর আমদানি সেটা এড়ানোর দ্বিতীয়
             * দরজা হতে পারে না — নাহলে একটা ফাইল দিয়েই গোটা ডিপোর
             * লেখা অপঠনযোগ্য করে দেওয়া যেত।
             */
        ]);
    }

    /**
     * চেইনটা সমতল করে কোড-রূপের সাথে কেবল পার্থক্যটুকু।
     *
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    private static function flatten(LookSkin $skin, string $root): array
    {
        $light = array_diff_assoc($skin->tokens('light'), LookRegistry::tokens($root, 'light'));
        $dark = array_diff_assoc($skin->tokens('dark'), LookRegistry::tokens($root, 'dark'));

        /*
         * হালকায় যা আছে, গাঢ়ে সেটা আবার লেখা হয় না।
         *
         * হালকার মান গাঢ়েও নামে (কোড-রূপগুলোর মতোই)। দুই জায়গায়
         * একই মান লিখলে ফাইলটা দ্বিগুণ লম্বা হত, আর পড়ে বোঝা যেত না
         * কোনটা সত্যিই রাতের জন্য আলাদা করে বাছা হয়েছে।
         */
        foreach ($dark as $name => $value) {
            if (($light[$name] ?? null) === $value) {
                unset($dark[$name]);
            }
        }

        ksort($light);
        ksort($dark);

        return ['light' => $light, 'dark' => $dark];
    }

    /** @throws ValidationException */
    private static function refuseIfNotOurs(array $said): void
    {
        if (($said['kind'] ?? null) !== self::KIND) {
            throw ValidationException::withMessages([
                'file' => __('core.look.file_not_a_look'),
            ]);
        }

        $format = (int) ($said['format'] ?? 0);

        if ($format < 1 || $format > self::FORMAT) {
            throw ValidationException::withMessages([
                'file' => __('core.look.file_wrong_format', [
                    'found' => $format,
                    'known' => self::FORMAT,
                ]),
            ]);
        }
    }

    /**
     * নামটা এই কোম্পানিতে খালি কি না — না হলে একটা সংখ্যা জুড়ে দেওয়া।
     *
     * ── কেন নীরবে বদলানো হয় না, আর আটকানোও হয় না ─────────────────────
     * `(company_id, name)` অনন্য, তাই একই নামে দ্বিতীয়টা বসে না।
     * আটকে দিলে মানুষকে ফাইলটা সম্পাদনা করে নাম বদলাতে হত — একটা
     * JSON ফাইল, হাতে।
     *
     * তাই সংখ্যা জুড়ে দেওয়া হয়, কিন্তু **চুপচাপ নয়**: কন্ট্রোলার
     * সফলতার বার্তায় নতুন নামটাই দেখায়, তাই কেউ ভাবেন না তাঁর পুরনো
     * রূপটা বদলে গেছে।
     */
    private static function freeName(string $wanted): string
    {
        $wanted = trim($wanted) !== '' ? trim($wanted) : __('core.look.new');
        $wanted = mb_substr($wanted, 0, 110);

        $name = $wanted;
        $n = 1;

        while (LookSkin::query()->where('name', $name)->exists()) {
            $name = $wanted.' ('.(++$n).')';
        }

        return $name;
    }

    /**
     * কেবল স্ট্রিং-এর জোড়াগুলো।
     *
     * JSON-এ যেকোনো কিছু থাকতে পারে — বাসা-বাঁধা অ্যারে, সংখ্যা,
     * `null`। ওগুলো স্কিমার হাতে দিলে সে টাইপ-ভুলে ভাঙত, আর বার্তাটা
     * ফাইল সম্পর্কে কিছুই বলত না।
     *
     * @return array<string, string>
     */
    private static function strings(mixed $said): array
    {
        if (! is_array($said)) {
            return [];
        }

        $out = [];

        foreach ($said as $name => $value) {
            if (is_string($name) && (is_string($value) || is_numeric($value))) {
                $out[$name] = (string) $value;
            }
        }

        return $out;
    }
}
