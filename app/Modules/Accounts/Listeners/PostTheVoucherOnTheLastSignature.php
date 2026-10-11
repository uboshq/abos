<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Listeners;

use App\Core\Engines\Approval\SignedPaperFinisher;
use App\Core\Events\ApprovalDecided;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * ⭐ ভাউচারের শেষ সই পড়লে ভাউচার নিজেই পোস্ট — মালিক, ১১ অক্টোবর ২০২৬ (ক): *"Approval er pore Vautcher … post korte hoy"*।
 *
 * ⓘ লাইভে ADI-র ২৮টা রসিদ আর জার্নালের প্রতিটা সইয়ের পরে কেউ হাতে "পোস্ট" চেপেছেন (RCV-0052: সই ১৩:৩৫, পোস্ট ১৩:৪১)।
 * ⭐ পোস্ট হয় হাতের পোস্টের একই দরজায় ([[VoucherWriter::post()]]: তালা, আবার পড়া, সই যাচাই, তারপর খাতা) — দ্বিতীয় কোনো পথ নয়।
 * ⛔ আটকালে খসড়াই থাকে, লেখক আর সইকারী খবর পান ([[SignedPaperFinisher]])।
 */
final class PostTheVoucherOnTheLastSignature
{
    public function __construct(
        private readonly SignedPaperFinisher $finisher,
        private readonly VoucherWriter $writer,
    ) {}

    public function handle(ApprovalDecided $event): void
    {
        if (($event->payload['status'] ?? null) !== Approval::APPROVED) {
            return;
        }

        $type = (string) ($event->payload['approvable_type'] ?? '');

        if ((Relation::getMorphedModel($type) ?? $type) !== Voucher::class) {
            return;
        }

        // ⓘ সইদাতার শাখার দেয়াল ছাড়া — হেডারে অন্য শাখা বাছা মালিক শেষ সই দিলেও ভাউচারটা পাওয়া যায় (কোম্পানির দেয়াল থাকে)
        $voucher = Voucher::query()->withoutGlobalScope('user-branch')->find((int) ($event->payload['approvable_id'] ?? 0));

        // ⓘ খসড়া থাকলেই — একই সই দুইবার এলে বা কেউ হাতে আগেই পোস্ট করলে কিছুই নয়
        if ($voucher === null || ! $voucher->isDraft()) {
            return;
        }

        // ⛔ কাউন্টারের এক বিক্রির দলের কাগজ — দলটা নিজের শেষকারী সব সই পড়লে একসাথে শেষ করে ([[SignedPaperFinisher::bundled()]])
        if ($this->finisher->bundled((int) ($event->payload['approval_id'] ?? 0))) {
            return;
        }

        $signer = $event->actorId === null ? null : User::query()->find($event->actorId);

        $this->finisher->finish(
            $voucher,
            $signer,
            route('accounts.voucher.show', $voucher),
            fn (Model $paper) => $this->writer->post(Voucher::query()->withoutGlobalScope('user-branch')->findOrFail($paper->getKey())),
        );
    }
}
