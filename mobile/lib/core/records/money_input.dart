import 'package:flutter/services.dart';

/// ⭐ টাকার ঘরে কেবল টাকা — অঙ্ক (ইংরেজি বা বাংলা), কমা, একটা দশমিক বিন্দু, বিন্দুর পরে দুই ঘর (a4, ২৩ অক্টোবর ২০২৬)।
///
/// <p>⛔ কেন: `numberWithOptions(decimal: true)`-এর কিবোর্ডে বিয়োগ চিহ্ন নেই, তবু পেস্ট করা "-0.51" ঢুকত — [Money.typedText]
/// '-' রাখে, ঘরটা ঋণাত্মক পড়ত। আদায়ের পর্দায় ঋণাত্মক ছাড়/রাউন্ডিং নীরবে বাদ পড়ত (পাঠানো হত না), অথচ বিলের বণ্টন সেই
/// ঋণাত্মক অঙ্ক ধরে কষা — আর সার্ভার (a6 5d6c6bac0, CollectionSync-এর `difference.amount` নিয়ম) ঋণাত্মক পার্থক্য পুশে ফেরায়,
/// মানুষ জানতেন কেবল জমার পরে। এখন চিহ্নটা ঘরেই ঢোকে না।
///
/// <p>ⓘ দুই রকম: চিহ্ন (-, −, +), টাকার চিহ্ন (৳ — পেস্ট করা "৳-5" থেকে "5"; fe, ২৩ অক্টোবর) আর ফাঁকা ঝরে যায়, বাকিটা থাকে ("-0.51" → "0.51" — সার্ভারের প্রিভিউও পার্থক্যের অঙ্কটাই
/// নেয়)। ⛔ অন্য সব ভুল লেখা — অক্ষর ("1e5"), দ্বিতীয় বিন্দু, বিন্দুর পরে দুইয়ের বেশি ঘর — পুরোটাই নেওয়া হয় না, ঘরে আগের
/// লেখা থাকে: "1e5" থেকে চুপচাপ "15" বা "12.345" থেকে "12.34" বানালে মানুষ যা লেখেননি সেই টাকা যেত।
/// কমা থাকে (বিন্দুর আগে), কারণ ঘর ছাড়লে পর্দা টাকার রূপে সাজায় ("45,000.00") আর তারপরের সম্পাদনাও চলতে হবে।
/// বাংলা অঙ্ক থাকে — [Money.typedText] পড়ে।
class MoneyInputFormatter extends TextInputFormatter {
  const MoneyInputFormatter();

  static const _bangla = '০১২৩৪৫৬৭৮৯'; // l10n-ok: the Bengali digit table, not text
  static final _dropped = RegExp(r'[-−+৳\s]'); // l10n-ok: the sign, taka-sign and space characters a money field drops, not text

  static bool _digit(String ch) => (ch.codeUnitAt(0) >= 0x30 && ch.codeUnitAt(0) <= 0x39) || _bangla.contains(ch);

  /// টাকার লেখা কি না — অঙ্ক আর কমা, তারপর ঐচ্ছিক বিন্দু আর দুই ঘর পর্যন্ত অঙ্ক (খালিও চলে)
  static bool isMoney(String text) {
    var point = false;
    var decimals = 0;
    for (final ch in text.split('')) {
      if (ch == '.') {
        if (point) return false;
        point = true;
      } else if (ch == ',') {
        if (point) return false;
      } else if (_digit(ch)) {
        if (point && ++decimals > 2) return false;
      } else {
        return false;
      }
    }
    return true;
  }

  @override
  TextEditingValue formatEditUpdate(TextEditingValue oldValue, TextEditingValue newValue) {
    final text = newValue.text.replaceAll(_dropped, '');
    if (!isMoney(text)) return oldValue;
    if (text == newValue.text) return newValue;
    final at = newValue.selection.isValid ? newValue.selection.extentOffset.clamp(0, newValue.text.length) : newValue.text.length;
    final offset = newValue.text.substring(0, at).replaceAll(_dropped, '').length;
    return TextEditingValue(text: text, selection: TextSelection.collapsed(offset: offset));
  }
}
