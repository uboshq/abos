{{--
    ⭐ The full-screen Copilot page — owner's web sample copilot-web-sample (21 Nov 2026).

    ক · start: the big live Ai mark, "শুভ দুপুর, <name> — কী জানতে চান?", the ask box (paper · mic · send), six chips from
        the person's own questions, key hints. ⛔ No "Copilot ভুল করতে পারে…" line — the owner took it out.
    খ · a talk: history (আজ / গতকাল / আগে) on the left, answers with text, table, small chart, "উৎস: …" chips and actions.

    Owner rules: title + "নতুন কথা" + "ইতিহাস" on ONE row, no description line, no notice strips; English digits.
    ⓘ Colours live in [[copilot-page.css]] (EveryScreenObeysTheThemeTest); the ABOS looks draw the living Ai mark
      ([[AiMark::shows()]]), every other look keeps the brain.
    ⓘ Logic in [[copilot-page.js]] (CSP-Alpine: the expressions here only read fields and call methods).
--}}
@php
    $aiMark = \App\Core\Support\AiMark::shows();
    $msgr = class_exists(\App\Modules\Messaging\Support\MessengerChrome::class) && \App\Modules\Messaging\Support\MessengerChrome::on()
        && \Illuminate\Support\Facades\Route::has('messaging.x.papers.find');
    $state = [
        'name' => $name,
        'askUrl' => route('assistant.chat.ask'),
        'chatUrl' => route('assistant.chat'),
        'homeUrl' => route('dashboard'),
        'feedbackUrl' => str_replace('999999999', '__ID__', route('assistant.feedback', ['event' => 999999999])),
        'papersUrl' => $msgr ? route('messaging.x.papers.find') : null,
        'csrf' => csrf_token(),
        'locale' => app()->getLocale(),
        'talk' => $talk,
        'history' => $history,
        'words' => [
            'mic' => __('assistant::chat.mic'), 'mic_on' => __('assistant::chat.mic_on'), 'mic_off' => __('assistant::chat.mic_off'),
            'failed' => __('assistant::chat.failed'), 'stopped' => __('assistant::chat.stopped'), 'no_key' => __('assistant::quick.no_key'),
            'today' => __('assistant::chat.group.today'),
        ],
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $name }}</x-slot:title>

    <div class="cpp" x-data="copilotPage" data-copilot-page :data-busy="busy ? '1' : '0'">
        <script type="application/json" data-cpp-state>@json($state)</script>

        {{-- ── title row: Copilot + নতুন কথা + ইতিহাস, one row, no description line ── --}}
        <div class="cpp-head">
            <h1>
                @if ($aiMark)
                    <x-ui.ai-mark :size="30" x-bind:data-ai-busy="busy" />
                @else
                    <x-ui.icon name="brain" :size="26" />
                @endif
                <span>{{ $name }}</span>
            </h1>
            <a href="{{ route('assistant.chat') }}" class="cpp-wb cpp-wb-pri" data-cpp-new><x-ui.icon name="plus" :size="17" />{{ __('assistant::chat.new') }}</a>
            <button type="button" class="cpp-wb" @click="toggleHistory()" :aria-pressed="histOpen ? 'true' : 'false'" data-cpp-history-toggle><x-ui.icon name="clock" :size="17" />{{ __('assistant::chat.history') }}</button>
        </div>

        <div class="cpp-card">
            {{-- ── history: my talks in this company, আজ / গতকাল / আগে ── --}}
            <aside class="cpp-hist" x-show="histOpen" x-cloak data-cpp-history aria-label="{{ __('assistant::chat.history') }}">
                <label class="cpp-hsr">
                    <x-ui.icon name="search" :size="16" />
                    <input type="search" x-model="histQ" placeholder="{{ __('assistant::chat.search') }}" aria-label="{{ __('assistant::chat.search') }}">
                </label>
                <template x-for="g in cfg.history" :key="g.key">
                    <div>
                        <div class="cpp-hg" x-text="g.group"></div>
                        <template x-for="row in g.items" :key="row.id">
                            <a class="cpp-hi" :href="row.url" x-show="historyShown(row)" :class="row.on ? 'cpp-on' : ''" :aria-current="row.on ? 'page' : 'false'">
                                <x-ui.icon name="chat" :size="16" />
                                <span x-text="row.title"></span>
                                <time class="cpp-n" x-text="row.time"></time>
                            </a>
                        </template>
                    </div>
                </template>
                @if ($history === [])
                    <p class="cpp-hempty" x-show="cfg.history.length === 0">{{ __('assistant::chat.no_history') }}</p>
                @endif
            </aside>

            <section class="cpp-main">
                {{-- ── ক · start screen ── --}}
                <div class="cpp-hero" x-show="fresh()" data-cpp-start>
                    <div class="cpp-glow">
                        @if ($aiMark)
                            <x-ui.ai-mark :size="128" x-bind:data-ai-busy="busy" />
                        @else
                            <x-ui.icon name="brain" :size="96" />
                        @endif
                    </div>
                    <h2 class="cpp-hello" data-cpp-hello>{{ $greeting['hello'] }} <span>{{ $greeting['ask'] }}</span></h2>
                </div>

                {{-- ── খ · the talk ── --}}
                <div class="cpp-thread" x-ref="thread" x-show="started()" x-cloak aria-live="polite" data-cpp-thread>
                    <div class="cpp-in">
                        <template x-for="item in items" :key="item.key">
                            <div>
                                <template x-if="item.me"><div class="cpp-uq" x-text="item.text"></div></template>
                                <template x-if="! item.me">
                                    <div class="cpp-ua" :data-cpp-answer="item.key" :data-state="item.state">
                                        <span class="cpp-avm">
                                            @if ($aiMark)
                                                <x-ui.ai-mark :size="30" />
                                            @else
                                                <x-ui.icon name="brain" :size="24" />
                                            @endif
                                        </span>
                                        <div class="cpp-body">
                                            <p class="cpp-print-q" x-text="item.question"></p>
                                            <p class="cpp-text" x-text="item.text"></p>

                                            <div class="cpp-grid" x-show="item.hasTable || item.hasChart" :class="item.hasChart ? 'cpp-two' : ''">
                                                <div class="cpp-mini" x-show="item.hasTable">
                                                    <table class="ui-grid cpp-t">
                                                        <thead>
                                                            <tr>
                                                                <th class="cpp-no">#</th>
                                                                <template x-for="h in item.heads" :key="h.key"><th :class="h.num ? 'cpp-r' : ''" x-text="h.text"></th></template>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <template x-for="row in item.table" :key="row.key">
                                                                <tr>
                                                                    <td class="cpp-no cpp-n" x-text="row.no"></td>
                                                                    <template x-for="c in row.cells" :key="c.key">
                                                                        <td :class="c.num ? 'cpp-r cpp-n' : ''">
                                                                            <a x-show="c.href !== ''" :href="c.href" x-text="c.text"></a>
                                                                            <span x-show="c.href === ''" x-text="c.text"></span>
                                                                        </td>
                                                                    </template>
                                                                </tr>
                                                            </template>
                                                        </tbody>
                                                    </table>
                                                </div>
                                                <template x-if="item.hasChart">
                                                    <div class="cpp-mini" data-cpp-chart>
                                                        <h5 x-text="item.chart.title"></h5>
                                                        <div class="cpp-bars" role="img" :aria-label="item.chart.title">
                                                            <template x-for="b in item.chart.bars" :key="b.key">
                                                                <div class="cpp-bar">
                                                                    <div class="cpp-bar-track"><span class="cpp-bar-fill" :style="b.style"><b class="cpp-n" x-text="b.value"></b></span></div>
                                                                    <small x-text="b.label"></small>
                                                                </div>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>

                                            <div class="cpp-src" x-show="item.answered" data-cpp-sources>
                                                <template x-for="s in item.sources" :key="s.key">
                                                    <a class="cpp-sc" :href="s.href !== '' ? s.href : null"><x-ui.icon name="book" :size="13" /><span x-text="s.label"></span></a>
                                                </template>
                                                <span class="cpp-sc"><x-ui.icon name="lock" :size="13" />{{ __('assistant::chat.yours_only') }}</span>
                                                <span class="cpp-by" x-show="item.by !== ''" x-text="item.by"></span>
                                            </div>

                                            <div class="cpp-acts" x-show="item.answered" data-cpp-actions>
                                                <button type="button" class="cpp-ab cpp-ab-pri" x-show="hasList(item)" @click="openList(item)"><x-ui.icon name="fullscreen" :size="16" />{{ __('assistant::chat.open_list') }}</button>
                                                <button type="button" class="cpp-ab" x-show="shareOn" :data-msgr-share="shareInfo(item)" data-cpp-share><x-ui.icon name="chat" :size="16" />{{ __('assistant::chat.share') }}</button>
                                                <button type="button" class="cpp-ab" @click="pdf(item)" data-cpp-pdf><x-ui.icon name="download" :size="16" />{{ __('assistant::chat.pdf') }}</button>
                                                <span class="cpp-sep"></span>
                                                <button type="button" class="cpp-ib" @click="copy(item)" :aria-pressed="isCopied(item) ? 'true' : 'false'" title="{{ __('assistant::chat.copy') }}" aria-label="{{ __('assistant::chat.copy') }}">
                                                    <x-ui.icon name="check" :size="17" x-show="isCopied(item)" />
                                                    <x-ui.icon name="paper" :size="17" x-show="! isCopied(item)" />
                                                </button>
                                                <button type="button" class="cpp-ib" x-show="canVote(item)" @click="vote(item, 1)" :aria-pressed="votedUp(item) ? 'true' : 'false'" title="{{ __('assistant::chat.good') }}" aria-label="{{ __('assistant::chat.good') }}">
                                                    <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M7 11v9H4v-9zM7 11l4-7a2 2 0 0 1 3 2l-1 4h6a2 2 0 0 1 2 2l-2 7a2 2 0 0 1-2 1H7"/></svg>
                                                </button>
                                                <button type="button" class="cpp-ab" x-show="canRegenerate(item)" @click="regenerate(item)" data-cpp-regenerate><x-ui.icon name="refresh" :size="16" />{{ __('assistant::chat.regenerate') }}</button>
                                            </div>
                                            <div class="cpp-acts" x-show="! item.answered && canRegenerate(item)">
                                                <button type="button" class="cpp-ab" @click="regenerate(item)"><x-ui.icon name="refresh" :size="16" />{{ __('assistant::chat.regenerate') }}</button>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>

                        {{-- ⓘ while the answer comes: the mark goes busy, "থামান" with the three dots --}}
                        <div class="cpp-ua cpp-wait" x-show="busy" x-cloak data-cpp-busy>
                            <span class="cpp-avm">
                                @if ($aiMark)
                                    <x-ui.ai-mark :size="30" busy />
                                @else
                                    <x-ui.icon name="brain" :size="24" />
                                @endif
                            </span>
                            <div class="cpp-body">
                                <button type="button" class="cpp-stop" @click="stop()" data-cpp-stop><i></i>{{ __('assistant::chat.stop') }}<span class="cpp-dots"><b></b><b></b><b></b></span></button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ── the ask box: paper · text · mic · send ── --}}
                <div class="cpp-foot" :class="started() ? '' : 'cpp-foot-hero'">
                    <form class="cpp-ask" @submit.prevent="submit()" data-cpp-ask>
                        @if ($msgr)
                            <button type="button" class="cpp-cb" @click="toggleAttach()" :aria-expanded="attachOpen ? 'true' : 'false'" title="{{ __('assistant::chat.attach') }}" aria-label="{{ __('assistant::chat.attach') }}" data-cpp-attach>
                                <x-ui.icon name="attachment" :size="23" />
                            </button>
                        @endif
                        <label for="cpp-q" class="sr-only">{{ __('assistant::chat.placeholder') }}</label>
                        <input id="cpp-q" x-ref="box" x-model="q" autocomplete="off" enterkeyhint="send" maxlength="500"
                               :placeholder="started() ? '{{ e(str_replace("'", '', __('assistant::chat.reply'))) }}' : '{{ e(str_replace("'", '', __('assistant::chat.placeholder'))) }}'"
                               @disabled(! $askOn)>
                        <span class="cpp-wave" x-show="listening" x-cloak aria-hidden="true"><b></b><b></b><b></b><b></b><b></b><b></b><b></b><b></b><b></b></span>
                        <button type="button" class="cpp-cb" :class="listening ? 'cpp-mic-on' : ''" @click="toggleMic()" :disabled="! canListen()" :title="micTitle()" :aria-label="micTitle()" :aria-pressed="listening ? 'true' : 'false'" data-cpp-mic>
                            <x-ui.icon name="mic" :size="23" />
                        </button>
                        <button type="submit" class="cpp-cb cpp-send" :class="sendDim() ? 'cpp-dim' : ''" aria-label="{{ __('assistant::chat.send') }}" title="{{ __('assistant::chat.send') }}">
                            <x-ui.icon name="send" :size="21" />
                        </button>
                        @if ($msgr)
                            <div class="cpp-attach" x-show="attachOpen" x-cloak @click.outside="closeAttach()" data-cpp-attach-panel>
                                <input type="search" x-ref="attach" x-model="attachQ" @input="findPaper()" placeholder="{{ __('assistant::chat.attach_find') }}" aria-label="{{ __('assistant::chat.attach_find') }}">
                                <template x-for="hit in attachHits" :key="hit.key">
                                    <button type="button" class="cpp-hit" @click="attachPaper(hit)"><b class="cpp-n" x-text="hit.no"></b><span x-text="hit.title"></span><small x-text="hit.meta"></small></button>
                                </template>
                                <p class="cpp-hempty" x-show="attachEmpty">{{ __('assistant::chat.attach_none') }}</p>
                            </div>
                        @endif
                    </form>

                    {{-- ক · six chips, then the key hints (only on the start screen) --}}
                    <div class="cpp-chips" x-show="fresh()" data-cpp-chips>
                        @foreach ($chips as $chip)
                            <button type="button" class="cpp-chip" data-tone="{{ $chip['tone'] }}" data-cpp-chip="{{ $chip['text'] }}" @click="pick($event)">
                                <i><x-ui.icon :name="$chip['icon']" :size="19" /></i>{{ $chip['text'] }}
                            </button>
                        @endforeach
                    </div>
                    <div class="cpp-kh" x-show="fresh()" data-cpp-keys>
                        <span><kbd>Ctrl</kbd>+<kbd>J</kbd> {{ __('assistant::chat.keys_open') }}</span>
                        <span><kbd>Esc</kbd> {{ __('assistant::chat.keys_close') }}</span>
                        <span><kbd>Enter</kbd> {{ __('assistant::chat.keys_send') }}</span>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-layouts.app>
