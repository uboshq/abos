import '../records/money.dart';

/// ⭐ "এই পাতা" — অ্যাপজুড়ে একটাই মানে: **পর্দায় এখন যে সারিগুলো দেখা যাচ্ছে**, তাদের গোনা আর যোগ (fe-র মোটের নিরীক্ষা,
/// ২৪ অক্টোবর ২০২৬; ০.৪.৪৮)।
///
/// <p>ⓘ খোঁজা বা ছাঁকনিতে লুকানো সারি পড়ে না; "আরও দেখুন"-এ আনা সব খেপের সারি পড়ে — শেষ খেপটা একা নয়।
/// ⛔ আগে চার মানে ছিল: আদেশ আর বকেয়ায় ছাঁকা সারি, ডিওতে সার্ভারের পাঠানো ৫০টা, আদায়ে এ পর্যন্ত আনা সব, আর কাগজের
/// পর্দায় কেবল শেষ ৩০টা (সার্ভারের `page_sum`)। এখন প্রতিটা তালিকা এই এক যোগ ডাকে, সারি যা দেখায় তাই দিয়ে।
///
/// <p>[counts] — কোন সারি যোগে পড়ে (যেমন প্রত্যাখ্যাত আর বাতিল আদেশ পড়ে না, [PaperTotal.counts]); সারিটা তবু গোনায় থাকে,
/// কারণ পর্দায় সেটা দেখা যাচ্ছে। যোগ পয়সায় — দশমিকের ভাসমান ভুল জমে না।
class ShownRows {
  const ShownRows._(this.count, this._paisa);

  /// দেখানো সারির গোনা — যোগে না-পড়া সারিসহ
  final int count;
  final int _paisa;

  /// যোগে পড়া দেখানো সারির টাকা
  double get sum => _paisa / 100;

  /// [shown] — পর্দার সারি, ঠিক যে ক্রমে আর যতগুলো আঁকা হয়; [amount] — সারির টাকা (সংখ্যা বা সার্ভারের দশমিক-লেখা;
  /// না থাকলে শূন্য)
  static ShownRows of<T>(Iterable<T> shown, Object? Function(T row) amount, {bool Function(T row)? counts}) {
    var n = 0, paisa = 0;
    for (final row in shown) {
      n++;
      if (counts != null && !counts(row)) continue;
      final value = amount(row);
      final taka = value is num ? value.toDouble() : Money.valueOrZero(value);
      paisa += (taka * 100).round();
    }
    return ShownRows._(n, paisa);
  }
}
