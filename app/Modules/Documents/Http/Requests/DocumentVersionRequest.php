<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Requests;

use App\Modules\Documents\Services\DocumentFiles;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * নতুন ভার্সন — একটা ফাইল, ছোট না বড় বদল, আর মন্তব্য (§৯; ৮ অক্টোবর ২০২৬)।
 *
 * ⓘ ফাইলের দুই ছাঁকনি তোলার ফর্মের মতোই ([[DocumentUploadRequest]])।
 */
class DocumentVersionRequest extends FormRequest
{
    /** ⓘ অনুমতি রুটের `can:addVersion,document`-এ */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(DocumentFiles::maxMb() * 1024), 'extensions:'.DocumentFiles::extensions()],
            'major' => ['nullable', 'boolean'],
            'comment' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $file = $this->file('file');

                if ($file === null || $validator->errors()->has('file')) {
                    return;
                }

                $refusal = DocumentFiles::refusal($file);

                if ($refusal !== null) {
                    $validator->errors()->add('file', __('documents::message.file_'.$refusal, [
                        'name' => $file->getClientOriginalName(),
                        'max' => DocumentFiles::maxMb().' MB',
                    ]));
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'file' => __('documents::field.file'),
            'comment' => __('documents::field.comment'),
        ];
    }
}
