import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/locale/language_switch.dart';
import 'package:abos_mobile/core/locale/locale_choice.dart';
import 'package:abos_mobile/core/navigation/top_bar/avatar_menu.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/theme_choice.dart';

import 'hard_coded_scan.dart';

/// ⛔ ভাষার সুইচ লুকানো থাকে যতক্ষণ হাতে-লেখা বাংলার তালিকা খালি না হয় — আর খালি হলেই চালু (0.4.43)।
///
/// <p>আগে উল্টালে লাল (অর্ধেক অ্যাপ বাংলায় থেকে যেত ইংরেজি বাছার পরেও); তালিকা খালি হয়েও না উল্টালে লাল (কাজ শেষ, সুইচ
/// ভোলা)। ⭐ সারিটা আজই বানানো আর পরখ করা — [LanguageSwitch.debugShown] দিয়ে — যাতে চালুর দিন কাজ করে।
void main() {
  setUp(() {
    ThemeChoices.instance = ThemeChoices(read: () async => null, write: (_) async {});
    LocaleChoices.instance = LocaleChoices(read: () async => null, write: (_) async {}, device: () => const Locale('en', 'US'));
    LocaleChoices.onChanged = null;
    LanguageSwitch.debugShown = null;
    AppLocale.current = AppLocale.locale;
  });
  tearDown(() {
    LanguageSwitch.debugShown = null;
    AppLocale.current = AppLocale.locale;
    LocaleChoices.instance = LocaleChoices();
  });

  // ⓘ ০.৪.৪৪ (fe, ২২ অক্টোবর ২০২৬): তালিকা খালি, তবু সুইচ বন্ধ — লাইভ সার্ভার এখনো ভাষা রাখতে পারে না, তাই ইংরেজি অ্যাপ
  // মিশ্র হত; সার্ভারের অংশ লাইভ হলে পরের সংস্করণে চালু। তাই দাবি দুই ভাগে: খালি তালিকা ছাড়া ready নয়, আর তালিকা খালিই থাকে।
  test('ready may be true only when nothing is hard-coded', () {
    final list = readList();
    final empty = list.values.every((n) => n == 0);
    if (LanguageSwitch.ready) {
      expect(empty, isTrue,
          reason: '⛔ এখনো ${list.values.fold<int>(0, (a, b) => a + b)}টা বাংলা লেখা হাতে — সুইচ চালু করা যাবে না');
    }
  });

  test('nothing is hard-coded: the list is empty', () {
    expect(readList().values.every((n) => n == 0), isTrue, reason: '⛔ হাতে-লেখা বাংলার তালিকা আবার ভরল');
  });

  test('the row shows exactly when ready is true (unless a test forces it)', () {
    LanguageSwitch.debugShown = null;
    expect(LanguageSwitch.shown, LanguageSwitch.ready);
  });

  /// ছবির মেনু, অ্যাপের ভাষা যেমন [appLocaleProvider] বলে
  Widget app() => ProviderScope(
        child: Consumer(
          builder: (context, ref, _) => MaterialApp(
            theme: AppTheme.light(),
            locale: ref.watch(appLocaleProvider),
            supportedLocales: AppLocale.supported,
            localizationsDelegates: AppLocale.delegates,
            home: const Scaffold(body: SingleChildScrollView(child: AvatarMenu(name: 'আব্দুর রহিম', branch: 'ময়মনসিংহ'))),
          ),
        ),
      );

  testWidgets('hidden: no language row, and an English choice on an English phone still reads Bangla', (tester) async {
    // ⓘ ০.৪.৪৫ থেকে ready চালু — লুকানো পথটা (ফেরত নেওয়ার দিনের জন্য) জোর করে লুকিয়ে পরখ
    LanguageSwitch.debugShown = false;
    await LocaleChoices.instance.choose(LocaleChoice.en);
    await tester.pumpWidget(app());
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('avatar-language')), findsNothing, reason: '⛔ অর্ধেক-অনুবাদের অ্যাপে ভাষার সারি');
    expect(AppLocale.current, AppLocale.locale);
    expect(find.text('বের হওয়া'), findsOneWidget);
  });

  // ⭐ ০.৪.৪৫ (fe, ২২ অক্টোবর ২০২৬): সার্ভার মানুষের ভাষা রাখে (`PATCH /me/locale`, লাইভ cfec6c2f4) আর তালিকা খালি — তাই
  // সুইচ চালু, আর অনুমোদিত নিয়মে প্রথম চালু ফোনের ভাষা মানে। ⛔ ভুলে বন্ধ রইলে এই দাবি লাল, শুধু ছবি নয়।
  testWidgets('switched on in 0.4.45: the menu offers the language, and an English phone opens in English', (tester) async {
    expect(LanguageSwitch.ready, isTrue, reason: '⛔ ০.৪.৪৫-এ ভাষার সুইচ বন্ধ রয়ে গেল');
    tester.view.physicalSize = const Size(412, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(app());
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('avatar-language')), findsOneWidget, reason: '⛔ মেনুতে ভাষার সারি নেই');
    expect(AppLocale.current, AppLocale.english, reason: 'ইংরেজি ফোনে প্রথম চালু ইংরেজি (অনুমোদিত পরিকল্পনা)');
  });

  testWidgets('switched on: the row offers phone · বাংলা · English, and a tap turns the whole app at once', (tester) async {
    LanguageSwitch.debugShown = true;
    tester.view.physicalSize = const Size(412, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    LocaleChoice? told;
    LocaleChoices.onChanged = (c) async => told = c;
    await LocaleChoices.instance.choose(LocaleChoice.bn);

    await tester.pumpWidget(app());
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('avatar-language')), findsOneWidget);
    for (final c in LocaleChoice.values) {
      expect(find.byKey(ValueKey('language-${c.name}')), findsOneWidget);
    }
    expect(find.text('বাংলা'), findsOneWidget);
    expect(find.text('English'), findsOneWidget);
    expect(find.text('বের হওয়া'), findsOneWidget);
    expect(tester.getSize(find.byKey(const ValueKey('me-language'))).height, greaterThanOrEqualTo(48));

    await tester.tap(find.byKey(const ValueKey('language-en')));
    await tester.pumpAndSettle();
    expect(LocaleChoices.instance.current.value, LocaleChoice.en);
    expect(told, LocaleChoice.en, reason: '⛔ উইজেট আর সার্ভার জানল না');
    expect(AppLocale.current, AppLocale.english);
    expect(find.text('Sign out'), findsOneWidget, reason: '⛔ বাছাইয়ের পরেও মেনু বাংলায় — অ্যাপ নতুন করে আঁকা হয়নি');
    expect(find.text('Language'), findsOneWidget);
    expect(find.text('Settings · Version ${_version(tester)}'), findsOneWidget);
    final material = MaterialLocalizations.of(tester.element(find.byType(AvatarMenu)));
    expect(material.okButtonLabel, 'OK', reason: 'Material-এর নিজের লেখাও ইংরেজিতে');

    await tester.tap(find.byKey(const ValueKey('language-phone')));
    await tester.pumpAndSettle();
    expect(AppLocale.current, AppLocale.english, reason: 'ইংরেজি ফোনে "ফোনের মতো" = English');

    await tester.tap(find.byKey(const ValueKey('language-bn')));
    await tester.pumpAndSettle();
    expect(find.text('বের হওয়া'), findsOneWidget);
    expect(AppLocale.current, AppLocale.locale);
  });
}

String _version(WidgetTester tester) {
  final row = tester.widget<Text>(find.descendant(of: find.byKey(const ValueKey('avatar-settings')), matching: find.byType(Text)));
  return row.data!.split(' ').last;
}
