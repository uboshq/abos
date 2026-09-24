{{--
    একটা সীমা তুলে নেওয়া।

    ── ⚠️ কেন নিশ্চিত করতে বলা হয় ──────────────────────────────────────
    ⓘ সীমা তুলে নেওয়া **উদার** দিকে নিয়ে যায়, কড়া দিকে নয়: সারিটা গেলে
    ঐ রোল আর কোনো অঙ্কে আটকায় না। ⛔ অর্থাৎ একটা ভুল ক্লিকে পাঁচ লাখের
    সীমা উঠে গিয়ে সীমাহীন হয়ে যেত, আর পর্দাটা কেবল একটা সারি কম দেখাত।
--}}
<form method="POST" action="{{ route('approval.limit.destroy', $limit->id) }}"
      data-confirm="{{ __('approval::message.limit_remove_confirm') }}">
    @csrf
    @method('DELETE')

    <x-ui.button type="submit" tone="ghost">
        {{ __('core.action.delete') }}
    </x-ui.button>
</form>
