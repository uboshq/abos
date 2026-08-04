<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * ব্যবহারকারীর ছবি — কাটা, ছোট করা, সংরক্ষণ।
 *
 * কেন নিজে লেখা, লাইব্রেরি নয়: কাজটা তিনটা — ঘোরানো ঠিক করা, বর্গ করে
 * কাটা, ছোট করা। এর জন্য একটা পুরো ইমেজ লাইব্রেরি টানলে প্রতিটা আপগ্রেডে
 * সেটাও রক্ষণাবেক্ষণ করতে হয়। GD সব সার্ভারেই থাকে।
 *
 * নিরাপত্তা (নিয়ম ৪ — প্রতিটা ইনপুটে ভ্যালিডেশন):
 *
 * - ফাইলের এক্সটেনশন বা ব্রাউজারের বলা MIME বিশ্বাস করা হয় না; ছবিটা
 *   আসলে খোলা যায় কি না সেটাই একমাত্র প্রমাণ। "photo.jpg" নাম দিয়ে PHP
 *   ফাইল পাঠানোর পুরনো আক্রমণটা এখানেই আটকায়।
 * - SVG নেওয়া হয় না। SVG-তে <script> থাকতে পারে, আর সেটা একই ডোমেইন
 *   থেকে পরিবেশন করা মানে ব্যবহারকারীর সেশন ওই স্ক্রিপ্টের হাতে।
 * - ফলাফল সবসময় নতুন করে আঁকা JPEG — উৎস ফাইলটা কখনো ডিস্কে যায় না,
 *   তাই ছবির ভেতরে লুকানো কিছু (EXIF-এ বসানো পে-লোড) সাথে যায় না।
 */
class AvatarService
{
    /** পর্দায় সবচেয়ে বড় যেখানে দেখানো হয় ৯৬px; দ্বিগুণ ঘনত্বের পর্দার জন্য ২৫৬। */
    public const SIZE = 256;

    public const MAX_BYTES = 5 * 1024 * 1024;

    /** @var list<string> */
    public const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];

    private const DIRECTORY = 'avatars';

    private const DISK = 'public';

    /**
     * আপলোড করা ছবি বসানো। আগেরটা থাকলে মুছে যায়।
     *
     * @return string সংরক্ষিত পথ
     */
    public function store(User $user, UploadedFile $file): string
    {
        if ($file->getSize() > self::MAX_BYTES) {
            throw new RuntimeException('avatar.too_large');
        }

        $image = $this->open($file->getRealPath());
        $image = $this->applyExifOrientation($image, $file->getRealPath());
        $image = $this->squareThumbnail($image);

        // নামে এলোমেলো অংশ: একই ব্যবহারকারী ছবি বদলালে পুরনো নামটাই আবার
        // ব্যবহার করলে ব্রাউজার ও CDN আগেরটা ক্যাশ থেকে দেখাত, আর
        // ব্যবহারকারী ভাবত আপলোড হয়নি।
        $path = self::DIRECTORY.'/'.$user->getKey().'-'.bin2hex(random_bytes(8)).'.jpg';

        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

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

    /**
     * ফাইলটা সত্যিই ছবি কি না — খুলে দেখে।
     */
    private function open(string $path): GdImage
    {
        $info = @getimagesize($path);

        if ($info === false || ! in_array($info['mime'] ?? '', self::ACCEPTED, true)) {
            throw new RuntimeException('avatar.not_an_image');
        }

        $image = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
        };

        if (! $image instanceof GdImage) {
            throw new RuntimeException('avatar.not_an_image');
        }

        return $image;
    }

    /**
     * EXIF অনুযায়ী ঘুরিয়ে সোজা করা।
     *
     * ফোনে তোলা ছবি প্রায়ই পাশ ফিরে সংরক্ষিত হয় আর ঘোরানোর কথাটা শুধু
     * EXIF-এ লেখা থাকে। GD সেটা পড়ে না, তাই এই ধাপ বাদ দিলে অর্ধেক
     * ব্যবহারকারীর ছবি কাত হয়ে বসত — আর বর্গ করে কাটার সময় মুখটাই
     * বাদ পড়ত।
     */
    private function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated instanceof GdImage) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    /**
     * বর্গ করে কেটে ছোট করা।
     *
     * লম্বালম্বি ছবিতে ঠিক মাঝখান থেকে কাটা হয় না — মানুষের ছবিতে মুখ
     * প্রায় সবসময় উপরের দিকে থাকে, আর মাঝখান থেকে কাটলে মুখের বদলে বুক
     * আসে। উপর থেকে বাকি উচ্চতার ১০% নিচে শুরু করলে মাথার উপর একটু
     * ফাঁকা থাকে আর মুখটা পুরো ধরা পড়ে।
     *
     * আড়াআড়ি ছবিতে অনুভূমিকভাবে মাঝখান — ওখানে উপর-নিচের পক্ষপাতের
     * কারণ নেই।
     */
    private function squareThumbnail(GdImage $image): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $side = min($w, $h);

        $x = intdiv($w - $side, 2);
        $y = $h > $w ? (int) round(($h - $side) * 0.10) : intdiv($h - $side, 2);

        $out = imagecreatetruecolor(self::SIZE, self::SIZE);

        // স্বচ্ছ PNG থেকে এলে ফাঁকা অংশ কালো হয়ে যেত; JPEG-এ স্বচ্ছতা
        // নেই, তাই সাদা দিয়ে ভরা হয়।
        imagefilledrectangle($out, 0, 0, self::SIZE, self::SIZE, imagecolorallocate($out, 255, 255, 255));

        imagecopyresampled($out, $image, 0, 0, $x, $y, self::SIZE, self::SIZE, $side, $side);
        imagedestroy($image);

        return $out;
    }
}
