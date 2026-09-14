<?php

declare(strict_types=1);

namespace App\Core\Engines\Image;

use GdImage;
use RuntimeException;

/**
 * ⛔ ফোনে তোলা ছবি কাগজের মতো করে তোলা — ১৪ সেপ্টেম্বর ২০২৬।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * মালিকের কথা: *"যেকোনো ফটো আপলোডের সময় নিজে থেকে ক্রপ করে নেওয়ার
 * ব্যবস্থা করার কথা ছিল সেটা হয় নাই"*, আর *"নিজেই সাইজ করে নিতে হবে,
 * কত কেবি/এমবি হবে নিজের বানিয়ে নিতে হবে — CamScanner-এর মতো"*।
 *
 * ⓘ মেপে দেখা: [[App\Core\Services\AvatarService]] ঘোরানো, কাটা ও
 * সংকোচন **আগে থেকেই করে** — কিন্তু কেবল প্রোফাইল ছবির জন্য।
 * ⛔ সংযুক্তিতে কিছুই হত না: বিলের ছবি যেমন আসে তেমনই জমা হত — পাশ
 * ফিরে, আট মেগাবাইট, কোনো প্রক্রিয়া ছাড়া।
 *
 * ── ⭐ কেন কোনো প্যাকেজ বসানো হয়নি ──────────────────────────────────
 * মেপে দেখা: **Imagick নেই, GD আছে**, আর কোনো ইমেজ প্যাকেজও নেই।
 * ⓘ সাইটটা শেয়ার্ড cPanel-এ; একটা নতুন এক্সটেনশন চাওয়া মানে হোস্টের
 * উপর নির্ভরতা, আর একটা নতুন প্যাকেজ মানে প্রতি ডিপ্লয়ে বাড়তি ভার।
 * ⭐ GD দিয়ে নিচের সবটাই হয়।
 *
 * ── ⚠️ এই ইঞ্জিন যা করে **না**, আর সেটা লিখে রাখা জরুরি ─────────────
 * ⛔ কাগজের **চার কোণ খুঁজে বের করা** আর **বাঁকা ছবি সোজা করা**
 * (perspective warp) — এখানে নেই। ওটা কম্পিউটার ভিশনের কাজ, আর খাঁটি
 * PHP-তে লিখলে প্রতি ছবিতে কয়েক সেকেন্ড যেত শেয়ার্ড হোস্টিংয়ে।
 *
 * ⓘ ঐ দুইটা আসে পরের দুই ধাপে: হাতে চার কোণ বেছে দেওয়া, আর তারপর
 * আপনাআপনি কোণ শনাক্তকরণ। ⭐ কিন্তু **এই ইঞ্জিনটা তখনও লাগবে** —
 * সোজা করার আগে ঘোরানো আর পরে সংকোচন, দুইটাই এখান থেকেই হবে।
 *
 * ⚠️ তাই এখানে যা আছে তাকে "CamScanner" বলা হয়নি, বলা হয়েছে **"কাগজের
 * চেহারা"** — কারণ যে নাম বেশি প্রতিশ্রুতি দেয়, সে পরের জনকে ভুল পথে
 * নিয়ে যায়।
 *
 * ── ⛔ `imagedestroy()` এখানে একবারও ডাকা হয়নি — ইচ্ছাকৃত ─────────────
 * ⓘ মেপে দেখা: এই মেশিনে PHP **৮.৫.১০**, আর ৮.৫ থেকে `imagedestroy()`
 * **বাতিল** — কারণ ৮.০ থেকেই ওটার কোনো কাজ নেই, GD-র ছবি এখন সাধারণ
 * বস্তুর মতোই নিজে থেকে মুক্ত হয়।
 *
 * ⚠️ প্রথমে ওগুলো লেখা হয়েছিল ([[AvatarService]]-এর ছাঁচ অনুসরণ করে), আর
 * প্রতিটা ছবিতে তিন-চারটা deprecation সতর্কতা লগে পড়ছিল। ⭐ সরানো
 * হয়েছে — আর [[AvatarService]]-এর তিনটাও এই কাজের অংশ হিসেবে যাবে।
 */
