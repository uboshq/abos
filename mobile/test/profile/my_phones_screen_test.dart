import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/profile/profile_api.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/profile/my_phones_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../support/main_screens.dart' show setScreenSize;
import 'profile_fakes.dart';
import 'profile_host.dart';

/// ⭐ "আমার ফোনগুলো" (ছবির মেনু; অ্যাপ ০.৪.৪১, a6, ২০ অক্টোবর ২০২৬)।
///
/// <p>দাবি: প্রতিটা ফোন নাম আর "শেষ ব্যবহার: 20/10/2026 সকাল 10:00" (অ্যাপের তারিখের নিয়ম) নিয়ে, নিজেরটায় "এই ফোন"; পাতা
/// ধরে শেষ পর্যন্ত; "বের করে দিন" ফোনের নাম ধরে জিজ্ঞেস করে, ২০৪-এ সারি সরে; ⛔ এই ফোনটাই হলে অ্যাপ নিজেও বের হয়; ৪০৪-এ
/// তালিকা নতুন করে আর বাংলায় কারণ; পুরনো সার্ভারে "সার্ভার আপডেট হয়নি"; পর্দা-পাঠক ফোনের নাম বলে, বাংলায়; ৪৮dp, ২০০%, অন্ধকার।
void main() {
  Future<SignedIn> open(WidgetTester tester, FakeProfileApi api, {bool dark = false, double scale = 1}) async {
    final auth = SignedIn();
    final c = profileContainer(auth: auth);
    await tester.pumpWidget(profileHost(c, MyPhonesScreen(api: api), dark: dark, scale: scale));
    await tester.pumpAndSettle();
    return auth;
  }

  testWidgets('each phone shows its name and last use in the app\'s date form; only mine says "এই ফোন"', (tester) async {
    final api = FakeProfileApi(pages: [
      [
        phone('aaaa1111', current: true, used: DateTime(2026, 10, 20, 10)),
        phone('bbbb2222', used: DateTime(2026, 10, 19, 19, 45)),
      ]
    ]);
    await open(tester, api);
    expect(find.text('Android ফোন · 0.4.40 · #AAAA'), findsOneWidget);
    expect(find.text('শেষ ব্যবহার: 20/10/2026 সকাল 10:00'), findsOneWidget);
    expect(find.text('শেষ ব্যবহার: 19/10/2026 সন্ধ্যা 7:45'), findsOneWidget);
    expect(find.byKey(const ValueKey('phone-current')), findsOneWidget);
    expect(find.descendant(of: find.byKey(const ValueKey('phone-aaaa1111')), matching: find.text('এই ফোন')), findsOneWidget);
    expect(find.descendant(of: find.byKey(const ValueKey('phone-bbbb2222')), matching: find.text('এই ফোন')), findsNothing);
    expect(lastUsedLine(const MyPhone(id: 'cccc', name: 'x')), 'শেষ ব্যবহার: জানা নেই');
  });

  testWidgets('pages load one after another with "আরও দেখুন" until the server says there is no next page', (tester) async {
    final api = FakeProfileApi(pages: [
      [phone('p1aaaaaa'), phone('p1bbbbbb')],
      [phone('p2aaaaaa')],
      [phone('p3aaaaaa')],
    ]);
    await open(tester, api);
    expect(api.pagesAsked, [1]);
    expect(find.byKey(const ValueKey('phone-p2aaaaaa')), findsNothing);

    for (final next in ['p2aaaaaa', 'p3aaaaaa']) {
      await tester.scrollUntilVisible(find.byKey(const ValueKey('books-more')), 200);
      await tester.tap(find.byKey(const ValueKey('books-more')));
      await tester.pumpAndSettle();
      await tester.scrollUntilVisible(find.byKey(ValueKey('phone-$next')), 200);
      expect(find.byKey(ValueKey('phone-$next')), findsOneWidget);
    }
    expect(api.pagesAsked, [1, 2, 3], reason: '⛔ প্রথম পাতার পরে থেমে গেল');
    expect(find.byKey(const ValueKey('books-more')), findsNothing, reason: 'শেষ পাতা — আর "আরও দেখুন" নেই');
  });

  testWidgets('signing another phone out asks by its name, removes the row, and keeps me in', (tester) async {
    final api = FakeProfileApi();
    final auth = await open(tester, api);

    await tester.tap(find.byKey(const ValueKey('phone-out-bbbb2222')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('phone-out-confirm')), findsOneWidget);
    expect(find.textContaining('“Android ফোন · 0.4.40 · #BBBB” থেকে আপনার লগইন এখনই বন্ধ হবে'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('phone-out-no')));
    await tester.pumpAndSettle();
    expect(api.signedOut, isEmpty, reason: '"না" বললে কিছু হয় না');

    await tester.tap(find.byKey(const ValueKey('phone-out-bbbb2222')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('phone-out-yes')));
    await tester.pumpAndSettle();
    expect(api.signedOut, ['bbbb2222']);
    expect(find.byKey(const ValueKey('phone-bbbb2222')), findsNothing);
    expect(find.text('“Android ফোন · 0.4.40 · #BBBB” থেকে বের করে দেওয়া হলো।'), findsOneWidget);
    expect(auth.signedOutHere, 0, reason: 'অন্য ফোন — আমি এই ফোনে থাকি');
    expect(auth.state.status, AuthStatus.signedIn);
  });

  testWidgets('signing THIS phone out runs the app\'s own sign-out', (tester) async {
    final api = FakeProfileApi();
    final auth = await open(tester, api);
    await tester.tap(find.byKey(const ValueKey('phone-out-aaaa1111')));
    await tester.pumpAndSettle();
    expect(find.text('এই ফোন থেকেই বের হবেন?'), findsOneWidget);
    expect(find.textContaining('এটা আপনার হাতের এই ফোনটাই'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('phone-out-yes')));
    await tester.pumpAndSettle();
    expect(api.signedOut, ['aaaa1111']);
    expect(auth.signedOutHere, 1, reason: '⛔ সার্ভার টোকেন মুছেছে অথচ অ্যাপ ঢোকা রয়ে গেল');
    expect(auth.state.status, AuthStatus.signedOut);
  });

  testWidgets('a phone already gone (404) brings a fresh list and says why in Bengali', (tester) async {
    final api = FakeProfileApi(signOutAnswer: (_) async => false);
    await open(tester, api);
    api.pages = [
      [phone('aaaa1111', current: true)]
    ];
    await tester.tap(find.byKey(const ValueKey('phone-out-bbbb2222')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('phone-out-yes')));
    await tester.pumpAndSettle();
    expect(api.pagesAsked, [1, 1], reason: 'তালিকা আবার আনা');
    expect(find.text('“Android ফোন · 0.4.40 · #BBBB” আগেই বের হয়ে গেছে — তালিকা নতুন করে আনা হলো।'), findsOneWidget);
    expect(find.byKey(const ValueKey('phone-bbbb2222')), findsNothing);
  });

  testWidgets('an old server: "সার্ভার আপডেট হয়নি", no rows, no button', (tester) async {
    await open(tester, FakeProfileApi(doors: false));
    expect(find.byKey(const ValueKey('phones-server-old')), findsOneWidget);
    expect(find.text('সার্ভার আপডেট হয়নি'), findsOneWidget);
    expect(find.text('বের করে দিন'), findsNothing);
    expect(find.byKey(const ValueKey('phones-error')), findsNothing, reason: 'ভুল নয় — সার্ভার পুরনো');
  });

  testWidgets('pulling the list down asks the server again, even when it was empty', (tester) async {
    final api = FakeProfileApi(pages: [[]]);
    await open(tester, api);
    expect(find.text('কোনো ফোনে ঢোকা নেই'), findsOneWidget);
    expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget);
    await tester.fling(find.byKey(const ValueKey('phones-list')), const Offset(0, 400), 1200);
    await tester.pumpAndSettle();
    expect(api.pagesAsked, [1, 1]);
  });

  group('accessibility', () {
    for (final dark in [false, true]) {
      testWidgets('TalkBack names the phone on its row and its button, in Bengali; 48dp; contrast — ${dark ? 'dark' : 'light'}',
          (tester) async {
        final handle = tester.ensureSemantics();
        await open(tester, FakeProfileApi(), dark: dark);
        expect(find.bySemanticsLabel('Android ফোন · 0.4.40 · #AAAA, এই ফোন, শেষ ব্যবহার: 20/10/2026 সকাল 10:00'), findsOneWidget);
        expect(find.bySemanticsLabel('Android ফোন · 0.4.40 · #BBBB থেকে বের করে দিন'), findsOneWidget);
        expect(find.bySemanticsLabel('বের করে দিন'), findsNothing, reason: '⛔ পাঁচটা বোতাম এক রকম শোনায় — কোনটা কোন ফোনের?');
        expect(find.bySemanticsLabel(RegExp(r'^[A-Za-z ]+$')), findsNothing, reason: '⛔ ইংরেজি পর্দা-পাঠ');
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
        await expectLater(tester, meetsGuideline(textContrastGuideline));
        if (dark) expect(Theme.of(tester.element(find.byType(ListView).first)).brightness, Brightness.dark);
        handle.dispose();
      });
    }

    testWidgets('200% text at 320dp: nothing overflows, the button stays 48dp', (tester) async {
      setScreenSize(tester, const Size(320, 720));
      await open(tester, FakeProfileApi(), scale: 2);
      expect(tester.takeException(), isNull);
      expect(tester.getSize(find.byKey(const ValueKey('phone-out-aaaa1111'))).height, greaterThanOrEqualTo(48));
    });
  });
}
