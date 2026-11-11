<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Controllers;

use App\Core\Engines\Assistant\AssistantEngine;
use App\Core\Engines\Assistant\ModelClient;
use App\Core\Engines\Assistant\ToolRegistry;
use App\Http\Controllers\Controller;
use App\Models\AssistantDismissal;
use App\Models\AssistantEvent;
use App\Models\User;
use App\Modules\Assistant\Watch\Brief;
use App\Modules\Assistant\Watch\Suggestions;
use App\Modules\Assistant\Watch\Watch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * ⭐ ফোনের কোপাইলট — ওয়েবের একই ইঞ্জিন, একই সংখ্যা, একই চাবি (মালিক, ১১ নভেম্বর ২০২৬: "কোপাইলটটা মোবাইল অ্যাপে একই বিল্ডে দিয়ে দাও")।
 *
 * ⓘ আজ: নাম, সকালের সারাংশ, নজর, প্রস্তাব — [[Brief]], [[Watch]], [[Suggestions]], ওয়েবের পাতার একই উৎস।
 * ⓘ প্রশ্ন: [[AssistantEngine::ask()]] — অ্যাপ থেকে, তাই খাতায় "অ্যাপ"; উত্তরের লিংক ওয়েবের ঠিকানা (অ্যাপ নিজের ভেতরের ব্রাউজারে খোলে)।
 * ⛔ এখানেও কোপাইলট কিছু লেখে না, পাঠায় না — খসড়া ওয়েবের পাতায়, মানুষের চাপে।
 */
final class CopilotApiController extends Controller
{
    public function __construct(private readonly AssistantEngine $engine) {}

    public function today(Request $request, Brief $brief, Watch $watch, Suggestions $suggestions, ToolRegistry $tools, ModelClient $model): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'name' => $this->engine->name(),
            'brief' => $this->engine->on('brief') ? $brief->for($user) : null,
            'watch' => $this->engine->on('watch') ? $watch->for($user) : null,
            'suggestions' => $this->engine->on('suggest') ? array_map(fn (array $s): array => array_diff_key($s, ['id' => 0]) + ['id' => $s['id']], $suggestions->for($user)) : null,
            'ask' => $this->engine->on('ask'),
            'model' => $model->configured(),
            'examples' => collect($tools->for($user))->keys()->map(fn (string $t): string => (string) __('assistant::ask.example.'.str_replace('.', '_', $t)))->values()->all(),
        ])->header('Cache-Control', 'no-store');
    }

    public function ask(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        // ⓘ প্রশ্ন আসে অনুরোধের ভেতরে (POST) — ঠিকানায় নয়; অ্যারে বা অন্য কিছু এলে খালি
        $q = $request->input('q');
        $question = mb_substr(trim(is_scalar($q) ? (string) $q : ''), 0, 500);

        if ($question === '') {
            return response()->json(['message' => __('assistant::ask.placeholder')], 422);
        }

        $page = $request->input('page');
        $answer = $this->engine->ask($user, $question, is_string($page) && $page !== '' ? mb_substr($page, 0, 191) : null);
        $event = AssistantEvent::query()->where('user_id', $user->id)->latest('id')->first();

        return response()->json([
            'state' => $answer['state'],
            'message' => $answer['state'] === 'answered' ? null : (string) __('assistant::ask.state.'.$answer['state']),
            'answer' => $answer['result'],
            'understood_by' => $answer['model'] === 'words' ? (string) __('assistant::ask.words') : $answer['model'],
            'answer_id' => $event !== null && $event->kind === AssistantEvent::QUESTION ? (int) $event->id : null,
            'check' => (string) __('assistant::ask.check', ['name' => $this->engine->name()]),
        ])->header('Cache-Control', 'no-store');
    }

    public function feedback(Request $request, AssistantEvent $event): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $value = (int) $request->validate(['value' => ['required', 'integer', 'in:1,-1']])['value'];
        $this->engine->feedback($user, $event, $value);

        return response()->json(['ok' => true]);
    }

    /** ⭐ "এটা ঠিক আছে" — ওয়েবের একই নিয়ম: নজরটা এই মানুষের চোখে পড়ার মতো হতে হয়, কারণ বাধ্যতামূলক */
    public function dismiss(Request $request, Watch $watch): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'finding' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $seen = collect($watch->for($user))->firstWhere('id', $data['finding']);
        abort_if($seen === null, 404);

        AssistantDismissal::query()->create([
            'finding' => $data['finding'], 'reason' => trim($data['reason']),
            'until' => Carbon::today()->addDays(AssistantDismissal::DAYS - 1)->toDateString(), 'user_id' => $user->id,
        ]);
        $this->engine->record(AssistantEvent::DISMISS, $user, (string) $seen['title'], [
            'module' => (string) $seen['module'], 'reason' => mb_substr(trim($data['reason']), 0, 255), 'outcome' => $data['finding'],
        ]);

        return response()->json(['ok' => true, 'message' => __('assistant::page.dismissed')]);
    }
}
