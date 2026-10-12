<?php

declare(strict_types=1);

namespace App\Modules\Sales\Listeners;

use App\Core\Engines\Approval\SignedPaperFinisher;
use App\Core\Events\ApprovalDecided;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Services\CollectionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * ⭐ টাকা আদায়ের শেষ সই পড়লে আদায় নিজেই পাকা — মালিক, ১১ অক্টোবর ২০২৬ (ক): সইয়ের পরে কাগজ খুঁজে পোস্ট করতে সময় যায়।
 *
 * ⭐ হাতের "নিশ্চিত"-এর একই দরজা ([[CollectionService::confirm()]]) — দ্বিতীয় কোনো পথ নয়।
 * ⛔ আটকালে খসড়াই থাকে, লেখক আর সইকারী খবর পান ([[SignedPaperFinisher]])।
 */
final class ConfirmTheCollectionOnTheLastSignature
{
    public function __construct(
        private readonly SignedPaperFinisher $finisher,
        private readonly CollectionService $collections,
    ) {}

    public function handle(ApprovalDecided $event): void
    {
        if (($event->payload['status'] ?? null) !== Approval::APPROVED) {
            return;
        }

        $type = (string) ($event->payload['approvable_type'] ?? '');

        if ((Relation::getMorphedModel($type) ?? $type) !== Collection::class) {
            return;
        }

        $collection = Collection::query()->withoutGlobalScope('user-branch')->find((int) ($event->payload['approvable_id'] ?? 0));

        // ⓘ খসড়া থাকলেই — একই সই দুইবার এলে বা কেউ হাতে আগেই পাকা করলে কিছুই নয়
        if ($collection === null || $collection->status !== DocumentStatus::DRAFT) {
            return;
        }

        // ⛔ কাউন্টারের এক বিক্রির দলের কাগজ — দলটা নিজের শেষকারী সব সই পড়লে একসাথে শেষ করে ([[SignedPaperFinisher::bundled()]])
        if ($this->finisher->bundled((int) ($event->payload['approval_id'] ?? 0))) {
            return;
        }

        $signer = $event->actorId === null ? null : User::query()->find($event->actorId);

        $this->finisher->finish(
            $collection,
            $signer,
            route('sales.collection.show', $collection),
            fn (Model $paper) => $this->collections->confirm(Collection::query()->withoutGlobalScope('user-branch')->findOrFail($paper->getKey())),
        );
    }
}
