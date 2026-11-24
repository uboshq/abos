<label class="block">
    <span class="mb-1 block text-sm text-(--color-ink-muted)">{{ __('customer::binding.ends_on') }}</span>
    <x-ui.date name="ends_on" :id="'ends-on-'.$id" :required="true" :value="old('_pop') === $id ? old('ends_on', $today) : $today" />
</label>
