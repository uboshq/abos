/*
 * ⭐ ABOS-এর Web Push — ব্রাউজারের পটভূমিতে খবর দেখানো (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ২)।
 *
 * ⓘ কেবল দুইটা কাজ: পুশ এলে শিরোনাম আর ছোট লেখা দেখানো, আর চাপলে ABOS-এর খবরটা খোলা (খোলার আগে সার্ভার আবার দেখে
 * কাগজটা নাগালে কি না)। কোনো ক্যাশ নেই, কোনো অফলাইন কাজ নেই।
 */
self.addEventListener('push', (event) => {
    let data = {}

    try {
        data = event.data ? event.data.json() : {}
    } catch (e) {
        data = {}
    }

    event.waitUntil(self.registration.showNotification(data.title || 'ABOS', {
        body: data.body || '',
        data: { url: data.url || '/' },
        icon: '/favicon.ico',
    }))
})

self.addEventListener('notificationclick', (event) => {
    event.notification.close()

    const url = (event.notification.data && event.notification.data.url) || '/'

    // ⛔ কেবল নিজের সাইটের ঠিকানা — বাইরের লিংক খোলা নয়
    if (new URL(url, self.location.origin).origin !== self.location.origin) return

    event.waitUntil(self.clients.openWindow(url))
})
