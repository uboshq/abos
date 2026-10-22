import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/locale/language_switch.dart';
import 'package:abos_mobile/core/locale/locale_choice.dart';
import 'package:abos_mobile/core/locale/l10n.dart';

/// ⭐ ভাষার বাছাই — "ফোনের মতো" (শুরুতে), "বাংলা", "English"; ফোনে থাকে, আবার খুললেও থাকে (0.4.43; [ThemeChoices]-এর ছাঁচ)।
void main() {
  String? stored;
  var device = const Locale('bn', 'BD');
  LocaleChoices store() => LocaleChoices(read: () async => stored, write: (v) async => stored = v, device: () => device);

  setUp(() {
    stored = null;
    device = const Locale('bn', 'BD');
    LanguageSwitch.debugShown = true;
    LocaleChoices.onChanged = null;
  });
  tearDown(() {
    LanguageSwitch.debugShown = null;
    AppLocale.current = AppLocale.locale;
  });

  test('"phone" follows the phone: Bangla phone → bn, any other language → en', () {
    expect(LocaleChoices.resolve(LocaleChoice.phone, const Locale('bn')), AppLocale.locale);
    expect(LocaleChoices.resolve(LocaleChoice.phone, const Locale('bn', 'IN')), AppLocale.locale);
    expect(LocaleChoices.resolve(LocaleChoice.phone, const Locale('en', 'US')), AppLocale.english);
    expect(LocaleChoices.resolve(LocaleChoice.phone, const Locale('fr')), AppLocale.english);
    expect(LocaleChoices.resolve(LocaleChoice.bn, const Locale('fr')), AppLocale.locale);
    expect(LocaleChoices.resolve(LocaleChoice.en, const Locale('bn')), AppLocale.english);
  });

  test('it starts as the phone does; an unknown stored word is the phone too', () async {
    final s = store();
    await s.load();
    expect(s.current.value, LocaleChoice.phone);
    stored = 'klingon';
    await s.load();
    expect(s.current.value, LocaleChoice.phone);
  });

  test('a choice survives the app being closed and opened again, and AppLocale.current follows it', () async {
    await store().choose(LocaleChoice.en);
    expect(stored, 'en');
    expect(AppLocale.current, AppLocale.english);
    AppLocale.current = AppLocale.locale;

    final reopened = store();
    await reopened.load();
    expect(reopened.current.value, LocaleChoice.en, reason: '⛔ আবার খুললে বাছাই হারাল');
    expect(AppLocale.current, AppLocale.english, reason: '⛔ ApiClient আর সাহায্যকারীরা পুরনো ভাষা পড়ছে');
  });

  test('⛔ while the switch is hidden the app is Bangla whatever was chosen or the phone speaks', () async {
    LanguageSwitch.debugShown = null;
    device = const Locale('en', 'US');
    final s = store();
    await s.choose(LocaleChoice.en);
    expect(s.effective, AppLocale.locale);
    expect(AppLocale.current, AppLocale.locale);
  });

  testWidgets('context.l10n reads the app language, and falls back to AppLocale.current without a delegate', (tester) async {
    late String inside;
    await tester.pumpWidget(MaterialApp(
      locale: AppLocale.english,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      home: Builder(builder: (context) {
        inside = context.l10n.avatarMenuSignOut;
        return const SizedBox();
      }),
    ));
    expect(inside, 'Sign out');

    await tester.pumpWidget(MaterialApp(home: Builder(builder: (context) {
      inside = context.l10n.avatarMenuSignOut;
      return const SizedBox();
    })));
    expect(inside, 'বের হওয়া', reason: 'খালি পরীক্ষার অ্যাপ আজকের মতো বাংলায়');
  });

  test('plural and select say it right in both languages', () {
    final bn = lookupAppL10n(AppLocale.locale);
    final en = lookupAppL10n(AppLocale.english);
    expect(bn.commonPaperCount(0), 'কোনো কাগজ নেই');
    expect(bn.commonPaperCount(1), '1টা কাগজ');
    expect(bn.commonPaperCount(5), '5টা কাগজ');
    expect(en.commonPaperCount(0), 'No papers');
    expect(en.commonPaperCount(1), '1 paper');
    expect(en.commonPaperCount(5), '5 papers');
    expect(bn.commonPaperState('posted'), 'পোস্ট হয়েছে');
    expect(en.commonPaperState('posted'), 'Posted');
    expect(en.commonPaperState('weird'), 'Unknown');
  });

  test('the app itself listens to the choice and loads it before the first frame', () {
    final main = File('lib/main.dart').readAsStringSync();
    expect(main, contains('final locale = ref.watch(appLocaleProvider);'));
    expect(main, contains('locale: locale,'));
    expect(main, contains('supportedLocales: AppLocale.supported'));
    expect(main, contains('localizationsDelegates: AppLocale.delegates'));
    expect(main, contains('await LocaleChoices.instance.load();'));
    expect(main, contains('await saveLocale(AppLocale.code);'));
    expect(AppLocale.delegates, contains(AppL10n.delegate));
  });
}
