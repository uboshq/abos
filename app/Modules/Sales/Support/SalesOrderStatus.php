<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Core\Support\DocumentStatus;

/**
 * বিক্রয় আদেশের অবস্থা — মাথায় (`sal_orders.status`), আর পাশে চালান ও বিলের অগ্রগতি, মাথায় আর প্রতি লাইনে।
 *
 * ── ⭐ মালিক, ৪ অক্টোবর ২০২৬: আন্তর্জাতিক মান; DO বিক্রয় আদেশে মেশে (নকশা "DO বিক্রয় আদেশে মেশানো" §১.১, §১.২) ──
 *
 *   draft → submitted → awaiting_approval → approved → credit_held | confirmed → closed
 *   পাশে: rejected (সুপারভাইজার ফেরালেন), cancelled (কারণসহ)।
 *   অগ্রগতি: delivery_status আর billing_status — none | partial | full; লাইনের নিজের line_status — open | closed | rejected।
 *
 * ⭐ `draft`, `confirmed`, `closed`, `cancelled` — [[DocumentStatus]]-এর **একই মান**, ইচ্ছা করে: আজকের সব কোড
 * (`scopeOpen()`, চালানের `resolveOrder()`, [[OrderTracking]], কুপনের কাগজ) এই মানেই খোলা আদেশ চেনে, তাই কোনো পুরনো সারি
 * বদলাতে হয় না।
 *
 * ⓘ ডিপো যাচাই অবস্থা নয়, চিহ্ন (`depot_check_at`); ব্যাক অর্ডারও অবস্থা নয়, গোনা ([[OrderProgress]])।
 * ⛔ কে কোনটা লেখে: অবস্থা — আদেশের সেবা (abos-bb) আর বাকির যাচাই (abos-86, `approved → credit_held | confirmed`);
 * অগ্রগতি — কেবল [[OrderProgress::refresh()]]।
 */
final class SalesOrderStatus
{
    public const DRAFT = DocumentStatus::DRAFT;

    public const SUBMITTED = 'submitted';

    public const AWAITING_APPROVAL = 'awaiting_approval';

    public const APPROVED = 'approved';

    public const CREDIT_HELD = 'credit_held';

    public const CONFIRMED = DocumentStatus::CONFIRMED;

    public const CLOSED = DocumentStatus::CLOSED;

    public const REJECTED = 'rejected';

    public const CANCELLED = DocumentStatus::CANCELLED;

    /**
     * ধারার ক্রম — তালিকা আর পাতা এই ক্রমে দেখায়। ⓘ `credit_held` আর `confirmed` একই ধাপের দুই ফল।
     *
     * @var list<string>
     */
    public const FLOW = [...self::OPEN_FLOW, self::CLOSED];

    /**
     * খোলা ধাপগুলো — বন্ধের আগে পর্যন্ত।
     *
     * ⚠️ "কোন কাগজ হিসাবে গোনা হয়" এখানে নয় — সেটা কেবল [[DocumentStatus::POSTED]]-এ
     * ([[OneFigureOneDefinitionTest]]); এই তালিকা কেবল ধারার ক্রম।
     *
     * @var list<string>
     */
    public const OPEN_FLOW = [
        self::DRAFT, self::SUBMITTED, self::AWAITING_APPROVAL, self::APPROVED,
        self::CREDIT_HELD, self::CONFIRMED,
    ];

    /** @var list<string> */
    public const ALL = [...self::FLOW, self::REJECTED, self::CANCELLED];

    /**
     * শেষ — আর কিছু ঘটে না; তালিকার "ইতিহাস"।
     *
     * @var list<string>
     */
    public const FINISHED = [self::CLOSED, self::REJECTED, self::CANCELLED];

    // ── অগ্রগতি (মাথায় আর লাইনে) ────────────────────────────────────────

    public const NONE = 'none';

    public const PARTIAL = 'partial';

    public const FULL = 'full';

    /** @var list<string> */
    public const PROGRESS = [self::NONE, self::PARTIAL, self::FULL];

    // ── লাইনের নিজের অবস্থা ─────────────────────────────────────────────

    public const LINE_OPEN = 'open';

    public const LINE_CLOSED = 'closed';

    public const LINE_REJECTED = 'rejected';

    // ── সংরক্ষণের নিয়ম (`hold_mode`) ─────────────────────────────────────

    /** আজকের নিয়ম — নিশ্চিতে মজুদের খাতায় সরাসরি `reserved`, চালান নিজে ছাড়ে */
    public const HOLD_LEDGER = 'ledger';

    /** নতুন ধারার নিয়ম — ঘড়িসহ হোল্ড (`sal_order_stock_holds`, abos-86), চালানে consume */
    public const HOLD_HOLDS = 'holds';

    // ── কোথা থেকে লেখা (`source`) ───────────────────────────────────────

    public const SOURCE_PORTAL = 'portal';

    public const SOURCE_SR = 'sr';

    public const SOURCE_COUNTER = 'counter';

    /** ⭐ অফিস থেকে লেখা আদেশ — সমন্বয়কের উত্তর, প্রশ্ন ৭ (৪ অক্টোবর ২০২৬) */
    public const SOURCE_OFFICE = 'office';

    /** @var list<string> */
    public const SOURCES = [self::SOURCE_PORTAL, self::SOURCE_SR, self::SOURCE_COUNTER, self::SOURCE_OFFICE];

    public static function label(string $status): string
    {
        return __('sales::order_status.state.'.$status);
    }

    public static function deliveryLabel(string $progress): string
    {
        return __('sales::order_status.delivery.'.$progress);
    }

    public static function billingLabel(string $progress): string
    {
        return __('sales::order_status.billing.'.$progress);
    }

    /**
     * চিপের রং — ব্যাজের যে ছয়টা রং গড়া CSS-এ সত্যিই আছে, তার মধ্যেই (`inventory`, `muted` নেই — মাপা, ৪ অক্টোবর ২০২৬)।
     */
    public static function tone(string $status): string
    {
        return match ($status) {
            self::SUBMITTED, self::APPROVED => 'info',
            self::AWAITING_APPROVAL => 'pending',
            self::CREDIT_HELD, self::REJECTED, self::CANCELLED => 'danger',
            self::CONFIRMED => 'success',
            default => 'draft',
        };
    }

    public static function progressTone(string $progress): string
    {
        return match ($progress) {
            self::PARTIAL => 'warning',
            self::FULL => 'success',
            default => 'draft',
        };
    }
}
