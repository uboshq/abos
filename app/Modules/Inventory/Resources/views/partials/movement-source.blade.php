{{-- চলাচলটা কোন ডকুমেন্ট থেকে এল — নিয়ম ১।

     এখানে নিজে কোনো রুট বাছা হয় না; x-ui.drill জানে কোন source_type
     কোথায় নিয়ে যায়। নতুন ডকুমেন্ট টাইপ যোগ হলে এই ফাইলটা ছুঁতে হবে না। --}}
<x-ui.drill :source="$movement->source_type" :id="$movement->source_id">
    {{ $movement->document_no ?? $movement->source_type }}
</x-ui.drill>
