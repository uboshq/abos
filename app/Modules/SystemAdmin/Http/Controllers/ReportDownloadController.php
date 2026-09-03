<?php

declare(strict_types=1);

namespace App\Modules\SystemAdmin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ReportRun;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * নির্ধারিত রিপোর্টের ফাইল নামানো — private ডিস্ক থেকে, অনুমতি যাচাই করে।
 *
 * ফাইলটা `storage/app`-এর private অংশে, তাই সরাসরি URL দিয়ে পাওয়া যায় না।
 * কে নামাতে পারবেন তা রেকর্ড দেখে ঠিক হয় (ReportRunPolicy), আর কোম্পানি-
 * বিচ্ছিন্নতা রুট-মডেল বাইন্ডিংয়েই: অন্য কোম্পানির run global scope-এ ছেঁকে
 * বাদ পড়ে, তাই ৪০৪।
 */
final class ReportDownloadController extends Controller
{
    public function download(ReportRun $run): StreamedResponse
    {
        Gate::authorize('download', $run);

        abort_unless(
            $run->hasFile() && Storage::disk('local')->exists((string) $run->file_path),
            404,
        );

        $mime = match ($run->format) {
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'json' => 'application/json',
            'pdf' => 'application/pdf',
            default => 'text/csv',
        };

        return Storage::disk('local')->download(
            (string) $run->file_path,
            'report-'.$run->public_id.'.'.$run->format,
            ['Content-Type' => $mime],
        );
    }
}