final class ImageEngine
{
    /**
     * ⓘ যে ধরনগুলো GD খুলতে পারে — আর আমরা ছুঁই।
     *
     * ⚠️ PDF এখানে নেই, ইচ্ছাকৃতভাবে: একটা স্ক্যান করা PDF-কে ছবি বানিয়ে
     * ফেললে পাতাগুলো হারাত আর লেখা নির্বাচনযোগ্য থাকত না। ⭐ যা ছবি নয়,
     * সেটা অচ্ছুৎ — ইঞ্জিন তাকে ফিরিয়ে দেয়, নষ্ট করে না।
     *
     * @var list<string>
     */
    public const READS = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * ⭐ কাগজের ছবির সর্বোচ্চ লম্বা বাহু।
     *
     * ── কেন ২০০০ ─────────────────────────────────────────────────────
     * A4 কাগজ ২০০০px চওড়ায় ধরলে প্রতি ইঞ্চিতে ~২৪০ বিন্দু — ছাপা লেখা
     * পড়তে ১৫০ যথেষ্ট, তাই এতে হাতের লেখাও পড়া যায়। ⚠️ ফোনের ১২
     * মেগাপিক্সেল ছবিতে ৪০০০px থাকে, আর তার অর্ধেকটাই লেন্সের ঝাপসা —
     * ওটা রাখা মানে কেবল বাইট রাখা, তথ্য নয়।
     */
    public const PAPER_EDGE = 2000;

    /**
     * ⭐ একটা কাগজের ছবি সর্বোচ্চ কত বাইট।
     *
     * ⓘ মালিক বলেছেন *"কত কেবি/এমবি হবে নিজের বানিয়ে নিতে হবে"* — তাই
     * সংখ্যাটা ব্যবহারকারীর কাছে চাওয়া হয় না, ইঞ্জিন নিজে নামিয়ে আনে।
     *
     * ⚠️ ৮০০KB বেছে নেওয়ার কারণ ব্যবহারিক: একটা বিলের ছবি এতে পরিষ্কার
     * থাকে, আর ডিপোর মোবাইল ইন্টারনেটে দশটা সংযুক্তি খুলতে কয়েক সেকেন্ড।
     * ⓘ মূল ফাইল প্রায়ই ৪–৮MB, অর্থাৎ দশ গুণের বেশি কমে।
     */
    public const PAPER_BYTES = 800 * 1024;

    /** প্রোফাইল ছবির বাহু — বর্গাকার। */
    public const FACE_EDGE = 512;

    /** প্রোফাইল ছবির সর্বোচ্চ বাইট। */
    public const FACE_BYTES = 120 * 1024;

    /**
     * লোগোর সর্বোচ্চ লম্বা বাহু।
     *
     * ⓘ ছাপার কাগজে লোগো বসে ~২ ইঞ্চি চওড়ায়; ৮০০px মানে ৪০০ DPI —
     * ছাপাখানার চেয়েও বেশি। ⚠️ এর বেশি রাখলে কেবল প্রতিটা PDF ভারী হত।
     */
    public const MARK_EDGE = 800;

    public const MARK_BYTES = 200 * 1024;

    /**
     * এই ফাইলটা কি আমরা ছুঁতে পারি?
     *
     * ⓘ `getimagesize()` ফাইলের **ভিতর** দেখে, নাম বা ব্রাউজারের বলা
     * ধরন নয়। ⚠️ `.jpg` নামে একটা PDF এলে নাম দেখে বিশ্বাস করলে GD
     * ওটা খুলতে গিয়ে ব্যর্থ হত, আর ব্যবহারকারী একটা অর্থহীন ত্রুটি
     * পেতেন।
     */
    public function reads(string $path): bool
    {
        $info = @getimagesize($path);

        if ($info === false || ! in_array($info['mime'] ?? '', self::READS, true)) {
            return false;
        }

        return $this->fitsInMemory((int) $info[0], (int) $info[1]);
    }

