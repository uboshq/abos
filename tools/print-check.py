"""ছাপার পাতা সত্যিই ছেপে মাপা — আর মাপার আগে যন্ত্রটাকেই যাচাই করা।

⛔ কেন denominator-টা আগে
────────────────────────────────────────────────────────────────────
২২ সেপ্টেম্বর ২০২৬-এ দুইজন আলাদা সেশন আলাদা যন্ত্রে ছাপা মেপেছে, আর
দুইজনেরই প্রথম মাপে ছাপার CSS চলেনি। ⚠️ সংখ্যাগুলো দেখতে যুক্তিসঙ্গত
ছিল — দশ পাতা, মেনু ছাপা হচ্ছে — আর একজন প্রায় ওটা অ্যাপের দোষ বলে
রিপোর্ট করে ফেলেছিল।

⭐ তাই এখানে প্রথম কাজ একটা **জানা-উত্তরের প্রশ্ন**: পাশের মেনুটা
কাগজে থাকার কথা **নয়**। থাকলে ছাপার CSS চলেনি, আর বাকি প্রতিটা
সংখ্যা ফেলে দিতে হবে।

ব্যবহার
────────────────────────────────────────────────────────────────────
    php tools/print-check.php ledger=/suppliers/1?ledger=asc&print=1
    php -S 127.0.0.1:8123 -t public
    python tools/print-check.py ledger
"""

import os
import subprocess
import sys

import fitz

CHROME = r"C:\Program Files\Google\Chrome\Application\chrome.exe"
ORIGIN = "http://127.0.0.1:8123"

# ⓘ পর্দার যে লেখাগুলো কাগজে থাকার কথা নয় — skip-link আর মেনুর মাথা।
CHROME_ONLY = ["\u09b8\u09b0\u09be\u09b8\u09b0\u09bf \u0995\u09be\u099c\u09c7\u09b0 \u0985\u0982\u09b6\u09c7 \u09af\u09be\u09a8"]


def render(name, out_dir):
    pdf = os.path.join(out_dir, name + ".pdf")

    if os.path.exists(pdf):
        os.remove(pdf)

    subprocess.run(
        [
            CHROME, "--headless", "--disable-gpu", "--no-pdf-header-footer",
            "--print-to-pdf=" + pdf, "--virtual-time-budget=8000",
            "%s/__printcheck/%s.html" % (ORIGIN, name),
        ],
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False,
    )

    if not os.path.exists(pdf):
        sys.exit("Chrome কিছুই বানায়নি — `php -S 127.0.0.1:8123 -t public` চলছে তো?")

    return pdf


def main():
    if len(sys.argv) < 2:
        sys.exit("ব্যবহার: python tools/print-check.py <নাম> [...]")

    out_dir = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "storage", "app", "printcheck")
    os.makedirs(out_dir, exist_ok=True)

    for name in sys.argv[1:]:
        pdf = render(name, out_dir)
        doc = fitz.open(pdf)
        whole = "".join(page.get_text() for page in doc)

        print("\n=== %s  পাতা=%d ===" % (name, doc.page_count))

        # ⛔ যন্ত্রটা আগে — এই পরীক্ষাটা না দিলে নিচের কিছুই বিশ্বাসযোগ্য নয়।
        leaked = [word for word in CHROME_ONLY if word in whole]

        if leaked:
            print("  ⛔ ছাপার CSS চলেনি — কাগজে পর্দার মেনু পাওয়া গেছে: %r" % leaked[0])
            print("     প্রতিটা সংখ্যা ফেলে দিন। ঠিকানাগুলো আপেক্ষিক কি না দেখুন")
            print("     (tools/print-check.php সেটাই করে), আর CSS সত্যিই আসছে কি না।")
            doc.close()
            continue

        print("  ⓘ যন্ত্র ঠিক আছে — পর্দার মেনু কাগজে নেই।")

        for n, page in enumerate(doc, 1):
            words = page.get_text("words")

            if not words:
                print("  পাতা %d: ফাঁকা" % n)
                continue

            over = [w for w in words if w[2] > page.rect.width - 1]

            print(
                "  পাতা %d: %.0fx%.0f  লেখা x=%.0f..%.0f  ডানে উপচানো=%d"
                % (n, page.rect.width, page.rect.height,
                   min(w[0] for w in words), max(w[2] for w in words), len(over))
            )

            for w in over[:3]:
                print("      উপচেছে x1=%.0f  %r" % (w[2], w[4]))

        doc.close()
        print("  PDF: %s" % os.path.normpath(pdf))


if __name__ == "__main__":
    main()
