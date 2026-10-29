/**
 * ⭐ "ফোনের মতো" রং — যন্ত্রের আলো/অন্ধকার মেনে (ওয়েবের মান ধাপ ২, ২০ অক্টোবর ২০২৬; Material 3: রং ব্যবহারকারীর যন্ত্রের পছন্দ মানে)।
 *
 * ⓘ সার্ভার `<html data-theme="system">` বসায় যখন মানুষটা ছবির মেনুতে "ফোনের মতো" বেছেছেন; টোকেনগুলো কেবল `light` আর `dark`
 * চেনে (`tokens.css`-এর `:root[data-theme='dark']`)। তাই এখানে যন্ত্রের পছন্দ পড়ে আসল মানটা বসানো হয়, আর যন্ত্র বদলালে
 * (সন্ধ্যায় ফোন অন্ধকারে গেলে) সাথে সাথে বদলায়। ⓘ পছন্দটা `data-theme-choice`-এ থেকে যায়, যাতে মেনু ঠিকটা দেখায়।
 * ⛔ inline স্ক্রিপ্ট নয় — CSP; এটা বান্ডেলের অংশ।
 */
export function followTheDevice (root = document.documentElement, media = window.matchMedia?.('(prefers-color-scheme: dark)')) {
    if (! root || root.dataset.theme !== 'system') return null

    root.dataset.themeChoice = 'system'

    const apply = () => {
        root.dataset.theme = media?.matches ? 'dark' : 'light'
    }

    apply()
    media?.addEventListener?.('change', apply)

    return apply
}
