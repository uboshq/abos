<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Attachment\AttachmentException;
use App\Models\Attachment;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\DepositClaim;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * জমার অনুরোধের সাথে ব্যাংক স্লিপ — মালিক, ১ অক্টোবর ২০২৬।
 *
 * ── ⭐ মালিকের কথা ───────────────────────────────────────────────────
 * *"customer payment dile bank slip soho ekta request paTanor bebosta app e thakbe. web eo thakbe"* —
 * দোকানি (পোর্টাল) বা SR (অ্যাপ/ওয়েব) টাকা জমার ছবি তুলে পাঠান; হিসাবরক্ষক স্লিপ দেখে গ্রহণ বা বাতিল করেন
 * ([[DepositClaimService::accept()]])। অনুরোধ নিজে টাকা নয় — গ্রহণের আগে খাতায় কিছু ওঠে না।
 *
 * ⓘ নতুন ঘর নেই: স্লিপ সাধারণ সংযুক্তি ([[AttachmentEngine]], কেবল ছবি/PDF, ৫ MB), দাবির নামে বাঁধা।
 * ⛔ দাবি আর স্লিপ একসাথে — স্লিপ ফিরলে দাবিও ওঠে না; নাহলে স্লিপহীন দাবি তালিকায় বসে থাকত।
 */
final class DepositSlip
{
    public const MODULE = 'sales';

    public function __construct(
        private readonly DepositClaimService $claims,
        private readonly AttachmentEngine $attachments,
    ) {}

    /**
     * দাবি তোলা, স্লিপসহ — এক লেনদেনে।
     *
     * @param  array<string, mixed>  $data
     */
    public function raise(Customer $customer, array $data, ?UploadedFile $slip): DepositClaim
    {
        return DB::transaction(function () use ($customer, $data, $slip) {
            $claim = $this->claims->raise($customer, $data);

            if ($slip !== null) {
                try {
                    $this->attachments->store(
                        file: $slip,
                        module: self::MODULE,
                        entity: DepositClaim::class,
                        entityId: (int) $claim->id,
                        maxBytes: AttachmentEngine::SLIP_MAX_BYTES,
                        only: AttachmentEngine::SLIP,
                    );
                } catch (AttachmentException $refused) {
                    throw ValidationException::withMessages(['slip' => $refused->getMessage()]);
                }
            }

            return $claim;
        });
    }

    /** দাবির সর্বশেষ স্লিপ — না থাকলে null */
    public function of(DepositClaim $claim): ?Attachment
    {
        return $this->attachments->listFor(self::MODULE, DepositClaim::class, (int) $claim->id)->first();
    }

    /** স্লিপটা খোলা — চাবি দেখে ডাকে কন্ট্রোলার; এখানে কেবল ফাইল */
    public function stream(DepositClaim $claim): StreamedResponse
    {
        $slip = $this->of($claim);
        abort_if($slip === null || ! $this->attachments->exists($slip), 404);

        return response()->streamDownload(
            fn () => print ($this->attachments->contents($slip)),
            $slip->original_name,
            ['Content-Type' => $slip->mime_type ?: 'application/octet-stream'],
            'inline',
        );
    }
}
