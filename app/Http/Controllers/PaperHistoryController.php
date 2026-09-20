<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Models\DocumentDelivery;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * এই কাগজটা কে কখন বের করেছে — গোনার পিছনের তালিকা।
 *
 * ── ⭐ কেন সংখ্যাটা ক্লিকযোগ্য হতেই হবে ──────────────────────────────
 * মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬: *"সব জায়গায় হাইপার লিংক দেওয়ার কথা"*।
 * ⓘ আর এই সংখ্যাটার ক্ষেত্রে কারণটা আরও সোজা: তিনি গোনাটা চেয়েছিলেন
 * **কারণ** প্রশ্নটা "কে পাঠাল, কখন" — "চারবার" নিজে থেকে সেই উত্তর দেয় না।
 *
 * ⚠️ অনুমতি ছাপার রুটের সাথে মেলানো হয়নি, ইচ্ছাকৃতভাবে: এখানে কাগজের
 * **বিষয়বস্তু** নেই, কেবল কে-কখন-কোন মাপে। ⓘ তবু পাতাটা লগইনের ভিতরে,
 * আর সারিগুলো নিজের কোম্পানির বাইরে যায় না (কোম্পানির ছাঁকনি মডেলেই)।
 */
class PaperHistoryController extends Controller
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public function show(Request $request): View
    {
        $type = (string) $request->query('type', '');
        $id = (int) $request->query('id', 0);

        abort_if($type === '' || $id <= 0, 404);

        $rows = DocumentDelivery::query()
            ->where('document_type', $type)
            ->where('document_id', $id)
            ->with('user')
            ->orderByDesc('id')
            ->paginate(100)
            ->withQueryString();

        return view('paper.history', [
            'menu' => $this->menu->forUser($request->user()),
            'type' => $type,
            'id' => $id,
            'documentNo' => $rows->first()?->document_no,
            'rows' => $rows,
        ]);
    }
}
