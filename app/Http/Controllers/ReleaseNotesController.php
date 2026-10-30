<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Services\MenuBuilder;
use App\Core\Support\Release;
use App\Core\Support\ReleaseNotes;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ⭐ "নতুন কী এল" — নিচের বারের সংস্করণে চাপলে (মালিক, ২৪ অক্টোবর ২০২৬)। লগইন করা সবার জন্য, কোনো চাবি লাগে না:
 * রেপোর নোট ছাড়া কোনো কোম্পানির তথ্য নেই।
 */
class ReleaseNotesController extends Controller
{
    public function __construct(private readonly MenuBuilder $menu) {}

    public function __invoke(Request $request): View
    {
        return view('release.notes', [
            'menu' => $this->menu->forUser($request->user()),
            'sections' => ReleaseNotes::sections(),
            'current' => Release::label(),
        ]);
    }
}
