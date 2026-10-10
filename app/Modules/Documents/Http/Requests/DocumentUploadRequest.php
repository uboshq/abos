<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Requests;

use App\Modules\Documents\Services\DocumentFiles;
use Illuminate\Validation\Validator;

/**
 * ডকুমেন্ট তোলা — এক বা অনেক ফাইল, সাথে বিবরণ (§৬; ৮ অক্টোবর ২০২৬)।
 *
 * ── ⛔ দুই ছাঁকনি ─────────────────────────────────────────────────────
 * ১. নামের লেজ আর মাপ — ফর্মের নিয়মে (`extensions:`, `max:`), যাতে ভুলটা ঘরের পাশে আসে।
 * ২. ফাইলের **বাইট** — [[DocumentFiles::refusal()]], জমার আগেই প্রতিটা ফাইল। ⚠️ নামের লেজ
 *    কেবল দাবি; `চুক্তি.pdf` নামের HTML প্রথম ছাঁকনি পার হয়, দ্বিতীয়টা নয়।
 */
class DocumentUploadRequest extends DocumentDetailsRequest
{
    protected function nameIsRequired(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'files' => ['required', 'array', 'min:1', 'max:'.DocumentFiles::MAX_FILES],
            'files.*' => ['required', 'file', 'max:'.(DocumentFiles::maxMb() * 1024), 'extensions:'.DocumentFiles::extensions()],
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                foreach ((array) $this->file('files', []) as $i => $file) {
                    if ($validator->errors()->has('files.'.$i)) {
                        continue;
                    }

                    $refusal = DocumentFiles::refusal($file);

                    if ($refusal !== null) {
                        $validator->errors()->add('files.'.$i, __('documents::message.file_'.$refusal, [
                            'name' => $file->getClientOriginalName(),
                            'max' => DocumentFiles::maxMb().' MB',
                        ]));
                    }
                }
            },
        ];
    }
}
