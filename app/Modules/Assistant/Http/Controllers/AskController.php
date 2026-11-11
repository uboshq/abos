<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Controllers;

use App\Core\Engines\Assistant\AssistantEngine;
use App\Core\Engines\Assistant\ModelClient;
use App\Core\Engines\Assistant\ToolRegistry;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\AssistantEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ⭐ কোপাইলটকে প্রশ্ন — বাংলা, বাংলিশ বা ইংরেজিতে (পূর্ণ পরিকল্পনা অংশ ২, ধাপ ৪)।
 *
 * ⓘ মডেল (NEXUS-এর সার্ভার) বসানো থাকলে সে বোঝে; না থাকলে নিজের শব্দ-তালিকা — সাধারণ প্রশ্ন ("আজ কত বিক্রি", "বাকি কার বেশি",
 *   "চিনি কত আছে", "চিনি কত লাগবে") তখনও চলে। সংখ্যা সবসময় ABOS-এর দরজা থেকে, উৎসের লিংকসহ ([[AssistantEngine::ask()]])।
 */
class AskController extends Controller
{
    public function __construct(
        private readonly AssistantEngine $engine,
        private readonly MenuBuilder $menu,
    ) {}

    public function show(Request $request, ToolRegistry $tools, ModelClient $model): View
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['q' => ['nullable', 'string', 'max:500'], 'from' => ['nullable', 'string', 'max:191']]);
        $question = trim((string) ($data['q'] ?? ''));
        $answer = $question === '' ? null : $this->engine->ask($user, $question, $data['from'] ?? null);
        $event = $answer === null ? null : AssistantEvent::query()->where('user_id', $user->id)->latest('id')->first();

        return view('assistant::ask', [
            'menu' => $this->menu->forUser($user),
            'name' => $this->engine->name(),
            'question' => $question,
            'answer' => $answer,
            'event' => $event,
            'examples' => collect($tools->for($user))->keys()->map(fn (string $t): string => (string) __('assistant::ask.example.'.str_replace('.', '_', $t)))->filter()->values()->all(),
            'modelOn' => $model->configured(),
        ]);
    }

    /** ⭐ 👍 👎 — নিজের প্রশ্নের উত্তরে */
    public function feedback(Request $request, AssistantEvent $event): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $value = (int) $request->validate(['value' => ['required', 'integer', 'in:1,-1']])['value'];
        $this->engine->feedback($user, $event, $value);

        return back()->with('saved', __('assistant::ask.thanks'));
    }
}
