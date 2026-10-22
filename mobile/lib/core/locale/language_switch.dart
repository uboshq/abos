import 'package:flutter/foundation.dart';

/// ⛔ ভাষার সুইচ লুকানো — যতক্ষণ না সব বাংলা লেখা ARB-তে যায় (0.4.43)।
///
/// <p>[ready] হাতে উল্টানো হয় **শেষ দিনে**, যখন `test/l10n/still_hard_coded.json` খালি (সব ফাইল ০)।
/// `test/l10n/language_switch_ready_test.dart` দুটোকে বেঁধে রাখে: আগে উল্টালে লাল (অর্ধেক অ্যাপ বাংলায় থেকে যেত),
/// তালিকা খালি হয়েও না উল্টালে লাল (কাজ শেষ, সুইচ ভুলে থাকা)।
///
/// <p>ⓘ সুইচ বন্ধ থাকা পর্যন্ত মেনুতে "ভাষা" সারি নেই, আর বাছাই যা-ই থাকুক অ্যাপ বাংলায় ([LocaleChoices.effective])।
class LanguageSwitch {
  const LanguageSwitch._();

  static const bool ready = true;

  /// ⓘ পরীক্ষার পথ — সারিটা আজই বানিয়ে পরখ করা যায়, যাতে চালুর দিন কাজ করে
  @visibleForTesting
  static bool? debugShown;

  /// মেনুতে সারি দেখাবে কি না, আর বাছাই খাটবে কি না
  static bool get shown => debugShown ?? ready;
}
