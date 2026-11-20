<?php

declare(strict_types=1);

namespace App\Core\Engines\Attachment;

use App\Core\Engines\Image\ImageEngine;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * সংযুক্তি — প্ল্যান সেকশন ২১, অষ্টম engine।
 *
 * DMS-এর polymorphic নকশা, একটা গুরুত্বপূর্ণ পরিবর্তনসহ: ফাইল ডিস্কে বসে,
 * ডাটাবেজে নয়। base64 ফাইলকে ~৩৩% বড় করে, ডাটাবেজ ফুলে যায়, আর প্রতিটা
 * ব্যাকআপে সব ফাইল ঢোকে — শেয়ার্ড cPanel-এ ব্যাকআপ তখনই টাইমআউট করবে।
 */
final class AttachmentEngine
{
    /**
     * যেসব এক্সটেনশন কখনো নয়।
     *
     * অনুমোদিত তালিকা নয়, নিষিদ্ধ তালিকা — কারণ ব্যবসায়িক কাগজ কী কী হতে
     * পারে তার শেষ নেই, কিন্তু কোনটা বিপজ্জনক সেটার তালিকা ছোট ও জানা।
     */
    private const FORBIDDEN = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar',
        'exe', 'com', 'bat', 'cmd', 'sh', 'bash', 'ps1', 'psm1',
        'dll', 'so', 'jar', 'msi', 'scr', 'vbs', 'js', 'jse', 'wsf', 'hta',

