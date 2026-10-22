import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/profile/my_phones_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/bar_fonts.dart';
import '../support/main_screens.dart' show setScreenSize;
import 'profile_fakes.dart';
import 'profile_host.dart';

/// ⭐ এক পর্দা, দুই ভাষা (ভাষার সুইচ 0.4.43, a6-এর ভাগ): "আমার ফোনগুলো" বাংলায় আর ইংরেজিতে — আসল ফন্টে, ৩৬০dp, আলোয়।
/// ইংরেজিতে পর্দার সব লেখা ARB-এর ইংরেজি, সময় "10:30 AM" (stampText), কোনো বাংলা অক্ষর নেই; বাংলায় আগের মতোই।
void main() {
  setUpAll(loadBarFonts);
  tearDown(() => AppLocale.current = AppLocale.locale);

  for (final locale in AppLocale.supported) {
    final name = 'phones_360_light_${locale.languageCode}';
    testWidgets('$name looks as approved', (tester) async {
      // ⓘ উইজেট-গাছের বাইরের লেখা (শেষ ব্যবহারের লাইন) AppLocale.current মানে — অ্যাপে LocaleChoices এটা বসায়
      AppLocale.current = locale;
      setScreenSize(tester, const Size(360, 800));
      final c = profileContainer();
      addTearDown(c.dispose);
      final theme = withBarFonts(AppTheme.light());
      await tester.pumpWidget(RepaintBoundary(
        key: const ValueKey('shot'),
        child: UncontrolledProviderScope(
          container: c,
          child: MaterialApp(
            debugShowCheckedModeBanner: false,
            locale: locale,
            supportedLocales: AppLocale.supported,
            localizationsDelegates: AppLocale.delegates,
            theme: theme,
            home: MyPhonesScreen(
              api: FakeProfileApi(pages: [
                [
                  phone('aaaa1111', name: 'Android · 0.4.43 · #AAAA', current: true, used: DateTime(2026, 10, 20, 10, 30)),
                  phone('bbbb2222', name: 'iOS · 0.4.43 · #BBBB', used: DateTime(2026, 10, 18, 19, 45)),
                ],
              ]),
            ),
          ),
        ),
      ));
      await tester.pumpAndSettle();

      final english = locale.languageCode == 'en';
      expect(find.textContaining(english ? 'My phones' : 'আমার ফোনগুলো', findRichText: true), findsWidgets);
      expect(find.text(english ? 'This phone' : 'এই ফোন'), findsOneWidget);
      expect(find.text(english ? 'Last used: 20/10/2026 10:30 AM' : 'শেষ ব্যবহার: 20/10/2026 সকাল 10:30'), findsOneWidget);
      if (english) {
        final bengali = RegExp('[ঀ-৿]');
        final texts = tester.widgetList<RichText>(find.byType(RichText)).map((t) => t.text.toPlainText())
            .where(bengali.hasMatch)
            // ⓘ সার্ভারের ডেটা, ARB নয়: নামের প্রথম অক্ষর (নমুনার 'আল-আমিন শুভ'); ফোনের নাম নমুনায় ইংরেজিতে। ⛔ "সব শাখা" আর
            // ছাড় নয় — ওটা অ্যাপের নিজের কথা (homeAllBranches), ইংরেজিতে "All branches"
            .where((t) => t != 'আ');
        expect(texts, isEmpty, reason: 'Bengali left on the English screen: $texts');
      }
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('../goldens/profile/$name.png'));
    });
  }
}
