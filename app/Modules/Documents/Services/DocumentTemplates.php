<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Core\Support\CompanyContext;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentTemplate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * ছাঁচ থেকে কাগজ — ঘর ভরা, সহজ লেখার নিয়মে সাজানো, আর ABOS-এর ছাপার যন্ত্রে PDF (§২; সপ্তম ধাপ)।
 *
 * ── ⓘ লেখার নিয়ম (সম্পাদকে) ─────────────────────────────────────────────
 * `# শিরোনাম`, `## ছোট শিরোনাম`, `**মোটা**`, ফাঁকা লাইনে নতুন অনুচ্ছেদ, `---` দাগ, আর `{{ নাম }}` ঘর।
 * ⛔ ছাঁচের লেখা আর ঘরের মান দুইটাই আগে নিরাপদ করা (`e()`) — তারপর কেবল আমাদের নিজের চিহ্নগুলো HTML হয়।
 * ⓘ বাংলা যুক্তাক্ষর ঠিক রাখে ছাপার যন্ত্রের হিন্দ শিলিগুড়ি ফন্ট ([[PrintEngine]], `useOTL`)।
 *
 * ⓘ নিজে থেকে ভরা ঘর: `company_name`, `branch_name`, `today`, `user_name`, `document_no` (নতুন কাগজের নম্বর
 * বসার পরে নয় — তাই ওটা কাগজের নিজের নম্বর নয়, ছাঁচের নিজের ঘর হলে মানুষ ভরেন)।
 */
final class DocumentTemplates
{
    /** @var list<string> */
    public const BUILT_IN = ['company_name', 'branch_name', 'today', 'user_name'];

    public function __construct(
        private readonly PrintEngine $print,
        private readonly DocumentLibrary $library,
    ) {}

    /**
     * ছাঁচের ঘরগুলো — নিজে থেকে ভরা বাদে, যে ক্রমে লেখায় এসেছে।
     *
     * @return list<string>
     */
    public function variables(string $body): array
    {
        preg_match_all('/\{\{\s*([a-z][a-z0-9_]{0,39})\s*\}\}/u', $body, $m);

        return array_values(array_diff(array_unique($m[1]), self::BUILT_IN));
    }

    /**
     * ছাঁচ → HTML — নিরাপদ করা লেখা, ঘর ভরা, আমাদের চিহ্নগুলো সাজানো।
     *
     * @param  array<string, string>  $values
     */
    public function html(string $body, array $values, ?User $user = null, ?int $branchId = null): string
    {
        $values = [...$this->builtIns($user, $branchId), ...$values];
        $html = e(str_replace("\r\n", "\n", $body));

        $html = (string) preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]{0,39})\s*\}\}/u',
            fn ($m) => e((string) ($values[$m[1]] ?? '')), $html);

        $blocks = [];

        foreach (preg_split('/\n{2,}/u', trim($html)) ?: [] as $block) {
            $block = trim($block);

            $blocks[] = match (true) {
                $block === '---' => '<hr>',
                str_starts_with($block, '## ') => '<h3>'.substr($block, 3).'</h3>',
                str_starts_with($block, '# ') => '<h2>'.substr($block, 2).'</h2>',
                default => '<p>'.nl2br($block, false).'</p>',
            };
        }

        return (string) preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', implode("\n", $blocks));
    }

    /**
     * ভরা ছাঁচ থেকে নতুন কাগজ — PDF, প্রথম ভার্সন, ছাঁচের ধরন আর ফোল্ডারে।
     *
     * @param  array<string, string>  $values
     * @param  array<string, mixed>  $details  name, branch_id, confidentiality, owner_id, document_date, expiry_date
     */
    public function generate(DocumentTemplate $template, array $values, array $details, User $user): Document
    {
        $branchId = isset($details['branch_id']) ? (int) $details['branch_id'] : null;
        $title = filled($details['name'] ?? null) ? (string) $details['name'] : $template->title;

        $bytes = $this->print->render('documents::templates.print', [
            'title' => $title,
            'html' => $this->html((string) $template->body, $values, $user, $branchId),
        ], PaperSize::A4);

        $path = tempnam(sys_get_temp_dir(), 'dms-tpl-');
        file_put_contents($path, $bytes);
        $file = preg_replace('/[^\pL\pN\-_ ]+/u', '', $title) ?: 'document';

        try {
            $made = $this->library->upload([new UploadedFile($path, mb_substr($file, 0, 80).'.pdf', 'application/pdf', null, true)], [
                ...$details,
                'name' => $title,
                'doc_type' => $template->doc_type,
                'folder' => $template->folder,
                'comment' => __('documents::message.from_template', ['template' => $template->title]),
            ]);
        } finally {
            @unlink($path);
        }

        $made[0]->auditAction('document_from_template', $template->code);

        return $made[0];
    }

    /** @return array<string, string> */
    private function builtIns(?User $user, ?int $branchId): array
    {
        $company = Company::query()->find(CompanyContext::id());

        return [
            'company_name' => (string) ($company?->name() ?? ''),
            'branch_name' => (string) ($branchId ? Branch::query()->find($branchId)?->name() : ''),
            'today' => Carbon::today()->toDateString(),
            'user_name' => (string) ($user?->name ?? ''),
        ];
    }
}
