<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Core\Engines\Attachment\AttachmentException;
use App\Core\Engines\Audit\AuditEngine;
use App\Models\Attachment;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\ProfileRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * ⭐ গ্রাহকের অনুরোধ — পোর্টাল থেকে তোলা, অফিসে দেখা (গ্রাহক পোর্টাল, ধাপ ১, ১১ অক্টোবর ২০২৬)।
 *
 * ── মালিকের নিয়ম ─────────────────────────────────────────────────────────
 * গ্রাহক নিজের মূল রেকর্ড নিজে বদলান না। তথ্য বদল অফিসে অনুরোধ হয়ে যায়; অফিস মিলিয়ে নিজের পর্দায় (গ্রাহকের
 * সম্পাদনা) বসায়। ⛔ তাই এখানে কোনো পদ্ধতি `customers` সারি লেখে না — [[decide()]]-ও না।
 *
 * ⓘ কাগজ (ট্রেড লাইসেন্স / NID / BIN) সংযুক্তির খাতায় ([[AttachmentEngine]]) — জমার স্লিপের সেই একই পাহারা: কেবল
 * ছবি বা PDF, ৫ MB পর্যন্ত, ফাইলের নাম কখনো পথ হয় না।
 */
final class ProfileRequestService
{
    public const MODULE = 'customer';

    public function __construct(
        private readonly AttachmentEngine $attachments,
        private readonly AuditEngine $audit,
    ) {}

    /**
     * তথ্য বদলের অনুরোধ — কেবল যে ঘরগুলো সত্যিই বদলাতে চাওয়া হলো।
     *
     * ⓘ সাথে তখনকার মান (`was`) — অফিস পাশাপাশি দেখে কী ছিল আর কী চাওয়া হলো; পরে রেকর্ড বদলে গেলেও অনুরোধটা
     * নিজের গল্প বলে।
     *
     * @param  array<string, mixed>  $asked
     */
    public function askContact(Customer $customer, array $asked, ?string $note): ProfileRequest
    {
        $changes = [];
        $was = [];

        foreach (ProfileRequest::CONTACT_FIELDS as $field) {
            if (! array_key_exists($field, $asked)) {
                continue;
            }

            $new = trim((string) ($asked[$field] ?? ''));
            $old = trim((string) ($customer->getAttribute($field) ?? ''));

            if ($new !== $old) {
                $changes[$field] = $new;
                $was[$field] = $old;
            }
        }

        if ($changes === []) {
            throw ValidationException::withMessages(['contact' => __('customer::profile_request.nothing_changed')]);
        }

        return ProfileRequest::query()->create([
            'company_id' => $customer->company_id,
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'kind' => ProfileRequest::CONTACT,
            'asked' => $changes,
            'was' => $was,
            'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 500),
            'status' => ProfileRequest::SUBMITTED,
        ]);
    }

    /** একটা কাগজ — অনুরোধের সারি আর ফাইল একসাথে, একটা ফিরলে কোনোটাই বসে না */
    public function uploadPaper(Customer $customer, string $kind, UploadedFile $file, ?string $note): ProfileRequest
    {
        if (! in_array($kind, ProfileRequest::PAPER_KINDS, true)) {
            throw ValidationException::withMessages(['paper_kind' => __('customer::profile_request.unknown_paper')]);
        }

        return DB::transaction(function () use ($customer, $kind, $file, $note) {
            $request = ProfileRequest::query()->create([
                'company_id' => $customer->company_id,
                'branch_id' => $customer->branch_id,
                'customer_id' => $customer->id,
                'kind' => ProfileRequest::PAPER,
                'paper_kind' => $kind,
                'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 500),
                'status' => ProfileRequest::SUBMITTED,
            ]);

            try {
                $this->attachments->store(
                    file: $file,
                    module: self::MODULE,
                    entity: ProfileRequest::class,
                    entityId: (int) $request->id,
                    maxBytes: AttachmentEngine::SLIP_MAX_BYTES,
                    only: AttachmentEngine::SLIP,
                );
            } catch (AttachmentException $refused) {
                throw ValidationException::withMessages(['paper' => $refused->getMessage()]);
            }

            return $request;
        });
    }

    /**
     * অফিসের সিদ্ধান্ত — "হয়েছে" (রেকর্ডে বসানো হলো / কাগজ মিলল) বা "হলো না" (কারণসহ)।
     *
     * ⛔ এখানে গ্রাহকের রেকর্ড বদলায় না: অফিস বদলটা গ্রাহকের সম্পাদনার পর্দায় বসায়, যেখানে তার নিজের যাচাই আর
     * নিরীক্ষা আছে। ⓘ সিদ্ধান্তটা গ্রাহকের রেকর্ডের নিরীক্ষার খাতায় ওঠে — কে, কখন।
     */
    public function decide(ProfileRequest $request, User $by, string $status, ?string $note): ProfileRequest
    {
        if (! in_array($status, [ProfileRequest::DONE, ProfileRequest::REFUSED], true)) {
            throw ValidationException::withMessages(['status' => __('customer::profile_request.unknown_status')]);
        }

        $note = $note === null ? '' : trim($note);

        if ($status === ProfileRequest::REFUSED && $note === '') {
            throw ValidationException::withMessages(['decision_note' => __('customer::profile_request.reason_required')]);
        }

        return DB::transaction(function () use ($request, $by, $status, $note) {
            $locked = ProfileRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['status' => __('customer::profile_request.already_decided')]);
            }

            $locked->forceFill([
                'status' => $status,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'decision_note' => $note === '' ? null : mb_substr($note, 0, 500),
            ])->save();

            // ⓘ সম্পর্কটা হাতে তোলা — এই রিপোতে lazy loading বন্ধ
            $locked->loadMissing('customer');

            if ($locked->customer !== null) {
                $this->audit->recordAction(
                    $locked->customer,
                    $status === ProfileRequest::DONE ? 'profile_request_done' : 'profile_request_refused',
                    $note === '' ? null : $note,
                );
            }

            return $locked;
        });
    }

    public function paperOf(ProfileRequest $request): ?Attachment
    {
        return $request->kind === ProfileRequest::PAPER
            ? $this->attachments->listFor(self::MODULE, ProfileRequest::class, (int) $request->id)->first()
            : null;
    }

    /** কাগজের ফাইল — ছবি/PDF ব্রাউজারে খোলে, বাকি সব নামে ([[AttachmentEngine::inlineType()]]) */
    public function stream(ProfileRequest $request): StreamedResponse
    {
        $paper = $this->paperOf($request);
        abort_if($paper === null || ! $this->attachments->exists($paper), 404);

        $inline = AttachmentEngine::inlineType($paper->mime_type);

        return response()->streamDownload(
            fn () => print ($this->attachments->contents($paper)),
            $paper->original_name,
            ['Content-Type' => $inline ?? 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
            $inline === null ? 'attachment' : 'inline',
        );
    }
}
