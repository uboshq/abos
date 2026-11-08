// ignore_for_file: prefer_const_constructors
import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/approvals/approval_inbox_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/bar_fonts.dart';

/// ⭐ অনুমোদনের তিন পর্দার ছবি — পরিকল্পনার পাতা ৬ (অনুমোদন ভাগ ৩, দফা ২১): ১. ইনবক্স, ২. কাগজ আর অনুমোদন, ৩. একসাথে
/// অনুমোদন। 412dp, আলো আর অন্ধকার, বাংলায়, আসল ফন্টে; আর ১৩০% লেখায় — কিছু কাটা পড়ে না, কিছু উপচে যায় না
/// (`takeException` খালি)। বদলালে ছবি খুলে নকশার পাশে রেখে দেখে `--update-goldens`।
const _rows = [
  {
    'id': 'p1', 'documentType': 'Payment', 'documentNo': 'PAY-0231', 'documentId': 'd1', 'amount': '120000.0000', 'currentLevel': 2,
    'requesterName': 'আল-আমিন', 'requestedAt': '2026-10-21T10:15:00+06:00', 'party': 'জাবেদ অ্যাগ্রো ফুড · প্রধান শাখা', 'status': 'pending',
    'deadline': {'text': 'দেরি 3 ঘণ্টা', 'tone': 'danger'}, 'step': {'at': 2, 'of': 2}, 'bulkable': false,
  },
  {
    'id': 'p2', 'documentType': 'SalesInvoice', 'documentNo': 'SO-0412', 'amount': '2500.0000', 'currentLevel': 1, 'action': 'confirm',
    'party': 'করিম স্টোর · ফুলপুর · ময়মনসিংহ', 'status': 'pending', 'deadline': {'text': 'আজ 5:00', 'tone': 'success'},
    'step': {'at': 1, 'of': 1}, 'bulkable': true,
  },
  {
    'id': 'p3', 'documentType': 'Voucher', 'documentNo': 'EXP-0118', 'amount': '8000.0000', 'currentLevel': 1,
    'party': 'বিদ্যুৎ বিল · ময়মনসিংহ', 'status': 'pending', 'deadline': {'text': 'কাল', 'tone': 'success'}, 'step': {'at': 1, 'of': 1},
    'bulkable': true,
  },
  {
    // ⓘ বেতন নয় — বেতন ফোনে আসেই না; চতুর্থ কার্ডটা দুই ধাপের ক্রয় আদেশ
    'id': 'p4', 'documentType': 'PurchaseOrder', 'documentNo': 'PO-0099', 'amount': '356000.0000', 'currentLevel': 1,
    'party': 'মেঘনা সিমেন্ট · প্রধান শাখা', 'status': 'pending', 'deadline': {'text': 'দেরি 1 দিন', 'tone': 'danger'},
    'step': {'at': 1, 'of': 2}, 'bulkable': true,
  },
];

InboxPage _page() => InboxPage.fromJson({
      'tab': 'waiting',
      'tabs': [
        {'key': 'waiting', 'label': 'অপেক্ষায়', 'count': 7},
        {'key': 'sent', 'label': 'আমার পাঠানো', 'count': 0},
        {'key': 'done', 'label': 'আমি অনুমোদন দিয়েছি', 'count': 12},
        {'key': 'behalf', 'label': 'অন্যের হয়ে', 'count': 0},
      ],
      'modules': [
        {'key': 'accounts', 'label': 'ভাউচার', 'count': 3},
        {'key': 'sales', 'label': 'বিক্রয়', 'count': 2},
        {'key': 'purchase', 'label': 'ক্রয়', 'count': 1},
      ],
      'allCount': 7,
      'lateCount': 2,
      'rows': _rows,
      'totals': {'pageCount': 4, 'pageAmount': '486500.0000', 'count': 7, 'amount': '521300.0000'},
    });

final _sheet = ApprovalSheet.fromJson({
  'columns': [
    {'key': 'bill', 'label': 'বিল'},
    {'key': 'amount', 'label': 'টাকা', 'numeric': true},
  ],
  'rows': [
    {'bill': 'PB-0057', 'amount': '80,000.00'},
    {'bill': 'PB-0061', 'amount': '40,000.00'},
    {'bill': 'AIT · VDS', 'amount': '−20,869.56'},
  ],
  'totals': {'amount': '99,130.44'},
  'summary': {
    'requesterName': 'আল-আমিন',
    'requestedAt': '2026-10-21T10:15:00+06:00',
    'amountWords': 'এক লক্ষ বিশ হাজার টাকা মাত্র',
    'deadline': {'text': 'দেরি', 'tone': 'danger'},
    'step': {'at': 2, 'of': 2},
  },
  'journey': [
    {
      'level': 1, 'state': 'approved', 'tookHours': 2,
      'by': [
        {'name': 'রহিম', 'decision': 'approved', 'label': 'অনুমোদিত', 'at': '2026-10-21T12:40:00+06:00', 'remarks': 'বিল মিলেছে'},
      ],
    },
    {'level': 2, 'state': 'now', 'by': []},
  ],
  'attachments': [
    {'id': 1, 'name': 'ব্যাংক-অ্যাডভাইস.pdf', 'size': '120 KB'},
    {'id': 2, 'name': 'বিল.jpg', 'size': '80 KB'},
  ],
});