        /*
         * ⛔ যে ফাইলগুলো ব্রাউজারে **নিজেরাই কোড চালায়** — ২১ সেপ্টেম্বর ২০২৬।
         *
         * ── ⓘ কেন এগুলো চোখ এড়িয়ে গিয়েছিল ──────────────────────────
         * উপরের তালিকাটা "প্রোগ্রাম" ধরে বানানো — `.exe`, `.sh`, `.php`।
         * ⚠️ কিন্তু একটা `.svg` বা `.html`-ও প্রোগ্রাম: ভিতরে `<script>`
         * বসে, আর ব্রাউজার সেটা **ঐ সাইটেরই অংশ হিসেবে** চালায়, অর্থাৎ
         * লগইন করা ব্যবহারকারীর হয়ে।
         *
         * ── ⚠️ আজ কেন ফাটেনি, আর কাল কেন ফাটত ──────────────────────
         * এখন ফাইল যায় `streamDownload`-এ (`Content-Disposition: attachment`),
         * তাই ব্রাউজার নামায়, চালায় না। ⛔ কিন্তু ইঞ্জিন ব্রাউজারের বলা
         * mime-টাই সংরক্ষণ করে (`:236`) আর কন্ট্রোলার সেটাই হুবহু ফেরত
         * দেয় — তাই কোনোদিন একটা "ভিতরেই দেখুন" পর্দা, থাম্বনেইল বা CDN
         * বসলেই ওটা লগইন করা সেশনে সংরক্ষিত XSS হয়ে যেত।
         *
         * ⭐ তালিকায় থাকলে ফাইলটা ঢোকেই না — পরের জনকে আর ঐ শৃঙ্খলটা
         * মনে রাখতে হয় না।
         */
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xht', 'mhtml', 'xml', 'xsl', 'xslt',
    ];

    /**
     * ⭐ সাধারণ কাগজের দরজার অনুমতি-তালিকা — পূর্ণ নিরীক্ষা §৫ ও §৮ ⚠️৭ (১৮ অক্টোবর ২০২৬; OWASP ASVS 12.2.1)।
     *
     * ⛔ আগে কেবল নিষিদ্ধ-তালিকা ([[FORBIDDEN]]): তার বাইরের যা খুশি ঢুকত — `.zip`, `.rar`, `.iso`, `.env`, অচেনা বাইনারি —
     * আর সইকারী বা হিসাবরক্ষক সেটা নামিয়ে খুলতেন। ⭐ এখন বাইট থেকে পড়া ধরন এই তালিকায় থাকতে হয়, আর নামের লেজ সেই ধরনের
     * হতে হয়। ⓘ তালিকাটা দলিল-ভাণ্ডারের হুবহু (`DocumentFiles::ALLOWED`) — ব্যবসার কাগজের একটাই মান: PDF, ছবি, অফিসের ফাইল,
     * লেখা/CSV। ⓘ নিষিদ্ধ-তালিকা আর কোডের-ধরন যাচাই আগের মতোই আগে চলে, বার্তাও আগের।
     *
     * @var array<string, list<string>> বাইটের ধরন => যে নাম-লেজ চলে
     */
    public const GENERAL = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/vnd.oasis.opendocument.text' => ['odt'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['ods'],
        // ⓘ অফিসের নতুন ফাইল ভিতরে zip — কোনো যন্ত্রে finfo তাই বলে; কেবল ঐ লেজগুলোর জন্য, `.zip` নিজে নয়
        'application/zip' => ['docx', 'xlsx', 'pptx', 'odt', 'ods'],
        'application/msword' => ['doc'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.ms-office' => ['doc', 'xls', 'ppt'],
        'application/cdfv2' => ['doc', 'xls', 'ppt'],
        'application/x-ole-storage' => ['doc', 'xls', 'ppt'],
        'text/plain' => ['txt', 'csv'],
        'text/csv' => ['csv'],
    ];

    private const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

    /**
     * ⛔ প্রক্রিয়া **না হওয়া** একটা ছবি সর্বোচ্চ কত বড় হয়ে ডিস্কে বসতে পারে।
     *
     * ── কেন এই সীমাটা আলাদা, আর কেন এটা না থাকলে বিপদ ────────────────
     * ⓘ ঢোকার দরজার সীমা (`$maxBytes`) এখন ঢিলা — ১২ MB — কারণ যুক্তিটা
     * ছিল *"আমরাই তো ছোট করে নিচ্ছি"*।
     *
     * ⚠️ কিন্তু নিচের `keep()`-এ একটা fallback আছে: ইঞ্জিন ব্যর্থ হলে
     * কাঁচা ফাইলটাই জমা হয়। ⛔ দুইটা একসাথে রাখলে ফল দাঁড়াত — একটা
     * ১২ MB ছবি **সোজা ডিস্কে**, আর কেউ টের পেত না যতক্ষণ না শেয়ার্ড
     * cPanel-এর জায়গা ফুরাত।
     *
     * ⭐ তাই নিয়মটা দুই ভাগ: **ঢুকতে** পারে ১২ MB, কিন্তু **প্রক্রিয়া
     * ছাড়া থাকতে** পারে ২ MB। এর বেশি হলে চুপ করে রাখা হয় না —
     * ব্যবহারকারীকে বলা হয়।
     *
     * ⓘ এই ফাঁকটা abos-68 ধরেছে, ব্যবহারকারী নয় — আর সেটাই কাম্য।
     */
    private const RAW_IMAGE_CEILING = 2 * 1024 * 1024;

    /**
     * ব্যাংক বা বিকাশের স্লিপ — কেবল ছবি বা PDF, ২৮ সেপ্টেম্বর ২০২৬ (মালিকের কাজ)।
     *
     * ⓘ সাধারণ কাগজের দরজা খোলা-তালিকা (যা নিষিদ্ধ নয় তাই চলে — Excel, Word)।
     * ⛔ স্লিপ আলাদা: সইকারী ওটা **খুলে দেখেন**, তাই কেবল যা ব্রাউজার নিরাপদে
     * দেখায়। ⚠️ ধরন ঠিক হয় ফাইলের **বাইট** থেকে (finfo), আর নামের extension-কে
     * সেই ধরনের সাথে মিলতেই হয় — ছবির নামে PDF বা `.php` নামে ছবি, দুইটাই ফেরে।
     *
     * @var array<string, list<string>> আসল mime => যে extension চলে
     */
    public const SLIP = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'application/pdf' => ['pdf'],
    ];

    /** ⓘ ফোনের ছবি সাধারণত ২–৪ MB; ৫ MB স্লিপের জন্য যথেষ্ট, আর সার্ভারে বোঝা নয়। ⓘ এখন কেবল ছবির সীমার ডিফল্ট ([[slipMaxKb()]]) */
    public const SLIP_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * ⭐ স্লিপের ধরন ধরে তোলার সীমা (KB) — কন্ট্রোল প্যানেলে বদলানো যায় (মালিক, ১৬ নভেম্বর ২০২৬: "deposit report flexible")।
     *
     * ⓘ ছবি ঢুকে ছোট হয় ([[budgetFor()]] — জমা হয় ১০০ KB-র কাছাকাছি), তাই ঢোকার সীমা ঢিলা: ৫ MB। ⛔ PDF ছোট করা হয় না — যা আসে
     *   তাই জমা থাকে — তাই তার সীমা আঁটো: ২ MB (ব্যাংকের এক পাতার PDF সাধারণত ১০০–৫০০ KB)।
     * ⛔ ওপরের ছাদ ১০ MB — সাধারণ কাগজের দরজার সমান ([[DEFAULT_MAX_BYTES]]); সেটিংসে ভুল করে বড় সংখ্যা বসালেও এর বেশি নয়।
     */
    public const SLIP_IMAGE_MAX_KB = 5120;

    public const SLIP_PDF_MAX_KB = 2048;

    public const SLIP_HARD_MAX_KB = 10240;

    /** স্লিপের এক ধরনের (`image` বা `pdf`) তোলার সীমা, KB — সেটিংস থেকে, ৫০ KB থেকে ১০ MB-র মধ্যে */
    public static function slipMaxKb(string $kind): int
    {
        $default = $kind === 'pdf' ? self::SLIP_PDF_MAX_KB : self::SLIP_IMAGE_MAX_KB;

        try {
            $kb = (int) app(SettingsService::class)->get('system.slip_max_kb.'.($kind === 'pdf' ? 'pdf' : 'image'), $default);
        } catch (\Throwable) {
            // ⓘ সেটিংস পড়া না গেলে (মডিউল বন্ধ, টেবিল নেই) ডিফল্ট — তোলা থামে না
            $kb = $default;
        }

        return min(self::SLIP_HARD_MAX_KB, max(50, $kb > 0 ? $kb : $default));
    }

    /**
     * ⭐ স্লিপের দরজার বাইরের সীমা — দুই ধরনের বড়টা (বাইট)। ফর্মের `max:` নিয়ম আর ইঞ্জিনের প্রথম পাহারা এটা পড়ে; ধরন ধরে
     *   আঁটো সীমা পরে, বাইট দেখে ([[assertOneOf()]])।
     */
    public static function slipUploadBytes(): int
    {
        return max(self::slipMaxKb('image'), self::slipMaxKb('pdf')) * 1024;
    }

    /** ফর্মের `max:` নিয়মের জন্য — KB */
    public static function slipUploadKb(): int
    {
        return intdiv(self::slipUploadBytes(), 1024);
    }

    public function __construct(
        private readonly string $disk = 'local',
        private readonly ImageEngine $images = new ImageEngine,
    ) {}

    public function store(
        UploadedFile $file,
        string $module,
        string $entity,
        int $entityId,
        ?int $replacesId = null,
        ?int $userId = null,
        ?int $maxBytes = null,
        ?array $only = null,
    ): Attachment {
        // ⓘ `$only` — কেবল এই ধরনগুলো (যেমন [[SLIP]]); বাকি সব পাহারা তবুও চলে
        if ($only !== null) {
            $this->assertOneOf($file, $only, $maxBytes ?? self::DEFAULT_MAX_BYTES);
        }

        $this->assertAllowed($file, $maxBytes ?? self::DEFAULT_MAX_BYTES);

        $companyId = CompanyContext::id();
        $extension = strtolower($file->getClientOriginalExtension());

        // ব্যবহারকারীর দেওয়া নাম কখনো পথ তৈরিতে ব্যবহার হয় না। "../../.env"
        // নামের একটা ফাইল আপলোড করে অ্যাপের বাইরে লেখা যায় — এই একটা
        // সিদ্ধান্তেই সেটা অসম্ভব হয়ে যায়।
        $storedName = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');

        $directory = sprintf(
            'attachments/%d/%s/%s/%s',
            $companyId,
            $module,
            now()->format('Y'),
            now()->format('m'),
        );

        // ⭐ স্লিপ ছোট বাজেটে — সেটিংসের KB (মালিক, ১৬ নভেম্বর ২০২৬); বাকি কাগজ আগের মতো ([[budgetFor()]])
        $kept = $this->keep($file, $directory, $storedName, $extension, $this->budgetFor($only));

        $version = 1;

        if ($replacesId !== null) {
            $previous = Attachment::query()->findOrFail($replacesId);
            $version = $previous->version + 1;
        }

        return Attachment::create([
            'company_id' => $companyId,
            'source_module' => $module,
            'source_entity' => $entity,
            'source_entity_id' => $entityId,
            /*
             * ⭐ নামটা ব্যবহারকারীর দেওয়াটাই থাকে — `IMG_20260914.jpg`।
             *
             * ⓘ ছবি প্রক্রিয়া করা হলেও এখানে নতুন নাম বসানো হয় না, কারণ
             * তালিকায় মানুষ **নিজের ফাইলটা চিনতে** চান। ⚠️ যে জিনিস
             * বদলেছে (ধরন, আকার, বাইট) সেগুলো নিচে সত্যি করে লেখা হয়।
             */
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $kept['path'],
            'mime_type' => $kept['mime'],
            'extension' => $kept['extension'],
            'size_bytes' => $kept['bytes'],
            'checksum' => hash_file('sha256', Storage::disk($this->disk)->path($kept['path'])),
            // ⭐ চেহারার ছাপ — হোয়াটসঅ্যাপে আবার-চাপা একই স্লিপ চেনার জন্য ([[lookalikes()]]); ছবি না হলে null
            'fingerprint' => $this->images->fingerprint(Storage::disk($this->disk)->path($kept['path'])),
            'version' => $version,
            'replaces_id' => $replacesId,
            // ⛔ কেবল কর্মী — পোর্টালে গ্রাহক তুললে null ([[Actor]])
            'uploaded_by' => $userId ?? \App\Core\Support\Actor::userId(),
        ]);
    }

    /**
     * ⭐ স্লিপের KB সেটিংস থেকে — মালিক, ১৬ নভেম্বর ২০২৬ (*"ছবিগুলোকে কেবি সাইজে নিয়ে আসা"*)।
     *
     * ⓘ কেবল স্লিপের দরজা ([[SLIP]]) ছোট বাজেট পায়; সাধারণ কাগজ (বিল, চুক্তি) [[ImageEngine::PAPER_BYTES]]-এই থাকে। সেটিং না
     *   থাকলে (মডিউল বন্ধ) ১০০ KB।
     */
    private function budgetFor(?array $only): ?int
    {
        if ($only !== self::SLIP) {
            return null;
        }

        $kb = (int) app(SettingsService::class)->get('system.image_kb.slip', self::SLIP_KB);

        return max(20, $kb) * 1024;
    }

    /** স্লিপের ডিফল্ট KB — সেটিংসের ডিফল্টও এটাই */
    public const SLIP_KB = 100;

    /** কত বিট পর্যন্ত তফাত হলে "একই ছবি" ধরা — ৬৪-এর ৬ */
    public const LOOKALIKE_BITS = 6;

    /**
     * ⭐ এই ছবির মতো আগের ছবি — একই কোম্পানির, আগের এক বছরের (মালিক, ১৬ নভেম্বর ২০২৬: একই স্লিপ দুবার)।
     *
     * ⚠️ কেবল সতর্কতা, আটকানো নয় — একই ব্যাংকের একই ছাঁচের দুটো আলাদা স্লিপ কখনো কাছাকাছি ছাপ দিতে পারে; শেষ কথা ব্যাংক
     *   স্টেটমেন্ট। ⓘ আগে যেটা এসেছিল (পুরনোটা আগে) সেটাই প্রথমে।
     *
     * @return Collection<int, Attachment>
     */
    public function lookalikes(Attachment $attachment, int $within = self::LOOKALIKE_BITS)
    {
        $mine = (string) $attachment->fingerprint;
        $sum = (string) $attachment->checksum;
        $columns = ['id', 'company_id', 'source_module', 'source_entity', 'source_entity_id', 'original_name', 'checksum', 'fingerprint', 'uploaded_by', 'created_at'];

        /*
         * ⭐ হুবহু একই ফাইল — বাইটের ছাপ (sha256, পরিষ্কার করার **পরের** বাইটের; [[store()]]) মিললে (২০ নভেম্বর ২০২৬)।
         * ⓘ চেহারার ছাপ কেবল ছবির হয় — PDF স্লিপ দুবার এলে আগে কিছুই ধরা পড়ত না; এটা PDF-ও ধরে। ছবির ক্ষেত্রেও একই ফাইল
         *   আবার তোলা হলে পরিষ্কার করা বাইট একই হয় (একই ইঞ্জিন, একই ধাপ), তাই এটা চেহারার আগেই ধরে। সূচি `attachments_company_checksum`।
         */
        $exact = strlen($sum) === 64
            ? Attachment::query()
                ->where('company_id', $attachment->company_id)
                ->whereKeyNot($attachment->getKey())
                ->where('checksum', $sum)
                ->where('created_at', '>=', now()->subYear())
                ->orderBy('id')
                ->limit(50)
                ->get($columns)
            : Attachment::query()->whereRaw('1 = 0')->get();

        if (strlen($mine) !== 16) {
            return $exact;
        }

        $near = Attachment::query()
            ->where('company_id', $attachment->company_id)
            ->whereKeyNot($attachment->getKey())
            ->whereNotNull('fingerprint')
            ->where('created_at', '>=', now()->subYear())
            ->orderBy('id')
            ->limit(20000)
            ->get($columns)
            ->filter(fn (Attachment $other) => ImageEngine::distance($mine, (string) $other->fingerprint) <= $within);

        // ⓘ দুই খোঁজ এক তালিকায়, একবার করে, পুরনোটা আগে
        return $exact->concat($near)->unique('id')->sortBy('id')->values();
    }

    /** @return Collection<int, Attachment> */
    public function listFor(string $module, string $entity, int $entityId)
    {
        return Attachment::query()
            ->for($module, $entity, $entityId)
            ->current()
            ->latest('id')
            ->get();
    }

    /**
     * ফাইলটা পড়া — কন্ট্রোলারের মধ্য দিয়ে, কখনো সরাসরি URL দিয়ে নয়।
     *
     * সরাসরি URL হলে লিংক জানলেই অন্য কোম্পানির কাগজ নামানো যেত, কোনো
     * লগইন ছাড়াই। গ্লোবাল স্কোপ এখানে কাজ করে বলেই অন্য কোম্পানির
     * সংযুক্তি এই পদ্ধতিতে কখনো পাওয়া যাবে না।
     */
    public function contents(Attachment $attachment): string
    {
        return Storage::disk($this->disk)->get($attachment->stored_path);
    }

    public function exists(Attachment $attachment): bool
    {
        return Storage::disk($this->disk)->exists($attachment->stored_path);
    }

    /**
     * সফট ডিলিট — নিয়ম ৫। ডিস্কের ফাইল রয়ে যায়।
     *
     * ফাইলটাও মুছে ফেললে ভুল করে মোছা একটা চুক্তিপত্র আর কখনো ফেরানো যেত না।
     * জায়গা খালি করার কাজ আলাদা, ইচ্ছাকৃত, আর সময় পেরোনোর পরে।
     */
    public function delete(Attachment $attachment): void
    {
        $attachment->delete();
    }

    /**
     * ⛔ আপলোডকারী নিজের ফাইল সরাতে পারেন কি না — ec, Core লাইন-নিরীক্ষা, ১৫ অক্টোবর ২০২৬।
     *
     * ⓘ নিয়ম আগের মতোই: নিজের ভুল ছবি নিজে সরানো যায়, কিন্তু কাগজে সই শুরু হওয়ার **আগে**। ⛔ আগে "সই হয়েছে" মানে ছিল কেবল
     * পুরো অনুমোদন `approved` — তাই দুই স্তরের ছকে প্রথম স্তর সই দেওয়ার পরেও (অনুমোদন তখনো `pending`) আপলোডকারী স্লিপটা
     * সরাতে পারতেন, আর অনুমোদন বন্ধ এমন কোম্পানিতে (কোনো অনুমোদনের সারিই নেই) পোস্ট হওয়া রসিদের স্লিপ যেকোনো দিন সরানো
     * যেত। ⭐ এখন সই শুরু = কোনো স্তরে একটা "হ্যাঁ", অথবা কাগজটা আর খসড়া নয় (পোস্ট বা বাতিল)। আর কাগজটা আপনার দেখার
     * মধ্যে থাকতে হবে — শাখার অধিকার হারানো আপলোডকারী আর সরাতে পারেন না।
     *
     * ⓘ এর বাইরে কেবল কাগজ সম্পাদনার চাবিধারী (তদারকি) — সেটা ডাকার জায়গায় (`update`)।
     */
    public function uploaderMayRemove(Attachment $attachment, Model $document, ?User $user): bool
    {
        if ($user === null || (int) $attachment->uploaded_by !== (int) $user->getKey()) {
            return false;
        }

        if (! Gate::forUser($user)->allows('view', $document)) {
            return false;
        }

        return ! $this->signingStarted($document);
    }

    /** কাগজটায় সই শুরু হয়েছে কি না — পুরো অনুমোদন, কোনো স্তরের "হ্যাঁ", বা খসড়া পেরোনো অবস্থা। */
    private function signingStarted(Model $document): bool
    {
        $approvals = Approval::query()
            ->where('approvable_type', $document::class)
            ->where('approvable_id', $document->getKey());

        if ((clone $approvals)->where('status', Approval::APPROVED)->exists()) {
            return true;
        }

        if (ApprovalDecision::query()
            ->whereIn('approval_id', (clone $approvals)->select('id'))
            ->where('decision', ApprovalDecision::APPROVED)
            ->exists()) {
            return true;
        }

        // ⓘ কেবল যে কাগজ খসড়া → নিশ্চিত → বন্ধ ধারায় চলে; গ্রাহক বা পণ্যের মতো তালিকার "active" অবস্থা এখানে কিছু বলে না
        $status = $document->getAttribute('status');

        return is_string($status) && $status !== DocumentStatus::DRAFT && in_array($status, DocumentStatus::ALL, true);
    }

    /**
     * ⛔ বিলের ছবি যেমন আসত তেমনই জমা হত — ১৪ সেপ্টেম্বর ২০২৬।
     *
     * ── কী ভাঙা ছিল ──────────────────────────────────────────────────
     * এখানে আগে একটাই লাইন ছিল: `$file->storeAs(...)`। ⓘ অর্থাৎ ফোনে তোলা
     * একটা রসিদ **পাশ ফিরে, ছয় মেগাবাইট, ছায়াসহ** ডিস্কে বসত।
     *
     * ⚠️ মালিকের অভিযোগটা ঠিক এটাই ছিল: *"যেকোনো ফটো আপলোডের সময় নিজে
     * থেকে ক্রপ করে নেওয়ার ব্যবস্থা করার কথা ছিল সেটা হয় নাই"*।
     * ⓘ প্রোফাইল ছবিতে ব্যবস্থাটা ছিল ([[AvatarService]]), সংযুক্তিতে
     * কখনোই ছিল না — তাই "হয় নাই" কথাটা আক্ষরিক অর্থেই সত্যি ছিল।
     *
     * ── ⭐ যা ছবি নয়, তাতে হাত পড়ে না ─────────────────────────────────
     * PDF, Excel, Word — যেমন আসে তেমনই যায়। ⛔ একটা চুক্তিপত্রের PDF-কে
     * "উন্নত" করতে যাওয়া মানে সেটা নষ্ট করা।
     *
     * ── ⚠️ ব্যর্থ হলে ফাইলটা হারায় না ────────────────────────────────
     * GD একটা ভাঙা বা অদ্ভুত ছবিতে হোঁচট খেতে পারে। ⓘ তখন কাঁচা ফাইলটাই
     * জমা হয় — **একটা বড় ছবি থাকা, কাগজটা হারানোর চেয়ে ভালো**। ব্যবহারকারী
     * বিলটা তুলেছেন একবার; আমাদের যন্ত্রের অক্ষমতার দায় তাঁর নয়।
     *
     * @return array{path: string, extension: string, mime: string, bytes: int}
     */
    private function keep(UploadedFile $file, string $directory, string $storedName, string $extension, ?int $budget = null): array
    {
        $source = $file->getRealPath();
        $isImage = $source !== false && @getimagesize($source) !== false;

        if ($isImage && $this->images->reads($source)) {
            try {
                $bytes = $this->images->paper($source, $budget);

                /*
                 * ⓘ যা বের হয় সেটা সবসময় JPEG, তাই নামের লেজও তাই — নাহলে
                 * `.png` নামের ভিতরে JPEG থাকত, আর একদিন কেউ নাম দেখে
                 * সিদ্ধান্ত নিয়ে ভুল করত।
                 */
                $name = preg_replace('/\.[^.]+$/', '', $storedName).'.jpg';
                $path = $directory.'/'.$name;

                Storage::disk($this->disk)->put($path, $bytes);

                return [
                    'path' => $path,
                    'extension' => 'jpg',
                    'mime' => 'image/jpeg',
                    'bytes' => strlen($bytes),
                ];
            } catch (\Throwable) {
                // নিচে পড়ে যায় — কাঁচা ফাইলই জমা হবে, সীমার মধ্যে হলে।
            }
        }

        /*
         * ⛔ এখানে পৌঁছানো মানে: এটা ছবি, কিন্তু প্রক্রিয়া হয়নি —
         * হয় GD হোঁচট খেয়েছে, নয় বিন্দুর সংখ্যা মেমরির চেয়ে বেশি
         * ([[ImageEngine::fitsInMemory]])।
         *
         * ⚠️ ছোট হলে চুপচাপ রেখে দেওয়া যায় — ব্যবহারকারীর কাগজ হারানোর
         * চেয়ে ভালো। ⛔ কিন্তু বড় হলে **নীরবে রাখা যায় না**: ডিস্ক ভরার
         * মতো ক্ষতি কেউ টের পায় না যতক্ষণ না দেরি হয়ে যায়।
         */
        if ($isImage && $file->getSize() > self::RAW_IMAGE_CEILING) {
            throw new AttachmentException(sprintf(
                'That photo could not be processed, and at %s it is too large to keep as it is. The limit for an unprocessed photo is %s.',
                $this->human((int) $file->getSize()),
                $this->human(self::RAW_IMAGE_CEILING),
            ));
        }

        /*
         * ⛔ প্রক্রিয়া না হওয়া ছবিতেও ফোনের লুকানো তথ্য থাকে না — ২০ নভেম্বর ২০২৬ (মালিক, ১৬ নভেম্বর: স্লিপের ছবি পরিষ্কার)।
         * ⓘ মূল পথে GD নতুন করে লেখে, তাই EXIF (জিপিএস, ফোনের নাম, সময়) এমনিই বাদ পড়ে। ⚠️ এই পড়ে-যাওয়া পথে আগে ফাইলটা হুবহু
         *   বসত — দোকানের জিপিএস ঠিকানাসহ। ⭐ এখন JPEG/PNG-এর তথ্যের টুকরোগুলো বাইট থেকেই কাটা হয় ([[ImageEngine::withoutMetadata()]]),
         *   ছবির বিন্দু ছোঁয়া হয় না। কাটা না গেলে (অচেনা গঠন) যেমন ছিল তেমনই — কাগজ হারানো চলে না।
         */
        if ($isImage) {
            $raw = (string) file_get_contents((string) $source);
            $clean = ImageEngine::withoutMetadata($raw, (string) $file->getMimeType());
            $path = $directory.'/'.$storedName;
            Storage::disk($this->disk)->put($path, $clean);
        } else {
            $path = $file->storeAs($directory, $storedName, ['disk' => $this->disk]);
        }

        return [
            'path' => $path,
            'extension' => $extension,
            // ⛔ বাইট থেকে ধরা ধরন, ব্রাউজারের দাবি নয় (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️১৩: text/html দাবি করা ফাইল সেই ধরনে জমা হত)
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'bytes' => (int) Storage::disk($this->disk)->size($path),
        ];
    }

    /**
     * ⭐ পাতার ভেতরে খোলা যায় এমন ধরন — ছবি আর PDF; বাকি সব নামানো হিসেবে (পুরো ERP অডিট, ৬ অক্টোবর ২০২৬, ফোন ⚠️১৩: জমা ধরন
     * যা-ই হোক, HTML বা স্ক্রিপ্ট কখনো পাতায় খোলে না — পুরনো সারির ভুল ধরনও এখানেই আটকায়)।
     */
    public const INLINE_SAFE = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];

    /** খোলার ধরন — অনুমতি-তালিকায় থাকলে সেটাই, নইলে null (তখন নামানো, `application/octet-stream`) */
    public static function inlineType(?string $mime): ?string
    {
        $mime = strtolower(trim((string) $mime));

        return in_array($mime, self::INLINE_SAFE, true) ? $mime : null;
    }

    /**
     * অনুমতি-তালিকা — বাইট থেকে ধরন, নামের extension সেই ধরনের হতেই হবে।
     *
     * ⚠️ মাপ আগে, ধরন পরে, আর দুইটাই [[NotASlip]] — যাতে মানুষ বাংলায় জানেন কেন।
     *
     * @param  array<string, list<string>>  $only
     */
    private function assertOneOf(UploadedFile $file, array $only, int $maxBytes): void
    {
        // ⓘ স্লিপের মাপ নিচে ধরন ধরে দেখা হয় (কোন ধরন, কত বড়, সীমা কত — এক বাক্যে); বাকি তালিকার (দলিল-ভাণ্ডার) এক সীমা এখানেই
        if ($only !== self::SLIP && $file->isValid() && $file->getSize() > $maxBytes) {
            throw new NotASlip(NotASlip::TOO_BIG, maxKb: intdiv($maxBytes, 1024));
        }

        $path = $file->getRealPath();
        $sniffed = $path === false ? '' : strtolower((string) (new \finfo(FILEINFO_MIME_TYPE))->file($path));
        $extension = strtolower($file->getClientOriginalExtension());

        if (! isset($only[$sniffed]) || ! in_array($extension, $only[$sniffed], true)) {
            throw new NotASlip(NotASlip::WRONG_KIND);
        }

        /*
         * ⭐ স্লিপের ধরন ধরে সীমা — ছবি আর PDF আলাদা, কন্ট্রোল প্যানেলে বদলানো যায় ([[slipMaxKb()]])। ⚠️ ধরন বাইট থেকে পড়ার
         *   পরে, তাই নাম বদলে (`.jpg` নামে PDF) ছবির ঢিলা সীমা পাওয়া যায় না — নাম আর বাইট না মিললে ওপরেই ফেরে।
         */
        if ($only === self::SLIP) {
            $kind = $sniffed === 'application/pdf' ? 'pdf' : 'image';
            // ⓘ ডাকার জায়গা আরও আঁটো সীমা দিলে সেটাই
            $limitKb = min(self::slipMaxKb($kind), intdiv($maxBytes, 1024));

            if ($file->getSize() > $limitKb * 1024) {
                throw new NotASlip(NotASlip::TOO_BIG, kind: $kind, maxKb: $limitKb, sizeKb: (int) ceil($file->getSize() / 1024));
            }
        }
    }

    private function assertAllowed(UploadedFile $file, int $maxBytes): void
    {
        if (! $file->isValid()) {
            throw new AttachmentException('The upload did not complete.');
        }

        if ($file->getSize() > $maxBytes) {
            throw new AttachmentException(sprintf(
                'File is %s but the limit is %s.',
                $this->human($file->getSize()),
                $this->human($maxBytes),
            ));
        }

        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, self::FORBIDDEN, true)) {
            throw new NotAllowedType("Files of type .{$extension} cannot be attached.");
        }

        // নাম বদলে দেওয়া ফাইল ধরার জন্য বিষয়বস্তু দেখতে হয়। invoice.php-কে
        // invoice.pdf নাম দিলে এক্সটেনশন নিরীহ দেখায়, কিন্তু ভেতরে যা আছে
        // তা বদলায় না।
        //
        // getClientMimeType() এখানে ব্যবহার করা হয় না ইচ্ছাকৃতভাবে: ওটা
        // ব্রাউজারের পাঠানো মান, অর্থাৎ আপলোডকারীর নিয়ন্ত্রণে — নিরাপত্তার
        // সিদ্ধান্ত ওর উপর নেওয়া মানে আক্রমণকারীকে জিজ্ঞেস করা সে বিপজ্জনক
        // কি না। getMimeType() ফাইলটা পড়ে বলে।
        $sniffed = strtolower((string) $file->getMimeType());

        if (str_contains($sniffed, 'php') || str_contains($sniffed, 'x-httpd')
            || str_contains($sniffed, 'executable') || str_contains($sniffed, 'x-dosexec')) {
            throw new NotAllowedType('That file type cannot be attached.');
        }

        $guessed = strtolower((string) $file->guessExtension());

        if ($guessed !== '' && in_array($guessed, self::FORBIDDEN, true)) {
            throw new NotAllowedType('That file type cannot be attached.');
        }

        // ⭐ অনুমতি-তালিকা — বাইটের ধরন তালিকায়, আর নামের লেজ সেই ধরনের ([[GENERAL]])
        if (! isset(self::GENERAL[$sniffed]) || ! in_array($extension, self::GENERAL[$sniffed], true)) {
            throw new NotAllowedType(sprintf(
                'Files of type .%s cannot be attached. Allowed: %s.',
                $extension === '' ? '?' : $extension,
                implode(', ', array_values(array_unique(array_merge(...array_values(self::GENERAL))))),
            ));
        }
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1048576
            ? round($bytes / 1048576, 1).' MB'
            : round($bytes / 1024).' KB';
    }
}
