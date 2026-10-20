import 'package:intl/intl.dart';

/// Money and quantity as they actually arrive over sync: decimal **strings**,
/// never numbers.
///
/// <p>Every money and quantity field in the payloads is a bcmath value cast
/// with PHP's `(string)` — `salePrice`, `purchasePrice`, `creditLimit`,
/// `outstanding`, an order's `total`, and all five stock figures. See
/// `app/Modules/Inventory/Sync/ProductSync.php`'s `'salePrice' => (string)
/// $product->sale_price` for the shape of every one of them.
///
/// <p><b>Why they are strings on the wire and must stay strings here.</b> A
/// rate travels back to the server on a queued order, and the server's price
/// tolerance rule ([[PricingRule]], per `SalesOrderSync::apply()`) measures
/// what the phone sends against today's price. A figure that has been through
/// a double and been re-rendered is no longer the figure the catalogue showed
/// the rep — so [raw] passes the original characters through untouched, and
/// [value] exists only for arithmetic and display.
///
/// <p>Reading one of these with `as num?` — which is what the catalogue screen
/// did before this file existed — does not throw. It yields null, and a null
/// price simply does not draw. That is how the sale price came to be missing
/// from the product screen altogether rather than visibly wrong on it.
class Money {
  const Money._();

  /// ⭐ পরিমাণ — লাখ-কোটির ভাগে (১২,৩৪,৫৬৭), দশমিক যতটা আছে (চার ঘর পর্যন্ত; ০.১২৫ কেজি থাকে ০.১২৫)
  static final NumberFormat _quantity = NumberFormat('#,##,##0.####', 'en_IN');

  /// ⭐ টাকা — লাখ-কোটির ভাগে আর সবসময় দুই দশমিক (১২,৩৪,৫৬৭.৫০) — বাংলাদেশের হিসাবের ধারা (পূর্ণ নিরীক্ষা §৯ ⚠️৬,
  /// ১৮ অক্টোবর ২০২৬)। ⛔ আগে `#,##0.##`: ১২,৩৪,৫৬৭ দেখাত ১,২৩৪,৫৬৭ আর ১,২৫০.৫০ দেখাত ১,২৫০.৫।
  static final NumberFormat _money = NumberFormat('#,##,##0.00', 'en_IN');

  /// The value exactly as it arrived, or null when the key is absent.
  ///
  /// Absent is a real answer, not a failure: `purchasePrice` is omitted
  /// entirely for anyone without `inventory.cost.view` (docs/Contract §৩ rule
  /// ঙ) — never sent as null or masked — and a screen's job is to draw no
  /// cost line at all in that case.
  static String? raw(dynamic value) {
    if (value == null) return null;
    final text = value.toString().trim();
    return text.isEmpty ? null : text;
  }

  /// For arithmetic and comparison only — never for anything sent back.
  static double? value(dynamic value) {
    final text = raw(value);
    return text == null ? null : double.tryParse(text);
  }

  /// Same, with a zero for absent — for a figure whose absence genuinely
  /// means nothing is there (a stock count), not one whose absence means
  /// "you may not see this" (a cost price).
  static double valueOrZero(dynamic value) => Money.value(value) ?? 0;

  /// পরিমাণ — `1234567.5000` → `12,34,567.5`. ⓘ দশমিক যতটা আছে (চার ঘর পর্যন্ত), কারণ কেজি-লিটারের ভগ্নাংশ কাটা চলে না।
  /// টাকার জন্য [amount]/[taka]।
  static String plain(dynamic value) {
    final parsed = Money.value(value);
    if (parsed == null) return raw(value) ?? '—';
    return _quantity.format(parsed);
  }

  /// টাকা — `1234567.5` → `12,34,567.50`; লেখা পড়া না গেলে যেমন এসেছে
  static String amount(dynamic value) {
    final parsed = Money.value(value);
    if (parsed == null) return raw(value) ?? '—';
    return _money.format(parsed);
  }

  /// `1234567.5000` → `৳12,34,567.50` — ⛔ প্রতিটা মোটের পট্টি এটাই নেয়, সার্ভারের কাঁচা লেখা নয়
  static String taka(dynamic value) => '৳${amount(value)}';

  /// দর বা হার — অন্তত দুই ঘর, দরকারে চার (`40` → `40.00`, `41.6667` থাকে) — সার্ভারের `decimal:4` না কেটে
  /// (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)
  static String rate(double value) {
    final four = value.toStringAsFixed(4);
    if (four.endsWith('00')) return value.toStringAsFixed(2);
    if (four.endsWith('0')) return value.toStringAsFixed(3);
    return four;
  }

