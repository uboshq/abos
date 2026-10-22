import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/launcher_widgets/hub/widget_hub.dart';
import 'package:abos_mobile/core/launcher_widgets/hub/widget_summary_api.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/locale/language_switch.dart';
import 'package:abos_mobile/core/locale/locale_choice.dart';
import 'package:abos_mobile/core/privacy/phone_privacy.dart';

import '../support/fake_secure_storage.dart';

/// ⭐ হোম-স্ক্রিনের উইজেট কোন ভাষায় — অ্যাপ `widget_locale` ([WidgetHub.localeKey]) লেখে "bn" বা "en", আর Kotlin-এর
/// `WidgetWords.english()` কেবল "en"-এ ইংরেজি পড়ে; কিছু না থাকলে (অ্যাপ এখনো চলেনি) বাংলা (fe-র শর্ত, ২২ অক্টোবর ২০২৬)।
/// ⓘ সুইচ চালু ধরে ([LanguageSwitch.debugShown]; ০.৪.৪৪-এ লুকানো) — বাছাই না থাকলে "ফোনের মতো": বাংলা ফোনে বাংলা।
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  final saved = <String, String?>{};
  setUp(() {
    FakeSecureStorage.install();
    WidgetHub.resetForTest();
    saved.clear();
    WidgetHub.save = (id, value) async => saved[id] = value;
    WidgetHub.setEnabled = (key, on) async {};
    WidgetHub.redraw = (key) async {};
    WidgetHub.refreshAll = () async {};
    WidgetHub.kinds = () => const [];
    WidgetSummaryApi.transport = (etag) async =>
        const WidgetSummaryReply(200, etag: '"e1"', body: {'asOf': '2026-10-20T08:30:00Z', 'widgets': {}});
    PhonePrivacy.amountsReader = () async => true;
    AppLocale.current = AppLocale.locale;
    // ⓘ সুইচ ০.৪.৪৪-এ লুকানো (fe) — উইজেটের পথটা সুইচ চালুর দিনের মতো পরখ করা
    LanguageSwitch.debugShown = true;
  });
  tearDown(() {
    WidgetHub.resetForTest();
    LanguageSwitch.debugShown = null;
    LocaleChoices.instance = LocaleChoices();
    LocaleChoices.onChanged = null;
    AppLocale.current = AppLocale.locale;
  });

  /// ফোনের ভাণ্ডার — লেখা থাকে, পরের পড়ায় ফেরে (পেছনের isolate-এর মতো উইজেট প্রতিবার ভাণ্ডার থেকে পড়ে)
  LocaleChoices phone(String? stored, String device) {
    var store = stored;
    return LocaleChoices(read: () async => store, write: (v) async => store = v, device: () => Locale(device));
  }

  test('the key is the one Kotlin reads', () {
    expect(WidgetHub.localeKey, 'widget_locale');
  });

  test('a fresh install writes nothing until the app first refreshes the widgets', () async {
    LocaleChoices.instance = phone(null, 'bn');
    await LocaleChoices.instance.load();
    expect(saved.containsKey(WidgetHub.localeKey), isFalse, reason: 'কিছু না থাকলে Kotlin বাংলা পড়ে');
  });

  test('a fresh install on a Bangla phone, nothing chosen: the widgets get "bn"', () async {
    LocaleChoices.instance = phone(null, 'bn');
    await WidgetHub.refresh();
    expect(saved[WidgetHub.localeKey], 'bn');
  });

  test('Bangla chosen on an English phone: "bn"', () async {
    LocaleChoices.instance = phone('bn', 'en');
    await WidgetHub.refresh();
    expect(saved[WidgetHub.localeKey], 'bn');
  });

  test('switching the app language writes it at once: English → "en", back to Bangla → "bn"', () async {
    LocaleChoices.instance = phone('bn', 'bn');
    await LocaleChoices.instance.load();
    LocaleChoices.onChanged = (_) => WidgetHub.refresh();

    await LocaleChoices.instance.choose(LocaleChoice.en);
    expect(saved[WidgetHub.localeKey], 'en', reason: '⛔ ইংরেজি বাছার পরেও উইজেট বাংলায়');

    await LocaleChoices.instance.choose(LocaleChoice.bn);
    expect(saved[WidgetHub.localeKey], 'bn');
  });
}
