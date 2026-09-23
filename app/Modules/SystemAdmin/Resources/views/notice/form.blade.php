{{--
    নোটিশ লেখা বা বদলানো।

    ⚠️ দুইটা ঘরে আলাদা করে কারণ লেখা আছে: শ্রোতা খালি রাখলে **সবাই**
    দেখেন, আর চলন্ত বারের টিকটা সব নোটিশে দেওয়ার জিনিস নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $notice->exists
        ? __('system_admin::notice.edit')
        : __('system_admin::notice.new') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="$notice->exists
            ? __('system_admin::notice.edit')
            : __('system_admin::notice.new')" />
    </x-slot:header>

    <x-ui.errors />

    <form method="POST"
          action="{{ $notice->exists
              ? route('system_admin.notice.update', $notice->id)
              : route('system_admin.notice.store') }}"
          class="max-w-2xl space-y-4">
        @csrf
        @if ($notice->exists) @method('PUT') @endif

        <div data-boxed class="space-y-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">

            <x-ui.field name="title"
                        :label="__('system_admin::notice.field_title')"
                        :value="old('title', $notice->title)"
                        required maxlength="160" />

            <label class="block">
                <span class="mb-1 block text-2xs uppercase tracking-wide text-(--color-ink-muted)">
                    {{ __('system_admin::notice.body') }}
                </span>
                <textarea name="body" rows="6" maxlength="4000"
                          class="w-full rounded-(--radius-field) border border-(--color-border)
                                 bg-(--color-surface-app) px-2 py-1.5 text-sm"
                >{{ old('body', $notice->body) }}</textarea>
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                {{--
                    অগ্রাধিকার — আর এটা কেবল রং নয়, আচরণ।

                    ⓘ যেটা *গুরুত্বপূর্ণ* বা তার উপরে, সেটা নিচের বারে ওঠে।
                    যেটা *অত্যন্ত জরুরি* বা তার উপরে, সেটা সরানো যায় না আর
                    ডিফল্টে সই চায়।

                    ⚠️ তাই এই ঘরটা সাজসজ্জা নয় — এখানে বসানো শব্দটাই
                    ঠিক করে নোটিশটা কার চোখে কতবার পড়বে।
                --}}
                {{--
                    ধরন — আর এটা বসালে অগ্রাধিকার নিজে থেকেই বসার কথা।

                    ⓘ ঘরটা খালি রাখা যায়: অনেক নোটিশ কোনো ধরনেই পড়ে না।
                    ⚠️ বাধ্যতামূলক করলে প্রথম দিনে কোনো ধরনই নেই বলে একটা
                    নোটিশও লেখা যেত না।
                --}}
                <x-ui.select name="notice_category_id"
                             :label="__('core.notice.category_label')"
                             :options="\\App\\Models\\NoticeCategory::query()->orderBy('code')->get()
                                 ->mapWithKeys(fn ($c) => [$c->id => $c->code.' - '.$c->name()])"
                             placeholder="-"
                             :value="old('notice_category_id', $notice->notice_category_id)" />

                <x-ui.select name="priority"
                             :label="__('core.notice.priority_label')"
                             :options="collect(\App\Core\Support\NoticePriority::cases())
                                 ->mapWithKeys(fn ($p) => [$p->value => $p->label()])"
                             :value="old('priority', $notice->priority?->value ?? 'normal')" />

                <x-ui.field name="summary"
                            :label="__('core.notice.summary_label')"
                            :value="old('summary', $notice->summary)"
                            maxlength="300" />

                <x-ui.field name="starts_on" type="date"
                            :label="__('system_admin::notice.starts_on')"
                            :value="old('starts_on', $notice->starts_on?->format('Y-m-d'))" />

                <x-ui.field name="ends_on" type="date"
                            :label="__('system_admin::notice.ends_on')"
                            :value="old('ends_on', $notice->ends_on?->format('Y-m-d'))" />
            </div>
        </div>

        {{-- ⭐ কারা দেখবেন — মালিকের বাছাই: ভূমিকা ধরে।

             ⚠️ কিছু না বাছলে সবাই। ⓘ উল্টোটা ধরলে ভূমিকা বসাতে ভুলে
             যাওয়া নোটিশটা কেউই দেখত না, আর লেখক ভাবতেন পাঠানো হয়েছে। --}}
        <div data-boxed class="space-y-3 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
            <h2 class="text-2xs font-semibold uppercase tracking-wide text-(--color-ink-muted)">
                {{ __('system_admin::notice.roles') }}
            </h2>

            <p class="text-2xs text-(--color-ink-muted)">{{ __('system_admin::notice.roles_hint') }}</p>

            <div class="grid gap-2 sm:grid-cols-2">
                @foreach ($roles as $name => $label)
                    <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                        <input type="checkbox" name="roles[]" value="{{ $name }}"
                               @checked(in_array($name, old('roles', $chosen), true))
                               class="size-4">
                        <span>{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </div>

        <div data-boxed class="space-y-3 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
            <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1"
                       @checked(old('is_active', $notice->is_active)) class="size-4">
                <span>{{ __('system_admin::notice.is_active') }}</span>
            </label>

            <label class="flex min-h-(--spacing-touch) items-start gap-2 text-sm">
                <input type="checkbox" name="in_ticker" value="1"
                       @checked(old('in_ticker', $notice->in_ticker)) class="mt-1 size-4">
                <span>
                    {{ __('system_admin::notice.in_ticker') }}
                    <span class="block text-2xs text-(--color-ink-muted)">
                        {{ __('system_admin::notice.ticker_hint') }}
                    </span>
                </span>
            </label>
        </div>

        <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
    </form>
</x-layouts.app>
