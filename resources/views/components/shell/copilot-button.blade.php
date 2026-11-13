{{--
    ⭐ কোপাইলটের দ্রুত জিজ্ঞাসা — উপরের বারে সার্চ বক্সের ঠিক ডানে মগজ (মালিক, ১২ নভেম্বর ২০২৬; নমুনা 3uJhK92DAMhwyELt63mSmr)।

    চাপলে (বা Ctrl+J) পুরো পর্দা জুড়ে কেবল জিজ্ঞাসা — Siri আর Gemini-র মতো; পাতা বদলায় না, Esc বা × দিলে সেই পাতাতেই।
    ⛔ এখানে সকালের সারাংশ, নজর, প্রস্তাব বা খাতা নেই — সেসব বাঁ পাশের মডিউল বারের পুরো কোপাইলটে (মালিক: "এত সময় নাই যে সব পর্দা
    ঘুরে ঘুরে দেখিয়া")। উত্তর আসে [[CopilotApiController::ask()]] থেকে — ফোনের একই দরজা, একই চাবি, একই খাতা।
    ⓘ নামটা কন্ট্রোল প্যানেল থেকে ([[AssistantEngine::name()]]); কেবল যাঁর হাতে `assistant.use`।
    ⓘ ৬৪০px-এর নিচে বোতাম লুকায় (hidden sm:flex) — ৩৭৫px-এ প্রোফাইলের বোতাম পর্দার ভেতরে থাকে; ফোনে কোপাইলট অ্যাপে আর মেনুতে।
--}}
@auth
    @can('assistant.use')
        @php
            $copilot = app(\App\Core\Engines\Assistant\AssistantEngine::class)->name();
            $routeName = (string) request()->route()?->getName();
            // ⓘ ড্যাশবোর্ডের পথ `module.dashboard/{module}` — মডিউলটা পথের মান থেকে, বাকিগুলো নামের প্রথম অংশ থেকে
            $moduleCode = (string) (request()->route('module') ?: \Illuminate\Support\Str::before($routeName, '.'));
            $module = app(\App\Core\Module\ModuleRegistry::class)->get($moduleCode);
            $here = $module !== null ? (string) ($module->name[app()->getLocale()] ?? $module->name['en'] ?? '') : '';
            $examples = collect(app(\App\Core\Engines\Assistant\ToolRegistry::class)->for(auth()->user()))->keys()
                ->map(fn (string $t): string => (string) __('assistant::ask.example.'.str_replace('.', '_', $t)))
                ->filter(fn (string $e): bool => ! str_starts_with($e, 'assistant::'))->take(4)->values();
            // ⓘ x-data-র লেখায় একক উদ্ধৃতি থাকলে JS ভাঙত — বাংলা লেখায় নেই, ইংরেজিতে বাদ
            $say = fn (string $key): string => e(str_replace("'", '', (string) __($key)));
        @endphp
        <div class="hidden sm:flex ms-1 me-2" data-copilot-quick
             x-data="copilotQuick({ url: '{{ route('assistant.quick') }}', feedbackUrl: '{{ str_replace('999999999', '__ID__', route('assistant.feedback', ['event' => 999999999])) }}', csrf: '{{ csrf_token() }}', locale: '{{ app()->getLocale() }}', page: '{{ $routeName }}', failed: '{{ $say('assistant::quick.failed') }}', noKey: '{{ $say('assistant::quick.no_key') }}', ask: '{{ $say('assistant::quick.placeholder') }}', reply: '{{ $say('assistant::quick.reply') }}', micOn: '{{ $say('assistant::quick.mic') }}', micOff: '{{ $say('assistant::quick.mic_off') }}' })">
            <button type="button" class="cq-btn" data-copilot-button @click="show()"
                    title="{{ __('assistant::quick.open', ['name' => $copilot]) }}" aria-label="{{ __('assistant::quick.open', ['name' => $copilot]) }}">
                <x-shell.copilot-mark size="bare" />
                <span class="cq-label">{{ $copilot }}</span>
            </button>
            {{-- ⓘ মগজের রঙের ঢাল — নীল → বেগুনি → গোলাপি → কমলা, ধীরে বদলায়; পাতায় একবার, প্রতিটা মগজ এটাই পড়ে --}}
            <svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
                <defs>
                    <linearGradient id="cq-grad" x1="0" y1="0" x2="1" y2="1">
                        {{-- ⓘ রং আর তার বদল [[copilot-quick.css]]-এ (.cq-s1–3) — পর্দার Blade-এ রং নয় (EveryScreenObeysTheThemeTest) --}}
                        <stop class="cq-s1" offset="0%"/>
                        <stop class="cq-s2" offset="50%"/>
                        <stop class="cq-s3" offset="100%"/>
                    </linearGradient>
                </defs>
            </svg>

            <template x-teleport="body">
                <div class="cq-sheet" x-show="open" x-cloak x-transition.opacity role="dialog" aria-modal="true"
                     aria-label="{{ __('assistant::quick.open', ['name' => $copilot]) }}" :data-glow="glow()" data-copilot-sheet>
                    <div class="cq-col">
                        <div class="cq-head">
                            <x-shell.copilot-mark size="lg" x-bind:class="markState()" />
                            <span class="cq-name">{{ $copilot }}</span>
                            @if ($here !== '')
                                <span class="cq-ctx">{{ __('assistant::quick.here', ['page' => $here]) }}</span>
                            @endif
                            <span class="cq-close"><span class="hidden sm:inline"><span class="cq-kbd">Esc</span> {{ __('assistant::quick.esc') }}</span>
                                <button type="button" class="cq-round" @click="close()" aria-label="{{ __('assistant::quick.close') }}">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                                </button>
                            </span>
                        </div>

                        {{-- ১ · খুলল — শুভেচ্ছা; নিচে এই মানুষের চাবির প্রশ্নের উদাহরণ --}}
                        <div x-show="idle()" data-copilot-idle>
                            <p class="cq-hello">{{ __('assistant::quick.hello', ['person' => auth()->user()->name]) }} <span>{{ __('assistant::quick.hello_ask') }}</span></p>
                        </div>

                        {{-- ২ · শুনছে — আলোর গোলা, বলা কথা সাথে সাথে লেখা; মানুষ না থামানো পর্যন্ত শোনে (১৩ নভেম্বর ২০২৬) --}}
                        <div x-show="listening" x-cloak style="display:flex;flex-direction:column;gap:1rem" data-copilot-listening>
                            <div class="cq-orb" aria-hidden="true"></div>
                            <p class="cq-heard" aria-live="polite" x-text="heard"></p>
                            <div class="cq-wave" aria-hidden="true"><b></b><b></b><b></b><b></b><b></b><b></b><b></b><b></b><b></b><b></b><b></b></div>
                            <div class="cq-center">
                                <button type="button" class="cq-pill" @click="sendListening()" data-copilot-send-now>{{ __('assistant::quick.send_now') }}</button>
                                <button type="button" class="cq-pill" @click="stopListening()" data-copilot-done>{{ __('assistant::quick.done') }}</button>
                                <button type="button" class="cq-pill" @click="cancelListening()">{{ __('assistant::quick.cancel') }}</button>
                            </div>
                            <p class="cq-safe" style="text-align:center;margin:0">{{ __('assistant::quick.listening') }}</p>
                        </div>

                        {{-- ৩ · চ্যাট — প্রশ্ন ডানে, উত্তর বাঁয়ে মগজের পাশে --}}
                        <div class="cq-thread" x-ref="thread" x-show="chatting()" x-cloak aria-live="polite" data-copilot-thread>
                            <template x-for="item in thread" :key="item.id">
                                <div>
                                    <template x-if="item.me"><div style="display:flex;justify-content:flex-end"><div class="cq-me" x-text="item.text"></div></div></template>
                                    <template x-if="! item.me">
                                        <div class="cq-bot">
                                            <x-shell.copilot-mark />
                                            <div class="cq-ans">
                                                <div class="cq-big" x-text="item.head"></div>
                                                <div class="cq-rows" x-show="item.pairs.length > 0">
                                                    <template x-for="(pair, i) in item.pairs" :key="i"><div style="display:contents"><div class="cq-k" x-text="pair[0]"></div><div class="cq-v" x-text="pair[1]"></div></div></template>
                                                </div>
                                                <div class="cq-src" x-show="item.source !== ''" x-text="item.source"></div>
                                                <div class="cq-acts">
                                                    <button type="button" class="cq-pill" x-show="canSpeak()" @click="toggleSpeak(item)" :aria-pressed="isSpeaking(item)">
                                                        <span class="cq-mini" x-show="isSpeaking(item)" aria-hidden="true"><b></b><b></b><b></b></span>
                                                        <span x-show="! isSpeaking(item)">{{ __('assistant::quick.read') }}</span>
                                                        <span x-show="isSpeaking(item)">{{ __('assistant::quick.stop_read') }}</span>
                                                    </button>
                                                    <button type="button" class="cq-pill" x-show="item.answerId" @click="vote(item, 1)" :aria-pressed="votedUp(item)" aria-label="{{ __('assistant::quick.up') }}">
                                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M7 11v9H4v-9zM7 11l4-7a2 2 0 0 1 3 2l-1 4h6a2 2 0 0 1 2 2l-2 7a2 2 0 0 1-2 1H7"/></svg>
                                                    </button>
                                                    <button type="button" class="cq-pill" x-show="item.answerId" @click="vote(item, -1)" :aria-pressed="votedDown(item)" aria-label="{{ __('assistant::quick.down') }}">
                                                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M17 13V4h3v9zM17 13l-4 7a2 2 0 0 1-3-2l1-4H5a2 2 0 0 1-2-2l2-7a2 2 0 0 1 2-1h10"/></svg>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <p class="cq-typing" x-show="busy">{{ __('assistant::quick.thinking') }}</p>
                        </div>

                        <p class="cq-safe" x-show="micBlocked && ! listening" x-cloak data-copilot-mic-blocked>{{ __('assistant::quick.mic_blocked') }}</p>
                        <form class="cq-box" x-show="! listening" @submit.prevent="submit()" data-copilot-box>
                            <label for="cq-q" class="sr-only">{{ __('assistant::quick.question') }}</label>
                            <input id="cq-q" x-ref="box" x-model="q" autocomplete="off" enterkeyhint="send" maxlength="500" :placeholder="boxHint()">
                            <button type="button" class="cq-ib cq-mic" @click="listen()" :disabled="! canListen()" :title="micTitle()" data-copilot-mic
                                    aria-label="{{ __('assistant::quick.mic') }}">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v3"/></svg>
                            </button>
                            <button type="submit" class="cq-ib cq-send" aria-label="{{ __('assistant::quick.send') }}">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                            </button>
                        </form>

                        <div class="cq-chips" x-show="idle()" data-copilot-examples>
                            @foreach ($examples as $example)
                                @php $said = $say === null ? '' : str_replace("'", '', $example); @endphp
                                <button type="button" class="cq-chip" @click="pick('{{ $said }}')">{{ $example }}</button>
                            @endforeach
                        </div>

                        <div class="cq-foot" x-show="! listening">
                            <span class="hidden sm:inline"><span class="cq-kbd">Enter</span> {{ __('assistant::quick.hint_enter') }}</span>
                            <span class="hidden sm:inline"><span class="cq-kbd">Ctrl + J</span> {{ __('assistant::quick.hint_toggle') }}</span>
                            <span class="cq-safe">{{ __('assistant::quick.safe') }}</span>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endcan
@endauth
