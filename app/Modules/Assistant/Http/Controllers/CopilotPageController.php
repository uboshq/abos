<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Controllers;

use App\Core\Engines\Assistant\AssistantEngine;
use App\Core\Services\MenuBuilder;
use App\Http\Controllers\Controller;
use App\Models\AssistantConversation;
use App\Models\AssistantEvent;
use App\Models\User;
use App\Modules\Assistant\Support\CopilotAnswer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * ⭐ The full-screen Copilot page (owner's web sample copilot-web-sample, 21 Nov 2026):
 *   ক — start: the big live Ai mark, "শুভ দুপুর, <name> — কী জানতে চান?", the ask box, chips, key hints;
 *   খ — a talk: history on the left (আজ / গতকাল / আগে), answers with text, table, small chart, sources and actions.
 *
 * ⓘ The same engine, the same tools, the same keys and the same audit log as the quick ask and the phone
 *   ([[AssistantEngine::ask()]]); this controller only keeps the talk ([[AssistantConversation]]) and arranges the answer
 *   ([[CopilotAnswer]]). ⛔ It never reads a tool itself.
 * ⛔ Talks are per person and per company: another person's talk — or mine from another company — is a 404.
 */
final class CopilotPageController extends Controller
{
    public function __construct(
        private readonly AssistantEngine $engine,
        private readonly CopilotAnswer $answers,
        private readonly MenuBuilder $menu,
    ) {}

    public function show(Request $request, ?string $conversation = null): View
    {
        /** @var User $user */
        $user = $request->user();
        $talk = $conversation === null ? null : $this->mine($user, $conversation);

        return view('assistant::chat', [
            'menu' => $this->menu->forUser($user),
            'name' => $this->engine->name(),
            'greeting' => $this->answers->greeting($user),
            'chips' => $this->answers->chips($user),
            'history' => $this->history($user, $talk),
            'talk' => $talk === null ? null : [
                'id' => (string) $talk->public_id,
                'title' => (string) $talk->title,
                'url' => route('assistant.chat.show', ['conversation' => $talk->public_id]),
                'messages' => $this->answers->stillTheirs($user, (array) $talk->messages),
            ],
            'askOn' => $this->engine->on('ask'),
        ]);
    }

    /**
     * ⭐ One question on the page → the engine's answer, kept in the talk (a new talk on the first question).
     * ⓘ `regenerate` = "আবার লিখুন": the last answer to the same question is replaced, not added twice.
     */
    public function ask(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $q = $request->input('q');
        $question = mb_substr(trim(is_scalar($q) ? (string) $q : ''), 0, 500);

        if ($question === '') {
            return response()->json(['message' => __('assistant::ask.placeholder')], 422);
        }

        $id = $request->input('conversation');
        $talk = is_string($id) && $id !== '' ? $this->mine($user, $id) : null;

        $answer = $this->engine->ask($user, $question, 'assistant.chat');
        $event = AssistantEvent::query()->where('user_id', $user->id)->latest('id')->first();
        $answerId = $event !== null && $event->kind === AssistantEvent::QUESTION && $answer['state'] === 'answered' ? (int) $event->id : null;
        $line = $this->answers->present($answer, $answerId);

        $messages = $talk === null ? [] : (array) $talk->messages;

        if ($talk !== null && $request->boolean('regenerate')) {
            $messages = self::withoutLastAnswerTo($messages, $question);
        }

        $messages[] = ['role' => 'me', 'at' => Carbon::now()->toIso8601String(), 'text' => $question];
        $messages[] = $line;
        $messages = array_slice($messages, -AssistantConversation::MAX_MESSAGES);

        if ($talk === null) {
            $talk = AssistantConversation::query()->create([
                'user_id' => $user->id,
                'title' => CopilotAnswer::titleOf($question),
                'messages' => $messages,
                'last_at' => Carbon::now(),
            ]);
        } else {
            $talk->forceFill(['messages' => $messages, 'last_at' => Carbon::now()])->save();
        }

        return response()->json([
            'conversation' => [
                'id' => (string) $talk->public_id,
                'title' => (string) $talk->title,
                'url' => route('assistant.chat.show', ['conversation' => $talk->public_id]),
            ],
            'answer' => $line,
        ])->header('Cache-Control', 'no-store');
    }

    /** ⛔ my talk in this company, or 404 — never another person's */
    private function mine(User $user, string $publicId): AssistantConversation
    {
        return AssistantConversation::query()->mine($user)->wherePublicId($publicId)->first() ?? abort(404);
    }

    /**
     * ⓘ The history panel: my talks in this company, newest first, grouped আজ / গতকাল / আগে.
     *
     * @return list<array{key: string, group: string, items: list<array{id: string, title: string, time: string, url: string, on: bool}>}>
     */
    private function history(User $user, ?AssistantConversation $current): array
    {
        $today = Carbon::today();
        $groups = [];

        $talks = AssistantConversation::query()->mine($user)
            ->orderByDesc('last_at')->orderByDesc('id')
            ->limit(AssistantConversation::HISTORY)
            ->get(['id', 'public_id', 'title', 'last_at', 'created_at']);

        foreach ($talks as $t) {
            $at = $t->last_at ?? $t->created_at ?? Carbon::now();
            $key = $at->gte($today) ? 'today' : ($at->gte($today->copy()->subDay()) ? 'yesterday' : 'older');
            $groups[$key] ??= ['key' => $key, 'group' => (string) __('assistant::chat.group.'.$key), 'items' => []];
            $groups[$key]['items'][] = [
                'id' => (string) $t->public_id,
                'title' => (string) $t->title,
                'time' => $key === 'older' ? $at->format('d-m-Y') : $at->format('H:i'),
                'url' => route('assistant.chat.show', ['conversation' => $t->public_id]),
                'on' => $current !== null && (int) $current->id === (int) $t->id,
            ];
        }

        return array_values(array_filter([$groups['today'] ?? null, $groups['yesterday'] ?? null, $groups['older'] ?? null]));
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @return array<int, array<string, mixed>>
     */
    private static function withoutLastAnswerTo(array $messages, string $question): array
    {
        $n = count($messages);

        if ($n >= 2 && ($messages[$n - 2]['role'] ?? '') === 'me' && (string) ($messages[$n - 2]['text'] ?? '') === $question
            && ($messages[$n - 1]['role'] ?? '') === 'ai') {
            return array_slice($messages, 0, $n - 2);
        }

        return $messages;
    }
}
