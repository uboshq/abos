<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Services;

use App\Core\Contracts\ProvisionsCompany;
use App\Modules\MasterData\Models\SalesChannel;
use Illuminate\Support\Facades\DB;

/**
 * বিক্রয়ের পথের প্রমিত দশটা সারি — NEXUS §২৮।
 *
 * ── ⚠️ কেন [[MasterListService::installDefaults()]]-এর ভিতরে নয় ────────
 * ওখানকার `seed()` থামে তালিকায় **একটাও** সারি থাকলে — তাই পরে যোগ করা
 * সারি চলমান কোম্পানিতে কোনোদিন পৌঁছায় না (কারণ কোডে ঠিক এটাই হয়েছিল,
 * [[MasterListService::installMissingReasons()]] দেখুন)। ⓘ এখানে একটাই
 * পদ্ধতি দুই কাজ করে: নতুন কোম্পানিতে প্রথম দিনের তালিকা, আর চলমান
 * কোম্পানিতে (`abos:sync-sales-channels`) কেবল যা নেই।
 *
 * ⚠️ কোম্পানি যে সারি মুছেছেন সেটা ফেরে না (`withTrashed`), যার নাম বদলেছেন
 * সেটা ছোঁয়া হয় না, আর ডিফল্ট কেবল তখনই বসে যখন তালিকাটা এই ডাকেই প্রথম
 * তৈরি হলো — ⛔ নাহলে তাঁর বেছে নেওয়া ডিফল্ট উল্টে যেত।
 *
 * ⓘ ABOS অনেক ব্যবসায় বিক্রি হয় — এই দশটা কেবল **শুরুর** তালিকা; প্রতিটা
 * কোম্পানি নাম বদলাতে, বন্ধ করতে বা নতুন যোগ করতে পারে।
 */
final class SalesChannelDefaults implements ProvisionsCompany
{
    /**
     * কোড একবার বসলে বদলায় না (রিপোর্ট ও অফারের পরিধি কোড ধরে চেনে);
     * নাম যেকোনো দিন বদলানো যায়।
     *
     * ⓘ পরিবেশক ডিফল্ট — পক্ষের ধরনের একই কারণে: এটা পরিবেশক ডিপো, দিনের
     * প্রায় প্রতিটা ক্রেতাই পরিবেশক-পথের।
     *
     * @var list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    public const ROWS = [
        ['DISTRIB', 'Distributor', 'পরিবেশক', true],
        ['DEALER', 'Dealer', 'ডিলার', false],
        ['RETAIL', 'Retail', 'খুচরা', false],
        ['WHOLE', 'Wholesale', 'পাইকারি', false],
        ['CORP', 'Corporate', 'প্রাতিষ্ঠানিক', false],
        ['ECOM', 'E-commerce', 'ই-কমার্স', false],
        ['ONLINE', 'Online', 'অনলাইন', false],
        ['DIRECT', 'Direct', 'সরাসরি', false],
        ['COUNTER', 'Counter', 'কাউন্টার', false],
        ['OTHER', 'Other', 'অন্যান্য', false],
    ];

    public function __construct(private readonly MasterListService $lists) {}

    /** নতুন কোম্পানি — বারবার ডাকা নিরাপদ, যা আছে তা আবার বসে না। */
    public function provisionCompany(): void
    {
        $this->installMissing();
    }

    /**
     * যা নেই কেবল তা বসায়।
     *
     * @return int কয়টা নতুন সারি বসল
     */
    public function installMissing(): int
    {
        return DB::transaction(function (): int {
            $have = SalesChannel::withTrashed()->pluck('code')->all();
            $wasEmpty = $have === [];
            $added = 0;
            $default = null;

            foreach (self::ROWS as [$code, $en, $bn, $isDefault]) {
                if (in_array($code, $have, true)) {
                    continue;
                }

                $row = $this->lists->create(SalesChannel::class, [
                    'code' => $code,
                    'name_en' => $en,
                    'name_bn' => $bn,
                ], 'sales-channels');

                if ($isDefault) {
                    $default = $row;
                }

                $added++;
            }

            if ($wasEmpty && $default !== null) {
                $default->makeDefault();
            }

            return $added;
        });
    }
}
