<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Core\Services\ExportJournal;
use App\Core\Services\ListExport;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ?export=csv — পর্দাটা যা দেখাত, ফাইল হয়ে নামে।
 *
 * ── কেন মিডলওয়্যারে ─────────────────────────────────────────────────
 * কলামগুলো জানে কেবল Blade-এর টেবিলটা, আর সেটা জানা যায় ভিউ রেন্ডার
 * হওয়ার পর। কন্ট্রোলারে বসালে প্রতিটা কন্ট্রোলারকে নিজের কলাম দ্বিতীয়বার
 * লিখতে হত — আর দুই তালিকা একদিন আলাদা হয়ে যেত।
 *
 * এখানে উল্টো ক্রমে: রেসপন্সটা আগে তৈরি হতে দেওয়া হয়, getContent()
 * ভিউটাকে রেন্ডার করায়, রেন্ডার হওয়ার সময় x-ui.table নিজের সারিগুলো
 * ListExport-এ জমা দেয়, আর তারপর সেই জমাটা ফাইল হয়ে ফেরে।
 */
class ExportListing
{
    /** ⓘ ৫০ সারির পাতায় দশ হাজার সারি — মাসের সব বিলের চেয়ে ঢের বেশি */
    private const MAX_PAGES = 200;

    public function __construct(
        private readonly ListExport $export,
        private readonly ExportJournal $log,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // আগের অনুরোধের তালিকা যেন এই অনুরোধের ফাইলে না নামে
        $this->export->reset();

        /** @var Response $response */
        $response = $next($request);

        if (! $this->export->wanted()) {
            return $response;
        }

        /*
         * শুধু সফল HTML পাতা।
         *
         * রিডিরেক্ট বা ৪০৩-এর গায়ে ?export=csv লাগিয়ে দিলে সেটা যেন
         * খালি একটা CSV না হয়ে আসল উত্তরটাই দেয় — নাহলে অনুমতি নেই
         * এমন কেউ একটা ফাঁকা ফাইল পেয়ে ভাবত তালিকাটা খালি।
         */
        if ($response->getStatusCode() !== 200 || $response instanceof StreamedResponse) {
            return $response;
        }

        // ভিউটা এখানেই রেন্ডার হয়, আর তাতেই টেবিলটা জমা পড়ে
        $content = $response->getContent();

        $this->gatherEveryPage($request, $next);

        $format = $this->export->format();

        // চাওয়া ফরম্যাট ধরে বিষয়বস্তু — উৎস একটাই ধরা-টেবিল
        $body = match ($format) {
            'xlsx' => $this->export->xlsx(),
            'json' => $this->export->json(),
            default => $this->export->csv(),
        };

        /*
         * পর্দায় কোনো টেবিলই নেই — যেমন একটা ফর্ম বা ড্যাশবোর্ড।
         *
         * তখন পাতাটাই ফেরে। খালি ফাইল নামালে ব্যবহারকারী ভাবত ডেটা নেই,
         * অথচ আসলে ওই পর্দার রপ্তানি করার মতো তালিকাই নেই।
         */
        if ($body === null || $content === false) {
            return $response;
        }

        /*
         * খাতায় বসে ঠিক এখানেই — ফাইলটা সত্যিই বেরোনোর মুহূর্তে।
         *
         * ── কেন কন্ট্রোলারে নয় ──────────────────────────────────────
         * বাইশটা কন্ট্রোলারে একটা করে লেখা মানে তেইশতমটায় কেউ ভুলবে,
         * আর ঠিক ওই পর্দাটা দিয়েই ফাইল বেরোবে বিনা চিহ্নে। এখানে
         * লিখলে ভোলার কোনো উপায় নেই।
         *
         * ── কেন উপরে নয়, এতটা নিচে ──────────────────────────────────
         * উপরে বসালে সেই অনুরোধগুলোও খাতায় উঠত যেগুলোয় আসলে কিছু
         * নামেইনি — অনুমতি নেই, বা পর্দায় কোনো তালিকাই নেই। তখন খাতা
         * পড়ে মনে হত ফাইল গেছে, অথচ যায়নি — আর ভুল চিহ্ন কোনো চিহ্ন
         * না থাকার চেয়ে খারাপ।
         */
        $this->log->wrote($this->export->rowCount());

        return response($body, 200, [
            'Content-Type' => match ($format) {
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'json' => 'application/json; charset=UTF-8',
                default => 'text/csv; charset=UTF-8',
            },
            'Content-Disposition' => 'attachment; filename="'.$this->export->filename().'"',

            /*
             * রপ্তানি কখনো ক্যাশ হবে না — একই ঠিকানা কাল অন্য সংখ্যা
             * দেবে, আর পুরনো ফাইলটা দেখে কেউ ভুল হিসাব করত।
             */
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    /**
     * ⭐ সব পাতা — চলতিটা নয় (৩০ সেপ্টেম্বর ২০২৬, নিরাপত্তা-অডিট ২৯ সেপ্টেম্বরের খোলা খোঁজ)।
     *
     * ⛔ আগে ফাইলে যেত কেবল পর্দার পাতাটা — ৮০০ সারির তালিকায় ৫০টা। paginator নিজের
     * কোয়েরি ধরে রাখে না, তাই বাকি পাতা ওখান থেকে চাওয়া যায় না। ⭐ তাই একই অনুরোধ
     * প্রতিটা পাতার জন্য আবার চলে — একই দরজা, একই চাবি, একই ছাঁকনি — আর টেবিলগুলো
     * পরপর জোড়া লাগে ([[ListExport::capture()]])। একশো কন্ট্রোলারে হাত দিতে হয় না।
     *
     * ⚠️ সীমা [[self::MAX_PAGES]] পাতা — একটা ভুল ছাঁকনিতে গোটা খাতা টেনে সার্ভার আটকে
     * দেওয়া যাবে না। সীমা পেরোলে খাতায় সারির সংখ্যাটাই সত্যি বলে।
     */
    private function gatherEveryPage(Request $request, Closure $next): void
    {
        $pages = min($this->export->lastPage(), self::MAX_PAGES);

        if ($pages <= 1 || $this->export->captured() === null) {
            return;
        }

        $name = $this->export->pageName();
        $asked = $request->query->all()[$name] ?? null;

        $this->export->restartFromTheFirstPage();

        try {
            for ($page = 1; $page <= $pages; $page++) {
                $request->query->set($name, (string) $page);
                $this->export->nextPage();

                /** @var Response $again */
                $again = $next($request);

                if ($again->getStatusCode() !== 200) {
                    break;
                }

                $again->getContent();
            }
        } finally {
            $asked === null ? $request->query->remove($name) : $request->query->set($name, $asked);
        }
    }
}