    /**
     * ⛔ এই ছবিটা মেমরিতে ধরবে কি? — না ধরলে **ছোঁয়াই হয় না**।
     *
     * ── কেন এই পাহারাটা `try/catch`-এর বদলে আগেই ───────────────────────
     * ⚠️ মেমরি শেষ হওয়া একটা **fatal error**, ব্যতিক্রম নয় — অর্থাৎ
     * [[AttachmentEngine]]-এর `catch (\Throwable)` ওটা ধরতে **পারবে না**।
     * ⛔ ধরা না পড়লে ফলাফল হত সবচেয়ে খারাপটা: অনুরোধটাই মরে যেত আর
     * ব্যবহারকারীর তোলা বিলটা **কোথাও জমা হত না**।
     *
     * ⓘ তাই হিসাবটা আগে করা হয়: GD প্রতিটা বিন্দুতে ৪ বাইট নেয় (RGBA),
     * আর কাজের মধ্যে একসাথে দুই কপি থাকে (মূল ও ছোট করা) — তাই ২.৫ গুণ
     * ধরে রাখা হয়।
     *
     * ⭐ ধরবে না মনে হলে ইঞ্জিন "আমি এটা পড়ি না" বলে সরে যায়, আর ফাইলটা
     * কাঁচাই জমা হয় — বড় ফাইল থাকা, কাগজ হারানোর চেয়ে ভালো।
     *
     * ⚠️ মেপে দেখা: এই মেশিনে `memory_limit` ৫১২M, অর্থাৎ সীমা প্রায়
     * ৫ কোটি বিন্দু (~৫০ মেগাপিক্সেল) — ফোনের ছবি ১২, DSLR ২৪। তাই
     * সাধারণ কোনো ছবি এতে আটকাবে না; আটকাবে কেবল অসাধারণটা।
     */
    private function fitsInMemory(int $width, int $height): bool
    {
        $limit = $this->memoryLimit();

        // সীমা না থাকলে (−1) হিসাব করার কিছু নেই।
        if ($limit <= 0) {
            return true;
        }

        $needed = (int) ($width * $height * 4 * 2.5);

        return $needed < ($limit - memory_get_usage(true));
    }