final _preview = BulkPreview.fromJson({
  'sign': [
    {'id': 'p2', 'work': 'বিক্রয় ছাড়', 'documentNo': 'SO-0412 · করিম স্টোর', 'amount': '2500.0000'},
    {'id': 'p3', 'work': 'খরচ ভাউচার', 'documentNo': 'EXP-0118 · বিদ্যুৎ বিল', 'amount': '8000.0000'},
  ],
  'total': '10500.0000',
  'skip': [
    {'id': 'p4', 'work': 'ক্রয় আদেশ', 'documentNo': 'PO-0099', 'reason': 'এক ধাপের বেশি — একসাথে অনুমোদন কেবল এক-ধাপের কাগজে'},
  ],
});

Future<void> _open(WidgetTester tester, {required bool dark, double textScale = 1}) async {
  tester.view.physicalSize = Size(412, 915 * (textScale > 1 ? 1.3 : 1));
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(RepaintBoundary(
    key: const ValueKey('shot'),
    child: MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: withBarFonts(AppTheme.light()),
      darkTheme: withBarFonts(AppTheme.dark()),
      themeMode: dark ? ThemeMode.dark : ThemeMode.light,
      locale: AppLocale.locale,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      builder: (context, child) => MediaQuery.withClampedTextScaling(minScaleFactor: textScale, maxScaleFactor: textScale, child: child!),
      home: ApprovalInboxScreen(
        loadInbox: (_, __, ___, ____) async => _page(),
        loadSheet: (_) async => _sheet,
        bulkPreview: (_) async => _preview,
        bulkApprove: (_, __) async => BulkResult.fromJson({'done': 2}),
        approve: (_, __) async {},
        reject: (_, __) async {},
        sendBack: (_, __) async {},
        loadAttachment: (_, __) async => const [],
      ),
    ),
  ));
  await tester.pumpAndSettle();
}

void main() {
  setUpAll(loadBarFonts);

  for (final dark in [false, true]) {
    final mode = dark ? 'dark' : 'light';

    testWidgets('২১ · ১. ইনবক্স, 412dp, $mode', (tester) async {
      // ⓘ নমুনা v4-এর ছবি ১-এ কিছু বাছা নেই — ইনবক্স যেমন খোলে তেমন (বাছাই ছবি ৩-এ)
      await _open(tester, dark: dark);
      expect(tester.takeException(), isNull);
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/approvals21/1_inbox_412_$mode.png'));
    });

    testWidgets('২১ · ২. কাগজ আর অনুমোদন, 412dp, $mode', (tester) async {
      await _open(tester, dark: dark);
      await tester.tap(find.byKey(const Key('approval-open-p1')));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/approvals21/2_paper_412_$mode.png'));
    });

    testWidgets('২১ · ৩. একসাথে অনুমোদন, 412dp, $mode', (tester) async {
      await _open(tester, dark: dark);
      // ⓘ নমুনা v4-এর কার্ড উঁচু — তৃতীয় বাছার কার্ড পর্দার নিচে; বাছার আগে তাকে দেখা জায়গায় আনা
      for (final id in ['p2', 'p3', 'p4']) {
        await tester.ensureVisible(find.byKey(Key('approval-pick-$id')));
        await tester.pumpAndSettle();
        await tester.tap(find.byKey(Key('approval-pick-$id')));
      }
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('approvals-bulk')));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/approvals21/3_bulk_412_$mode.png'));
    });
  }

  // ⓘ দফা ২১ "কিছু কাটা পড়ে না" — বড় লেখাতেও (১৩০%) তিন পর্দার কোথাও উপচে পড়া নেই
  testWidgets('২১ · at 130% text nothing overflows on the three screens', (tester) async {
    await _open(tester, dark: false, textScale: 1.3);
    expect(tester.takeException(), isNull, reason: '⛔ দফা ২১: ইনবক্স ১৩০%-এ উপচে পড়ে');
    await tester.tap(find.byKey(const Key('approval-pick-p2')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('approvals-bulk')));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull, reason: '⛔ দফা ২১: একসাথে অনুমোদনের পপ-আপ ১৩০%-এ উপচে পড়ে');
    await tester.tap(find.byKey(const ValueKey('approvals-bulk-back')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('approval-open-p1')));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull, reason: '⛔ দফা ২১: কাগজের পাতা ১৩০%-এ উপচে পড়ে');
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/approvals21/2_paper_412_light_130.png'));
  });
}
