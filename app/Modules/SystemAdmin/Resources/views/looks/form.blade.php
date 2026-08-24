{{--
    একটা রূপ লেখা — থিম ইঞ্জিনের ধাপ ৩।

    ── কেন টোকেনের নাম হাতে লিখতে হয়, রঙের চাকা নয় ──────────────────────
    একটা রঙের চাকা দিলে প্রশ্নটা হত "কোন রংটা?", অথচ আসল প্রশ্ন "কোন
    **জিনিসের** রং?"। ছয়শো টোকেনের মধ্যে ভুলটা প্রায় সবসময় দ্বিতীয়
    প্রশ্নে হয় — মানুষ টপবার বদলাতে গিয়ে সাইডবার বদলে ফেলেন।

    তাই নামটাই আগে, আর `<datalist>` থেকে চেনা নামগুলো আসে। বানান ভুল
    হলে সেভের সময়ই ফেরে; পর্দায় নীরবে হারায় না।

    ── কেন হালকা ও গাঢ় পাশাপাশি ─────────────────────────────────────────
    গাঢ় রূপ পরে বানানোর জিনিস নয়। একটা জমিন গাঢ় করে কালি না বদলালে
    লেখাটা উধাও হয় — আর সেই ভুলটা ধরা পড়ে ছয় সপ্তাহ পরে, যখন কেউ
    রাতের শিফটে গাঢ় থিম চালু করেন। এক সারিতে দুইটা থাকলে জোড়াটা
    চোখের সামনেই থাকে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $skin->exists ? $skin->name : __('core.look.new') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$skin->exists ? $skin->name : __('core.look.new')"
                          :subtitle="__('core.look.subtitle')">
            <x-slot:actions>
                @if ($skin->exists)
                    <form method="POST" action="{{ route('system_admin.look.preview', $skin) }}">
                        @csrf
                        <x-ui.button type="submit" tone="secondary">{{ __('core.look.preview') }}</x-ui.button>
                    </form>
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        /*
         * ফর্মের সারিগুলো — নাম, হালকা, গাঢ়।
         *
         * দুইটা মানচিত্র (light, dark) এক সারিতে মেলানো হয়, কারণ
         * মানুষ টোকেন ধরে ভাবেন, থিম ধরে নয়। একটা টোকেন কেবল গাঢ়ে
         * বদলানো থাকলেও তার সারিটা দেখা যায়।
         */
        $said = $skin->tokens ?? [];
        $light = $said['light'] ?? [];
        $dark = $said['dark'] ?? [];

        $rows = [];

        foreach (array_keys($light + $dark) as $name) {
            $rows[] = [
                'name' => $name,
                'light' => $light[$name] ?? '',
                'dark' => $dark[$name] ?? '',
            ];
        }

        // অন্তত একটা খালি সারি, নাহলে নতুন রূপে লেখার জায়গাই নেই
        $rows[] = ['name' => '', 'light' => '', 'dark' => ''];
    @endphp

    <div class="grid gap-4 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <form method="POST"
              action="{{ $skin->exists ? route('system_admin.look.update', $skin) : route('system_admin.look.store') }}"
              class="space-y-4">
            @csrf
            @if ($skin->exists)
                @method('PUT')
            @endif

            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <div class="grid gap-3 sm:grid-cols-2">
                    <x-ui.field name="name" :label="__('core.look.name')"
                                :value="old('name', $skin->name)" required />

                    {{-- পূর্বপুরুষ — এখানেই ঠিক হয় কোন ষাটটা টোকেন
                         উত্তরাধিকারে নামবে, তাই এটা রঙের চেয়েও বড় বাছাই --}}
                    <x-ui.select name="parent" :label="__('core.look.parent')"
                                 :options="$parents"
                                 :selected="old('parent', $skin->parent)" required />
                </div>
            </section>

            <section data-boxed
                     x-data="{ rows: {{ count($rows) }} }"
                     class="rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card) p-4">
                <h2 class="font-semibold">{{ __('core.look.tokens') }}</h2>

                {{-- সবচেয়ে বেশি যে ভুলটা হয়, সেটাই এখানে লেখা --}}
                <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                    {{ __('core.look.dark_note') }}
                </p>

                {{-- চেনা নামগুলো — টাইপ করলেই আসে, আর তাতে বানান ভুল কমে --}}
                <datalist id="look-tokens">
                    @foreach ($known as $name)
                        <option value="{{ $name }}"></option>
                    @endforeach
                </datalist>

                <div class="space-y-2">
                    @foreach ($rows as $i => $row)
                        <div class="grid gap-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
                            <input type="text" list="look-tokens"
                                   name="tokens[{{ $i }}][name]"
                                   value="{{ $row['name'] }}"
                                   placeholder="{{ __('core.look.token_name') }}"
                                   aria-label="{{ __('core.look.token_name') }}"
                                   class="w-full rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-app) px-2 py-1.5 font-mono text-sm">

                            <input type="text" name="tokens[{{ $i }}][light]"
                                   value="{{ $row['light'] }}"
                                   placeholder="{{ __('core.appearance.light') }}"
                                   aria-label="{{ __('core.appearance.light') }}"
                                   class="w-full rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-app) px-2 py-1.5 font-mono text-sm">

                            <input type="text" name="tokens[{{ $i }}][dark]"
                                   value="{{ $row['dark'] }}"
                                   placeholder="{{ __('core.appearance.dark') }}"
                                   aria-label="{{ __('core.appearance.dark') }}"
                                   class="w-full rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-app) px-2 py-1.5 font-mono text-sm">
                        </div>
                    @endforeach

                    {{-- Alpine দিয়ে যোগ করা সারিগুলো। JavaScript বন্ধ থাকলেও
                         উপরের সারিগুলো কাজ করে — কেবল যোগ করার সুবিধাটা যায়। --}}
                    <template x-for="n in Math.max(0, rows - {{ count($rows) }})" :key="n">
                        <div class="grid gap-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1fr)]">
                            <input type="text" list="look-tokens"
                                   :name="`tokens[${ {{ count($rows) }} + n - 1 }][name]`"
                                   placeholder="{{ __('core.look.token_name') }}"
                                   aria-label="{{ __('core.look.token_name') }}"
                                   class="w-full rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-app) px-2 py-1.5 font-mono text-sm">

                            <input type="text"
                                   :name="`tokens[${ {{ count($rows) }} + n - 1 }][light]`"
                                   placeholder="{{ __('core.appearance.light') }}"
                                   aria-label="{{ __('core.appearance.light') }}"
                                   class="w-full rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-app) px-2 py-1.5 font-mono text-sm">

                            <input type="text"
                                   :name="`tokens[${ {{ count($rows) }} + n - 1 }][dark]`"
                                   placeholder="{{ __('core.appearance.dark') }}"
                                   aria-label="{{ __('core.appearance.dark') }}"
                                   class="w-full rounded-(--radius-field) border border-(--color-border)
                                          bg-(--color-surface-app) px-2 py-1.5 font-mono text-sm">
                        </div>
                    </template>
                </div>

                <button type="button" x-on:click="rows++"
                        class="mt-3 min-h-(--spacing-touch) text-sm text-(--color-brand-500) hover:underline">
                    {{ __('core.look.add_token') }}
                </button>
            </section>

            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
        </form>

        <div class="space-y-4">
            @if ($skin->exists)
                {{--
                    প্রকাশ — আর কেন এটা এখনো পারা যাচ্ছে না।

                    অভিযোগগুলো বোতামের **পাশে** দেখানো হয়, আলাদা পাতায়
                    নয়। ভুলটা আর তার প্রতিকার এক পর্দায় না থাকলে মানুষ
                    বোতামে চাপ দিয়ে যান, বার্তা পড়েন, ফিরে যান, ভুলে
                    যান কী লেখা ছিল।
                --}}
                <section data-boxed
                         class="rounded-(--radius-card) border border-(--color-border)
                                bg-(--color-surface-card) p-4">
                    <h2 class="mb-3 font-semibold">{{ __('core.look.publish') }}</h2>

                    @if ($complaints !== [])
                        <ul role="alert"
                            class="mb-3 list-inside list-disc rounded-(--radius-field)
                                   bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                                   text-(--color-badge-danger-ink)">
                            @foreach ($complaints as $complaint)
                                <li>{{ $complaint }}</li>
                            @endforeach
                        </ul>
                    @endif

                    <form method="POST" action="{{ route('system_admin.look.publish', $skin) }}"
                          class="space-y-3">
                        @csrf

                        <x-ui.field name="note" :label="__('core.look.note')"
                                    :hint="__('core.look.note_hint')" />

                        <x-ui.button type="submit" tone="primary"
                                     :disabled="$complaints !== []">
                            {{ __('core.look.publish') }}
                        </x-ui.button>
                    </form>
                </section>

                <section data-boxed
                         class="rounded-(--radius-card) border border-(--color-border)
                                bg-(--color-surface-card) p-4">
                    <h2 class="mb-3 font-semibold">{{ __('core.look.versions') }}</h2>

                    <ul class="divide-y divide-(--color-border) text-sm">
                        @forelse ($versions as $version)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <div class="min-w-0">
                                    <span class="font-medium">
                                        {{ __('core.look.version_n', ['n' => $version->version]) }}
                                    </span>

                                    <p class="text-2xs text-(--color-ink-muted)">
                                        {{ \App\Core\Support\DateFormat::formatWithTime($version->published_at) }}
                                        @if ($version->publisher)
                                            · {{ $version->publisher->name }}
                                        @endif
                                        @if ($version->reverted_from)
                                            · {{ __('core.look.reverted_from', ['n' => $version->reverted_from]) }}
                                        @endif
                                    </p>

                                    @if ($version->note)
                                        <p class="mt-0.5 text-2xs">{{ $version->note }}</p>
                                    @endif
                                </div>

                                @if (! $loop->first)
                                    <form method="POST"
                                          action="{{ route('system_admin.look.revert', [$skin, $version]) }}">
                                        @csrf
                                        <x-ui.button type="submit" tone="secondary">
                                            {{ __('core.look.revert') }}
                                        </x-ui.button>
                                    </form>
                                @endif
                            </li>
                        @empty
                            <li class="py-2 text-(--color-ink-muted)">{{ __('core.look.draft') }}</li>
                        @endforelse
                    </ul>
                </section>
            @endif
        </div>
    </div>
</x-layouts.app>
