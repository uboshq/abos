<?php

declare(strict_types=1);

namespace App\Core\Concerns;

use App\Core\Services\PaperParties;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ বিজ্ঞপ্তিতে কাগজের পক্ষ — গ্রাহক হলে "নাম · পয়েন্ট", না হলে সরবরাহকারী/কর্মী/ব্যক্তির নাম (মালিক, ১৯ অক্টোবর ২০২৬:
 * *"নম্বরের পাশে গ্রাহকের নাম আর পয়েন্টের নাম দিলে সহজে বোঝা যায়"*)।
 *
 * ⓘ অনুমোদনের বিজ্ঞপ্তি কোরের ([[ApprovalEngine::noticeLabel()]], [[noticeData()]]) — ঘণ্টি, বিজ্ঞপ্তি কেন্দ্র আর ফোন, তিন
 * জায়গাতেই এটা পড়ে। আগে কেবল বিক্রির কাগজ নিজের গ্রাহক বলত ([[NamesItsCustomerInNotices]]); আদায়, ভাউচার, চেক, ক্রয় আর
 * জনবলের কাগজের বিজ্ঞপ্তিতে কেবল ধরন আর নম্বর যেত।
 * ⓘ কোর মডিউল চেনে না: পক্ষ পড়া হয় কাগজের নিজের ঘর থেকে ([[PaperParties::keyOf()]] — `party_type`/`party_id`, অথবা
 * `customer_id`/`supplier_id`/`employee_id`/`person_id`)। পক্ষ না থাকলে null — বিজ্ঞপ্তি তখন আগের মতো।
 *
 * @mixin Model
 */
trait NamesItsPartyInNotices
{
    public function noticeParty(): ?string
    {
        return app(PaperParties::class)->labelOf($this);
    }
}
