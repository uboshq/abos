{{--
    ⭐ কীবোর্ড আর বাছাইয়ের গোনা — ইনবক্স আর কাগজের পাতা, দুই জায়গাতেই।

    ── কীবোর্ড (অনুমোদন পর্দা ভাগ ২; দফা ২০ এগিয়ে আনা) ──────────────────────
    ← → আগের/পরের কাগজ · A অনুমোদন · S সংশোধনে ফেরত · R প্রত্যাখ্যান · Ctrl+K মন্তব্যের ঘর · Esc লেখার ঘর ছাড়ে।

    ⛔ A/S/R কেবল **খোলে** — অনুমোদনের বোতাম চাপা মানে ব্রাউজারের "নিশ্চিত?" ([[actions.js]]-এর `data-confirm`, যেটা যেকোনো জমায়
    আসে), S/R মানে পপ-আপ। ⚠️ একটা অক্ষরে পাঁচ লাখ টাকা পাস করা দ্রুততা নয়, দুর্ঘটনা — তাই প্রশ্ন এড়ানোর কোনো পথ এখানে নেই।
    ⓘ লেখার ঘরে কার্সার থাকলে কিছুই নয় — নাহলে মন্তব্যে "a" লিখতেই অনুমোদনের প্রশ্ন উঠত, আর বাঁ তীরে কাগজটাই বদলে যেত।

    ── বাছাইয়ের গোনা ──────────────────────────────────────────────────────
    "বাছাই Nটা · একসাথে অনুমোদন" — কয়টা বাছা হলো তা বোতামেই, আর শূন্য হলে বোতাম বন্ধ (মৃত বোতাম চাপলে খালি পাতা)।
--}}
<script @nonce>
    (() => {
        const typing = (el) => el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName))

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && typing(event.target)) {
                event.target.blur()

                return
            }

            if (event.ctrlKey || event.metaKey) {
                if (event.key?.toLowerCase?.() !== 'k') {
                    return
                }

                const box = document.querySelector('[data-approval-remarks]')

                if (box) {
                    event.preventDefault()
                    box.focus()
                }

                return
            }

            /* ⓘ পপ-আপ খোলা থাকলে পেছনের পাতার কীবোর্ড চুপ — নাহলে প্রত্যাখ্যানের পপ-আপে থেকে "A" চাপলে পেছনে অনুমোদনের প্রশ্ন উঠত */
            const dialogOpen = [...document.querySelectorAll('[role="dialog"][aria-modal="true"]')]
                .some((dialog) => getComputedStyle(dialog).display !== 'none')

            if (event.altKey || typing(event.target) || dialogOpen) {
                return
            }

            const arrow = { ArrowLeft: '[data-approval-prev]', ArrowRight: '[data-approval-next]' }[event.key]

            if (arrow) {
                const to = document.querySelector(arrow)

                if (to) {
                    event.preventDefault()
                    window.location.href = to.href
                }

                return
            }

            const button = document.querySelector('[data-approval-decide] [data-key="' + event.key.toLowerCase() + '"]')

            if (button && ['a', 's', 'r'].includes(event.key.toLowerCase())) {
                event.preventDefault()
                button.click()
            }
        })

        const bulk = document.querySelector('[data-bulk-button]')

        if (bulk) {
            const count = () => {
                const picked = document.querySelectorAll('[data-inbox-pick]:checked').length

                bulk.textContent = bulk.dataset.label.replace('__N__', String(picked))
                bulk.disabled = picked === 0
            }

            document.querySelectorAll('[data-inbox-pick]').forEach((box) => box.addEventListener('change', count))
            count()
        }
    })()
</script>