  /// ⭐ মানুষের লেখা অঙ্ক — বাংলা অঙ্ক (১,২৫০) আর কমা (1,250) সহ (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)।
  ///
  /// <p>খালি ঘর `0` (কিছু লেখা হয়নি), আর লেখা আছে অথচ পড়া যায় না হলে `null` — ⛔ আগে `double.tryParse("1,250")` চুপচাপ
  /// শূন্য হত, আর টাকার সারিটা কোনো বার্তা ছাড়াই বাদ পড়ত: নগদ নেওয়া বিল পুরো বাকিতে যেত।
  static double? typed(String text) {
    final clean = typedText(text);
    if (clean.isEmpty) return 0;
    return double.tryParse(clean);
  }

  /// ⭐ পর্দা-পাঠকের (TalkBack) জন্য টাকা, মুখে বলার মতো: `1234567.5` → "১২ লাখ ৩৪ হাজার ৫৬৭ টাকা ৫০ পয়সা"
  /// (ec, অ্যাপ এন্টারপ্রাইজ মান, ১৯ অক্টোবর ২০২৬)।
  ///
  /// <p>⛔ পর্দার "৳12,34,567.50" যন্ত্রের গলায় কমায় কমায় ভেঙে আলাদা সংখ্যা হয়ে যায় ("বারো, চৌত্রিশ, …"), আর "৳" চিহ্নটা
  /// কেউ পড়ে, কেউ পড়ে না। লাখ-হাজারের ভাগে বললে বাংলাদেশের কানে একটাই অঙ্ক। পড়া না গেলে null।
  static String? spoken(dynamic value) {
    final parsed = Money.value(value);
    if (parsed == null) return null;
    final paisaTotal = (parsed.abs() * 100).round();
    var taka = paisaTotal ~/ 100;
    final paisa = paisaTotal % 100;
    final parts = <String>[];
    final crore = taka ~/ 10000000;
    taka %= 10000000;
    if (crore > 0) parts.add('${_bnDigits(crore)} কোটি');
    final lakh = taka ~/ 100000;
    taka %= 100000;
    if (lakh > 0) parts.add('${_bnDigits(lakh)} লাখ');
    final thousand = taka ~/ 1000;
    taka %= 1000;
    if (thousand > 0) parts.add('${_bnDigits(thousand)} হাজার');
    if (taka > 0) parts.add(_bnDigits(taka));
    final words = [
      if (parsed < 0 && paisaTotal > 0) 'ঋণাত্মক',
      parts.isEmpty ? '০' : parts.join(' '),
      'টাকা',
      if (paisa > 0) '${_bnDigits(paisa)} পয়সা',
    ];
    return words.join(' ');
  }

  /// লেখার ভিতরের প্রতিটা "৳…" অঙ্ক [spoken] রূপে — "বকেয়া ৳8,125.50" → "বকেয়া ৮ হাজার ১২৫ টাকা ৫০ পয়সা"।
  /// ⓘ পর্দার লেখা বদলায় না; এটা কেবল `semanticsLabel`-এর জন্য।
  static String speakable(String text) => text.replaceAllMapped(
        RegExp(r'([-−]?)৳\s*([-−]?)([0-9০-৯][0-9০-৯,]*(?:\.[0-9০-৯]+)?)'),
        (m) {
          final negative = m[1]!.isNotEmpty || m[2]!.isNotEmpty;
          final said = spoken('${negative ? '-' : ''}${typedText(m[3]!)}');
          return said ?? m[0]!;
        },
      );

  static String _bnDigits(int n) {
    const bangla = '০১২৩৪৫৬৭৮৯';
    return n.toString().split('').map((d) => bangla[int.parse(d)]).join();
  }

  /// একই লেখা পাঠানোর রূপে — বাংলা অঙ্ক ইংরেজিতে, কমা আর ফাঁকা বাদ
  static String typedText(String text) {
    const bangla = '০১২৩৪৫৬৭৮৯';
    final out = StringBuffer();
    for (final ch in text.trim().split('')) {
      final i = bangla.indexOf(ch);
      if (i >= 0) {
        out.write(i);
      } else if (ch != ',' && ch != ' ') {
        out.write(ch);
      }
    }
    return out.toString();
  }
}
