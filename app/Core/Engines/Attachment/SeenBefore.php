<?php

declare(strict_types=1);

namespace App\Core\Engines\Attachment;

use App\Core\Engines\Drill\DrillResolver;
use App\Models\Attachment;
use Illuminate\Support\Facades\Route;

/**
 * ⭐ "একই স্লিপ দুইবার?" — এই স্লিপ আগে অন্য কোনো কাগজে এসেছে কি না, আর সেই কাগজের লিংক (মালিক, ১৬ নভেম্বর ২০২৬: স্লিপ
 *   হোয়াটসঅ্যাপে কয়েক হাত ঘুরে আসে; শেষ কথা ব্যাংক স্টেটমেন্ট)। ২০ নভেম্বর ২০২৬।
 *
 * ⓘ খোঁজে [[AttachmentEngine::lookalikes()]] — হুবহু একই ফাইল (sha256) বা একই চেহারার ছবি (dHash), একই কোম্পানির, এক বছরের।
 *   এখানে কেবল বাছাই: **অন্য** কাগজের (একই কাগজে একই স্লিপের নতুন সংস্করণ নয়), সবচেয়ে পুরনোটা — আর মানুষের পড়ার নাম ও লিংক।
 * ⛔ কেবল সতর্কতা, কখনো আটকানো নয় (মালিক, ১৮ নভেম্বর ২০২৬: "warnings never block work") — কাগজটা আগেই জমা হয়ে গেছে, এটা
 *   পরে ডাকা হয়।
 * ⓘ লিংক কেবল দেখার নাগালে থাকলে ([[DrillResolver::resolve()]] নাগাল ধরে খোঁজে); না থাকলে নাম ছাড়াই "আরেকটা কাগজ" — অন্য শাখার
 *   কাগজের নম্বর ফাঁস হয় না।
 */
final class SeenBefore
{
    public function __construct(
        private readonly AttachmentEngine $attachments,
        private readonly DrillResolver $drill,
    ) {}

    /**
     * @return array{attachment: Attachment, paper: string, date: string, url: ?string, message: string}|null
     */
    public function of(Attachment $slip): ?array
    {
        $earlier = $this->attachments->lookalikes($slip)->first(
            fn (Attachment $other) => ! ($other->source_entity === $slip->source_entity
                && (int) $other->source_entity_id === (int) $slip->source_entity_id),
        );

        if ($earlier === null) {
            return null;
        }

        [$paper, $url] = $this->paperOf($earlier);
        $date = $earlier->created_at?->format('d-m-Y') ?? '';

        return [
            'attachment' => $earlier,
            'paper' => $paper,
            'date' => $date,
            'url' => $url,
            'message' => __('core.attachment.slip_seen_before', ['paper' => $paper, 'date' => $date]),
        ];
    }

    /** @return array{0: string, 1: ?string} কাগজের নাম আর লিংক */
    private function paperOf(Attachment $earlier): array
    {
        $entity = (string) $earlier->source_entity;
        $id = (int) $earlier->source_entity_id;

        // ⓘ টাকা জমার খবরের স্লিপ — পুরো শ্রেণির নামে বাঁধা ([[DepositSlip]]); তার নিজের পাতা নেই, তালিকা খোলে ঐ একটা সারিতে
        if (str_ends_with($entity, '\\DepositClaim')) {
            return [
                __('core.attachment.paper_claim', ['no' => $id]),
                Route::has('sales.claim.index') ? route('sales.claim.index', ['status' => 'all', 'claim' => $id]) : null,
            ];
        }

        try {
            if ($this->drill->knows($entity)) {
                $seen = $this->drill->describe($entity, $id);

                if ($seen['resolved'] && is_array($seen['route']) && Route::has((string) ($seen['route'][0] ?? ''))) {
                    return [(string) $seen['document_no'], route($seen['route'][0], $seen['route'][1] ?? [])];
                }
            }
        } catch (\Throwable) {
            // ⓘ লিংক বানানো না গেলে কেবল নাম — সতর্কতাটা হারায় না
        }

        return [__('core.attachment.paper_other'), null];
    }
}
