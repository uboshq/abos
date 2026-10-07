<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Engines\Attachment\AttachmentEngine;
use App\Models\Attachment;
use App\Modules\Hr\Models\Employee;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * কর্মীর ছবি — মালিক, ২ অক্টোবর ২০২৬: "employee prf pic upload ki kore dibe"।
 *
 * ⓘ পণ্যের ছবির ছাঁচ ([[ProductImageService]]): ফাইলটা [[AttachmentEngine]]-এর খাতায় — কোম্পানির ফোল্ডারে,
 * uuid নামে; ইঞ্জিন নিজেই ছবিটা ছোট করে JPEG বানায় ([[ImageEngine::paper()]])। কর্মীর সারিতে কেবল সংযুক্তির id।
 * ⓘ নতুন ছবি আগেরটার পরের সংস্করণ (`replaces_id`) — আগেরটা মোছে না, কবে কোন ছবি ছিল খাতায় থাকে।
 * ⛔ দেখা কেবল `attachment.download` দিয়ে — ওটা কর্মীর `view` নীতি (hr.employee.view + শাখার নাগাল) জিজ্ঞেস করে।
 */
final class EmployeePhotoService
{
    public const MODULE = 'hr';

    /** ⓘ DrillResolver-এর নাম — `hr` মডিউলের `drill_sources`-এ এটাই কর্মী; নামটা না মিললে দেখার দরজা কর্মীকে খুঁজে পেত না */
    public const ENTITY = 'employee';

    public const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

    public const MAX_BYTES = 12 * 1024 * 1024;

    public function __construct(private readonly AttachmentEngine $attachments) {}

    public function replace(Employee $employee, UploadedFile $file, ?int $userId = null): Attachment
    {
        return DB::transaction(function () use ($employee, $file, $userId): Attachment {
            $attachment = $this->attachments->store(
                file: $file,
                module: self::MODULE,
                entity: self::ENTITY,
                entityId: (int) $employee->getKey(),
                replacesId: $employee->photo_attachment_id,
                userId: $userId,
                maxBytes: self::MAX_BYTES,
            );

            $employee->forceFill(['photo_attachment_id' => $attachment->id])->save();

            return $attachment;
        });
    }

    /** ⚠️ নাম আর ব্রাউজারের বলা mime নয় — ফাইলের ভেতরটা পড়ে ([[UploadedFile::getMimeType()]]) */
    public function looksLikeAnImage(UploadedFile $file): bool
    {
        return in_array($file->getMimeType(), self::ALLOWED, true);
    }
}