    private function memoryLimit(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $unit = strtolower(substr($raw, -1));
        $value = (int) $raw;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * ⭐ একটা কাগজের ছবি — সোজা, ছাঁটা, পরিষ্কার, ছোট।
     *
     * ── ধাপগুলো, আর প্রতিটার কারণ ────────────────────────────────────
     * ১. **ঘোরানো** — ফোন ছবিটা কাত করে রাখে আর সোজা করার কথাটা কেবল
     *    EXIF-এ লেখে। ⓘ GD সেটা পড়ে না, তাই এটা না করলে অর্ধেক
     *    সংযুক্তি পাশ ফিরে বসত।
     * ২. **ছোট করা** — লম্বা বাহু `PAPER_EDGE`-এ।
     * ৩. **ফাঁকা প্রান্ত ছাঁটা** — কাগজের ছবিতে চারপাশে প্রায়ই এক রঙা
     *    টেবিল বা দেয়াল থাকে। ⚠️ এটা কাগজের কোণ খোঁজা **নয়**, কেবল
     *    এক রঙা কিনারা ফেলে দেওয়া — বাঁকা ছবি এতে সোজা হয় না।
     * ৪. **পরিষ্কার করা** — কনট্রাস্ট বাড়িয়ে কাগজ সাদা আর লেখা কালো।
     * ৫. **সংকোচন** — মান ধাপে ধাপে কমিয়ে `PAPER_BYTES`-এর নিচে।
     *
     * ── ⭐ কেন ছোট করাটা **দ্বিতীয়** ধাপে, ছাঁটার আগে ───────────────────
     * ⓘ মেপে দেখা (১৪ সেপ্টেম্বর, ১২ মেগাপিক্সেল ছবি): ছাঁটা ও পরিষ্কার
     * করা আগে চালালে GD-কে ঐ কাজ **১২ লক্ষ** বিন্দুতে করতে হত; ছোট করার
     * পরে করলে **৩ লক্ষে** — চার ভাগের এক।
     *
     * ⚠️ দাম দিতে হয় একটু: ছাঁটা যেহেতু পরে, চূড়ান্ত লম্বা বাহু
     * `PAPER_EDGE`-এর চেয়ে **ছোট** হয় (যতটা প্রান্ত কাটা গেল, ততটা)।
     * ⓘ সেটা ক্ষতি নয় — কাগজটা তো সবটুকুই আছে, কেবল টেবিলটা নেই।
     */
    public function paper(string $path): string
    {
        $image = $this->open($path);
        $image = $this->straighten($image, $path);
        $image = $this->fit($image, self::PAPER_EDGE);
        $image = $this->trimPlainEdges($image);
        $image = $this->clarify($image);

        return $this->squeeze($image, self::PAPER_BYTES);
    }

    /**
     * প্রোফাইল ছবি — বর্গাকার, ছোট।
     *
     * ⓘ `$crop` দিলে ঠিক ঐ অংশটাই নেওয়া হয় (ব্যবহারকারী পর্দায় যা
     * বেছেছেন); না দিলে ইঞ্জিন নিজে বর্গ করে কাটে।
     *
     * ── ⚠️ দুই পথে ক্রম আলাদা, আর কারণটা সূক্ষ্ম ──────────────────────
     * **নিজে আন্দাজ করলে** আগে ছোট করা হয়, তারপর কাটা — কারণ আন্দাজটা
     * অনুপাতের হিসাব, ছোট ছবিতেও একই ফল দেয়, আর কাজ অনেক কম।
     *
     * ⛔ **ব্যবহারকারী বেছে দিলে উল্টোটা করা যায় না**: তাঁর দেওয়া
     * সংখ্যাগুলো **মূল ছবির** বিন্দু ধরে মাপা। আগে ছোট করলে ঐ সংখ্যাগুলো
     * অর্থ হারাত, আর কাটা অংশটা সরে যেত — ব্যবহারকারী যা বেছেছেন তার
     * বদলে অন্য কিছু আসত। তাই কাটা আগে, ছোট করা পরে।
     *
     * @param  array{x: int, y: int, size: int}|null  $crop
     */
    public function face(string $path, ?array $crop = null): string
    {
        $image = $this->open($path);
        $image = $this->straighten($image, $path);

        if ($crop === null) {
            $image = $this->fitShortEdge($image, self::FACE_EDGE);
            $image = $this->squareByGuess($image);
        } else {
            $image = $this->squareByChoice($image, $crop);
            $image = $this->fit($image, self::FACE_EDGE);
        }

        return $this->squeeze($image, self::FACE_BYTES);
    }

    /**
     * ⭐ লোগো বা সিল — ছোট করা, কিন্তু **স্বচ্ছতা অক্ষত রেখে**।
     *
     * ── ⛔ কেন লোগো `paper()`-এ পাঠানো যায় না ─────────────────────────
     * তিনটা কারণেই ওটা লোগো নষ্ট করত:
     *
     * ১. `paper()` সবসময় **JPEG** দেয়, আর JPEG-এ স্বচ্ছতা নেই — স্বচ্ছ
     *    পটভূমির লোগো সাদা চৌকো ঘরে বন্দী হয়ে যেত। ⚠️ চালানের রঙিন
     *    মাথার উপর সেটা স্পষ্ট দেখা যেত।
     * ২. `clarify()` কনট্রাস্ট বাড়ায় — ব্র্যান্ডের রঙ বদলে যেত।
     * ৩. JPEG ধারালো কিনারায় ঘোলা দাগ ফেলে; লোগো মানেই ধারালো কিনারা।
     *
     * ⭐ তাই এখানে কেবল **একটা** কাজ: ছোট করা। যা PNG ছিল তা PNG-ই থাকে।
     *
     * ⓘ কেন ছোট করাটা জরুরি: লোগো প্রতিটা ছাপা কাগজে base64 হয়ে বসে
     * ([[App\Models\Company::logoData()]]), আর base64 ফাইলকে ~৩৩% বড়
     * করে। ⚠️ একটা ২ MB লোগো মানে **প্রতিটা PDF-এ ২.৭ MB** — একশোটা
     * চালান ছাপলে খরচটা একশো গুণ।
     *
     * @return array{bytes: string, extension: string, mime: string}
     */
    public function mark(string $path): array
    {
        $info = @getimagesize($path);
        $sourceMime = $info === false ? '' : (string) ($info['mime'] ?? '');

        $image = $this->open($path);
        $image = $this->straighten($image, $path);

        /*
         * ⚠️ এই দুই লাইন স্কেল করার **আগে** — নাহলে GD ছোট করার সময়
         * স্বচ্ছ বিন্দুগুলোকে মিশিয়ে দিত আর কিনারায় কালো আভা পড়ত।
         */
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $image = $this->fit($image, self::MARK_EDGE);

        // PNG ও WebP স্বচ্ছতা বহন করতে পারে, তাই ওদের PNG-ই রাখা হয়।
        if ($sourceMime === 'image/png' || $sourceMime === 'image/webp') {
            imagealphablending($image, false);
            imagesavealpha($image, true);

            ob_start();
            imagepng($image, null, 9);

            return [
                'bytes' => (string) ob_get_clean(),
                'extension' => 'png',
                'mime' => 'image/png',
            ];
        }

        return [
            'bytes' => $this->squeeze($image, self::MARK_BYTES),
            'extension' => 'jpg',
            'mime' => 'image/jpeg',
        ];
    }

    /* ── ধাপগুলো ───────────────────────────────────────────────────── */

    private function open(string $path): GdImage
    {
        $info = @getimagesize($path);

        if ($info === false || ! in_array($info['mime'] ?? '', self::READS, true)) {
            throw new RuntimeException('image.not_an_image');
        }

        /*
         * ⚠️ পাহারাটা এখানেও, `reads()`-এ থাকা সত্ত্বেও — কারণ সবাই আগে
         * `reads()` ডাকে না। ⓘ [[AvatarService]] সরাসরি `face()` ডাকে,
         * আর তখন এই লাইনটাই একমাত্র বাধা।
         */
        if (! $this->fitsInMemory((int) $info[0], (int) $info[1])) {
            throw new RuntimeException('image.too_large_to_process');
        }

        $image = match ($info['mime']) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
        };

        if (! $image instanceof GdImage) {
            throw new RuntimeException('image.not_an_image');
        }

        return $image;
    }

