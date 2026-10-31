/*
 * ⭐ জীবন্ত ABOS লোগো — দশটা নড়াচড়া একটার পর একটা, এলোমেলো ক্রমে, মাঝে বিরতি ছাড়া (মালিক, ১ নভেম্বর ২০২৬)।
 *
 * ⓘ প্রতিটা নড়াচড়া ২.৮ সেকেন্ড আর স্থির লোগোয় শুরু-শেষ ([[live-logo.css]]), তাই এখানে কেবল পরেরটা বাছা: আগেরটা বাদে
 *   বাকি নয়টার যেকোনো একটা। `data-move` সরিয়ে আবার বসালে ব্রাউজার নড়াচড়া নতুন করে শুরু করে।
 * ⓘ লোড হওয়ার পর্দা: অন্য পাতায় যাওয়ার লিংক বা ফর্ম জমার ৩০০ms পরেও পাতা না বদলালে দেখায় — দ্রুত পাতায় ঝলক দেয় না।
 *   ফিরে এলে (ব্রাউজারের "পেছনে") বা ১৫ সেকেন্ডেও কিছু না হলে নিজেই সরে যায়, তাই ডাউনলোডের লিংকে আটকে থাকে না।
 */
export const MOVES = ['rise', 'type', 'wave', 'roll', 'shine', 'draw', 'hop', 'beat', 'line', 'flip'];
export const MOVE_MS = 2800;

export function nextMove(previous, random = Math.random) {
    const choices = MOVES.filter((m) => m !== previous);

    return choices[Math.floor(random() * choices.length)];
}

export function startLiveLogo(el) {
    if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
        return;
    }
    let move = null;
    const step = () => {
        move = nextMove(move);
        el.removeAttribute('data-move');
        void el.offsetWidth;
        el.setAttribute('data-move', move);
    };
    step();
    setInterval(step, MOVE_MS);
}

export function startLoadingScreen(doc = document, win = window) {
    const screen = doc.querySelector('[data-abos-loading]');
    if (!screen) {
        return;
    }
    let timer = null;
    let safety = null;
    const hide = () => {
        clearTimeout(timer);
        clearTimeout(safety);
        screen.removeAttribute('data-on');
    };
    const soon = () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            screen.setAttribute('data-on', '');
            safety = setTimeout(hide, 15000);
        }, 300);
    };
    doc.addEventListener('click', (e) => {
        const a = e.target.closest?.('a[href]');
        if (!a || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        if (a.target && a.target !== '_self') return;
        if (a.hasAttribute('download')) return;
        const url = new URL(a.href, win.location.href);
        if (url.origin !== win.location.origin) return;
        if (url.pathname === win.location.pathname && url.search === win.location.search && url.hash) return;
        soon();
    });
    doc.addEventListener('submit', (e) => {
        if (!e.defaultPrevented && !e.target.hasAttribute('data-no-loading')) soon();
    });
    win.addEventListener('pageshow', hide);
}

export function bootLiveLogos(doc = document) {
    doc.querySelectorAll('[data-abos-live]').forEach(startLiveLogo);
    startLoadingScreen(doc);
}
