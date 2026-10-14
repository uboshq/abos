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

  static final NumberFormat _format = NumberFormat('#,##0.##', 'en');

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

  /// `1234.5000` → `1,234.5`. The four decimal places are the ledger's own
  /// precision, not a figure anybody reads aloud in a shop.
  static String plain(dynamic value) {
    final parsed = Money.value(value);
    if (parsed == null) return raw(value) ?? '—';
    return _format.format(parsed);
  }

  /// `1234.5000` → `৳1,234.5`.
  static String taka(dynamic value) => '৳${plain(value)}';

  /// ⭐ মানুষের লেখা অঙ্ক — বাংলা অঙ্ক (১,২৫০) আর কমা (1,250) সহ (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)।
  ///
  /// <p>খালি ঘর `0` (কিছু লেখা হয়নি), আর লেখা আছে অথচ পড়া যায় না হলে `null` — ⛔ আগে `double.tryParse("1,250")` চুপচাপ
  /// শূন্য হত, আর টাকার সারিটা কোনো বার্তা ছাড়াই বাদ পড়ত: নগদ নেওয়া বিল পুরো বাকিতে যেত।
  static double? typed(String text) {
    final clean = typedText(text);
    if (clean.isEmpty) return 0;
    return double.tryParse(clean);
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