    /**
     * EXIF অনুযায়ী সোজা করা।
     *
     * ⓘ ছাঁচটা [[AvatarService]]-এ আগে থেকেই ছিল আর কাজ করত; এখানে
     * সেটাই আনা হলো যাতে **সংযুক্তিও** একই সুবিধা পায়।
     */
    private function straighten(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);

        $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if ($rotated instanceof GdImage) {
            return $rotated;
        }

        return $image;
    }

    /**
     * চারপাশের এক রঙা কিনারা ফেলে দেওয়া।
     *
     * ── ⚠️ এটা কী **নয়** ────────────────────────────────────────────
     * এটা কাগজের কোণ খোঁজা নয়। ⓘ টেবিলের উপর বাঁকা করে রাখা বিলের
     * ছবিতে কিনারাগুলো এক রঙা নয়, তাই এখানে কিছুই ছাঁটা হবে না — আর
     * সেটাই সঠিক আচরণ: **অনিশ্চিত হলে কিছু না কাটা**।
     *
     * ⭐ কাজে লাগে যেখানে সত্যিই এক রঙা প্রান্ত আছে: স্ক্যান করা পাতা,
     * পর্দার ছবি, সাদা কাগজে তোলা রসিদ।
     *
     * ⚠️ সহনশীলতা আছে, কারণ ক্যামেরার শব্দে "সাদা" কখনো ঠিক একই সাদা
     * থাকে না — কঠোরভাবে মিলাতে গেলে কোনোদিন কিছুই ছাঁটা হত না।
     */
    private function trimPlainEdges(GdImage $image): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);

        $corner = imagecolorat($image, 0, 0);
        $tolerance = 18;

        $top = $this->scanEdge($image, $w, $h, $corner, $tolerance, 'top');
        $bottom = $this->scanEdge($image, $w, $h, $corner, $tolerance, 'bottom');
        $left = $this->scanEdge($image, $w, $h, $corner, $tolerance, 'left');
        $right = $this->scanEdge($image, $w, $h, $corner, $tolerance, 'right');

        $width = $w - $left - $right;
        $height = $h - $top - $bottom;

        /*
         * ⛔ পুরোটা এক রঙা হলে (ফাঁকা ছবি) সব ছাঁটা পড়ে যেত আর শূন্য
         * মাপের ছবি তৈরি হত — GD তাতে ভেঙে পড়ে। ⓘ খুব বেশি ছাঁটা
         * পড়লেও থামা হয়: অর্ধেকের বেশি কেটে ফেলা মানে সম্ভবত ছবিটাই
         * ভুল বোঝা হয়েছে।
         */
        if ($width < $w / 2 || $height < $h / 2) {
            return $image;
        }

        $cropped = imagecrop($image, ['x' => $left, 'y' => $top, 'width' => $width, 'height' => $height]);

        if (! $cropped instanceof GdImage) {
            return $image;
        }

        return $cropped;
    }

    /** এক পাশ থেকে কত সারি/কলাম এক রঙা — গুনে দেখা। */
    private function scanEdge(GdImage $image, int $w, int $h, int $corner, int $tolerance, string $side): int
    {
        $limit = in_array($side, ['top', 'bottom'], true) ? $h : $w;
        $across = in_array($side, ['top', 'bottom'], true) ? $w : $h;

        /*
         * ⓘ প্রতিটা বিন্দু নয়, প্রতি দশমটা — একটা ২০০০px কিনারায়
         * ২০০টা নমুনা যথেষ্ট, আর খরচ দশ ভাগের এক।
         */
        $step = max(1, (int) ($across / 200));

        for ($i = 0; $i < $limit; $i++) {
            $at = match ($side) {
                'top' => $i,
                'bottom' => $limit - 1 - $i,
                'left' => $i,
                default => $limit - 1 - $i,
            };

            for ($j = 0; $j < $across; $j += $step) {
                $colour = in_array($side, ['top', 'bottom'], true)
                    ? imagecolorat($image, $j, $at)
                    : imagecolorat($image, $at, $j);

                if ($this->differs($colour, $corner, $tolerance)) {
                    return $i;
                }
            }
        }

        return 0;
    }

    /** দুইটা রঙ কি চোখে আলাদা? */
    private function differs(int $a, int $b, int $tolerance): bool
    {
        return abs((($a >> 16) & 0xFF) - (($b >> 16) & 0xFF)) > $tolerance
            || abs((($a >> 8) & 0xFF) - (($b >> 8) & 0xFF)) > $tolerance
            || abs(($a & 0xFF) - ($b & 0xFF)) > $tolerance;
    }

    /** লম্বা বাহু সীমার মধ্যে নামানো — ছোট ছবি বড় করা হয় না। */
    private function fit(GdImage $image, int $edge): GdImage
    {
        return $this->scaleBy($image, $edge / max(imagesx($image), imagesy($image)));
    }

    /**
     * ছোট বাহুটা সীমায় নামানো।
     *
     * ⭐ বর্গাকারে কাটার আগে এটাই লাগে: ছোট বাহু ৫১২ হলে যে বর্গটা কাটা
     * হবে তার বাহুও ৫১২। ⚠️ লম্বা বাহু ধরে নামালে ছোট বাহু ৫১২-এর নিচে
     * চলে যেত, আর প্রোফাইল ছবি ঘোষিত মাপের চেয়ে ছোট হত — নীরবে।
     */
    private function fitShortEdge(GdImage $image, int $edge): GdImage
    {
        return $this->scaleBy($image, $edge / min(imagesx($image), imagesy($image)));
    }

    private function scaleBy(GdImage $image, float $scale): GdImage
    {
        // ⛔ বড় করা হয় না: ছোট ছবি টেনে বড় করলে বিন্দু বাড়ে, তথ্য বাড়ে না।
        if ($scale >= 1.0) {
            return $image;
        }

        $scaled = imagescale(
            $image,
            max(1, (int) round(imagesx($image) * $scale)),
            max(1, (int) round(imagesy($image) * $scale)),
            IMG_BILINEAR_FIXED,
        );

        return $scaled instanceof GdImage ? $scaled : $image;
    }

    /**
     * কাগজ সাদা, লেখা কালো।
     *
     * ⓘ সত্যিকারের স্ক্যানারের মতো থ্রেশহোল্ড করা হয় না — ওটা রঙিন
     * সিল, লাল কালির সই বা ছবি মুছে দিত। ⚠️ বিলে ঐ জিনিসগুলোই প্রায়ই
     * সবচেয়ে দরকারি।
     *
     * ⭐ তাই কেবল কনট্রাস্ট ও উজ্জ্বলতা — ছায়া হালকা হয়, লেখা স্পষ্ট
     * হয়, আর রঙ বেঁচে থাকে।
     */
    private function clarify(GdImage $image): GdImage
    {
        @imagefilter($image, IMG_FILTER_CONTRAST, -18);
        @imagefilter($image, IMG_FILTER_BRIGHTNESS, 8);

        return $image;
    }

    /**
     * ⭐ বাইটের বাজেটে নামিয়ে আনা — মান ধাপে ধাপে কমিয়ে।
     *
     * ── কেন লুপ, একটা স্থির মান নয় ───────────────────────────────────
     * ⓘ একই মানে (৮৫) একটা সাদা রসিদ ৮০KB হয়, আর একটা রঙিন প্যাকেটের
     * ছবি ১.৫MB। ⚠️ স্থির মান দিলে হয় ছোট ছবিগুলো অকারণে নষ্ট হত, নয়
     * বড়গুলো বাজেট ছাড়াত।
     *
     * ⭐ তাই বাজেটটাই নিয়ম, আর মানটা তার ফল।
     *
     * ⚠️ সর্বনিম্ন ৪০-এ থামা হয়: তার নিচে JPEG-এর ব্লক চোখে পড়ে আর
     * ছাপা লেখা ভাঙতে শুরু করে। ⓘ ঐ মানেও বাজেট না মিললে যা পাওয়া গেল
     * তাই রাখা হয় — **একটা বড় ছবি রাখা, ছবিটা হারানোর চেয়ে ভালো**।
     */
    private function squeeze(GdImage $image, int $budget): string
    {
        $image = $this->flatten($image);

        $bytes = '';

        foreach ([85, 75, 65, 55, 45, 40] as $quality) {
            ob_start();
            imagejpeg($image, null, $quality);
            $bytes = (string) ob_get_clean();

            if (strlen($bytes) <= $budget) {
                break;
            }
        }

        return $bytes;
    }

    /**
     * ⛔ স্বচ্ছ PNG-র ফাঁকা অংশ **কালো** হয়ে যেত।
     *
     * ⓘ JPEG-এ স্বচ্ছতা নেই। কিছু না করলে GD ঐ বিন্দুগুলোকে কালো ধরে —
     * অর্থাৎ একটা স্বচ্ছ পটভূমির লোগো আপলোড করলে ফলাফল হত **কালো চৌকো
     * ঘরে বন্দী লোগো**।
     *
     * ⚠️ কথাটা [[AvatarService::squareThumbnail]]-এ আগে থেকেই লেখা ছিল আর
     * সেখানে সামলানোও হত। ⭐ ইঞ্জিনে তোলার সময় প্রথমে এটা বাদ পড়েছিল —
     * পুরনো কোড পড়ে ধরা পড়েছে, ব্যবহারকারীর অভিযোগে নয়।
     */
    private function flatten(GdImage $image): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);

        $canvas = imagecreatetruecolor($w, $h);
        imagefilledrectangle($canvas, 0, 0, $w, $h, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $w, $h);

        return $canvas;
    }

    /**
     * বর্গ করে কাটা — ইঞ্জিনের নিজের আন্দাজে।
     *
     * ⓘ যুক্তিটা [[AvatarService]]-এর, আর মেপে লেখা: লম্বালম্বি ছবিতে
     * ঠিক মাঝখান থেকে কাটলে মুখের বদলে বুক আসে, কারণ মানুষের ছবিতে মুখ
     * প্রায় সবসময় উপরের দিকে।
     */
    private function squareByGuess(GdImage $image): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $side = min($w, $h);

        $x = (int) (($w - $side) / 2);
        $y = $h > $w ? (int) (($h - $side) * 0.1) : (int) (($h - $side) / 2);

        return $this->cropSquare($image, $x, $y, $side);
    }

    /**
     * বর্গ করে কাটা — ব্যবহারকারী পর্দায় যা বেছেছেন।
     *
     * ⚠️ সংখ্যাগুলো ব্রাউজার থেকে আসে, তাই বিশ্বাস করা হয় না: ছবির
     * সীমার বাইরে গেলে ভিতরে টেনে আনা হয়। ⓘ নাহলে একটা বানানো অনুরোধে
     * `imagecrop()` শূন্য বা ঋণাত্মক মাপ পেয়ে ভেঙে পড়ত।
     *
     * @param  array{x: int, y: int, size: int}  $crop
     */
    private function squareByChoice(GdImage $image, array $crop): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);

        $side = max(16, min($crop['size'], $w, $h));
        $x = max(0, min($crop['x'], $w - $side));
        $y = max(0, min($crop['y'], $h - $side));

        return $this->cropSquare($image, $x, $y, $side);
    }

    private function cropSquare(GdImage $image, int $x, int $y, int $side): GdImage
    {
        $cropped = imagecrop($image, ['x' => $x, 'y' => $y, 'width' => $side, 'height' => $side]);

        if (! $cropped instanceof GdImage) {
            return $image;
        }

        return $cropped;
    }
}
