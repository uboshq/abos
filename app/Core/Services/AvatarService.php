<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Engines\Image\ImageEngine;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * ব্যবহারকারীর ছবি — কাটা, ছোট করা, সংরক্ষণ।
 *
 * ── ⭐ ১৪ সেপ্টেম্বর ২০২৬: GD-র কাজটা এখান থেকে সরে গেছে ──────────────
 * আগে এই ফাইলে ছবি খোলা, EXIF অনুযায়ী ঘোরানো আর বর্গ করে কাটার কোড
 * নিজের ছিল, আর সেটা **কাজ করত**। ⓘ সরানো হয়েছে একটাই কারণে: মালিক
 * বললেন সংযুক্তিতেও একই ব্যবস্থা লাগবে — *"যেকোনো ফটো আপলোডের সময়"*।
 *
 * ⛔ কোডটা এখানে রেখে দিলে ঐ ব্যবস্থা [[App\Core\Engines\Attachment\AttachmentEngine]]-এ
 * **দ্বিতীয়বার** লিখতে হত, আর ছবি জমা করে এমন জায়গা এই রিপোতে গুনে
 * পাওয়া গেছে **চারটা** (সংযুক্তি, প্রোফাইল, পণ্যের ছবি, কোম্পানির লোগো)।
 * ⚠️ চারবার লেখা মানে একদিন চারটা আলাদা আচরণ।
 *
 * ⭐ তাই ছবির কাজ এখন [[App\Core\Engines\Image\ImageEngine]]-এর, আর এই
 * সেবাটা যা জানে কেবল তাই করে: **কোন ব্যবহারকারীর ছবি, কোথায় বসবে,
 * পুরনোটা কখন মুছবে**।
 *
 * ── নিরাপত্তা (নিয়ম ৪ — প্রতিটা ইনপুটে ভ্যালিডেশন) ───────────────────
 * - ফাইলের এক্সটেনশন বা ব্রাউজারের বলা MIME বিশ্বাস করা হয় না; ছবিটা
 *   আসলে খোলা যায় কি না সেটাই একমাত্র প্রমাণ। "photo.jpg" নাম দিয়ে PHP
 *   ফাইল পাঠানোর পুরনো আক্রমণটা ইঞ্জিনের `open()`-এই আটকায়।
 * - SVG নেওয়া হয় না। SVG-তে `<script>` থাকতে পারে, আর সেটা একই ডোমেইন
 *   থেকে পরিবেশন করা মানে ব্যবহারকারীর সেশন ওই স্ক্রিপ্টের হাতে।
 * - ফলাফল সবসময় নতুন করে আঁকা JPEG — উৎস ফাইলটা কখনো ডিস্কে যায় না,
 *   তাই ছবির ভেতরে লুকানো কিছু (EXIF-এ বসানো পে-লোড) সাথে যায় না।
 */
class AvatarService
{
    /**
     * প্রোফাইল ছবির বাহু।
     *
     * ⚠️ আগে ছিল ২৫৬। মালিক বলেছেন *"প্রোপাইল পিক ক্রপ করে রেজুলেশন
     * এডজাস্ট করা"* — তাই এখন ইঞ্জিনের `FACE_EDGE`, অর্থাৎ **৫১২**।
     * ⓘ পর্দায় সবচেয়ে বড় দেখানো হয় ৯৬px; ৫১২ রাখলে দ্বিগুণ-ঘনত্বের
     * পর্দাতেও ছবিটা কড়কড়ে থাকে, আর ওজন ~৩৫KB — সস্তা।
     */
    public const SIZE = ImageEngine::FACE_EDGE;

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @var list<string> */
    public const ACCEPTED = ImageEngine::READS;

    private const DIRECTORY = 'avatars';

    private const DISK = 'public';

    public function __construct(private readonly ImageEngine $images = new ImageEngine) {}

    /**
     * আপলোড করা ছবি বসানো। আগেরটা থাকলে মুছে যায়।
     *
     * ⓘ `$crop` এলে ব্যবহারকারী পর্দায় নিজে যে চৌকোটা টেনে বেছেছেন ঠিক
     * সেটাই নেওয়া হয়; না এলে ইঞ্জিন আন্দাজ করে কাটে (মুখ উপরের দিকে
     * থাকে ধরে)। ⭐ অর্থাৎ ক্রপের পর্দা না থাকলেও আচরণ আগের মতোই।
     *
     * @param  array{x: int, y: int, size: int}|null  $crop
     * @return string সংরক্ষিত পথ
     */
    public function store(User $user, UploadedFile $file, ?array $crop = null): string
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('avatar.too_large');
        }

        $source = $file->getRealPath();

        if ($source === false) {
            throw new RuntimeException('avatar.not_an_image');
        }

        $bytes = $this->images->face($source, $crop);

        // নামে এলোমেলো অংশ: একই ব্যবহারকারী ছবি বদলালে পুরনো নামটাই আবার
        // ব্যবহার করলে ব্রাউজার ও CDN আগেরটা ক্যাশ থেকে দেখাত, আর
        // ব্যবহারকারী ভাবত আপলোড হয়নি।
        $path = self::DIRECTORY.'/'.$user->getKey().'-'.bin2hex(random_bytes(8)).'.jpg';

        $previous = $user->getRawOriginal('avatar_path');

        Storage::disk(self::DISK)->put($path, $bytes);

        $user->forceFill(['avatar_path' => $path])->save();

        // পুরনোটা মোছা সবার শেষে, আগে নয়: লেখা বা সেভ ব্যর্থ হলে
        // ব্যবহারকারী অন্তত আগের ছবিটা ফেরত পায়।
        $this->deleteFile($previous);

        return $path;
    }

    /** ছবি সরানো — ফাইল ও রেকর্ড দুই-ই। */
    public function remove(User $user): void
    {
        $previous = $user->getRawOriginal('avatar_path');

        $user->forceFill(['avatar_path' => null])->save();

        $this->deleteFile($previous);
    }

    private function deleteFile(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
