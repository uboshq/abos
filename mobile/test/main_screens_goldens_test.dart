import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/accounts/voucher_api.dart';
import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/records/approval_record.dart';
import 'package:abos_mobile/core/records/notification_record.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/approvals/approval_inbox_screen.dart';
import 'package:abos_mobile/features/customers/due_list_screen.dart';
import 'package:abos_mobile/features/notifications/notifications_screen.dart';
import 'package:abos_mobile/features/vouchers/voucher_screens.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';
import 'support/main_screens.dart';

/// ⭐ প্রকাশের আগে মূল ১২টা পর্দার ছবি — আলো আর অন্ধকার দুটোতেই (fe, ২০ অক্টোবর ২০২৬; পরিকল্পনা ৪, অংশ ৭)।
///
/// <p>ছবি `test/goldens/screens/`-এ জমা। কারো বদলে পর্দার চেহারা নড়লে লাল হয়, তখন ছবি দেখে ঠিক করতে হয়:
/// ইচ্ছে করে বদলালে `--update-goldens`, নইলে বদলটা ফেরাও। ⓘ পরীক্ষার ফন্টে বাংলা অক্ষর চৌকো ঘর — এটা ভুল নয়;
/// ছবি ধরে বিন্যাস, রং আর কী কোথায় আছে।
void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
    await SyncEngine.instance.init();
  });

  tearDownAll(() async {
    appFrameInDark = false;
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  Widget themed(Widget home, bool dark) => MaterialApp(
        debugShowCheckedModeBanner: false,
        theme: AppTheme.light(),
        darkTheme: AppTheme.dark(),
        themeMode: dark ? ThemeMode.dark : ThemeMode.light,
        home: home,
      );

  const approval = <String, dynamic>{
    'id': 'a1', 'documentType': 'PurchaseBill', 'documentNo': 'PB-2610-0071', 'action': 'confirm', 'amount': '125000.0000',
    'currentLevel': 1, 'requestedAt': '2026-10-19T09:40:00+06:00', 'requesterName': 'করিম', 'summary': 'মেসার্স রহমান ট্রেডার্স',
  };

  final screens = <String, Widget Function(bool dark)>{
    'login': (_) => loginScreen(),
    'home': (_) => homeShell(),
    'customer_list': (_) => customerList(),
    'customer_detail': (_) => customerDetail(),
    'new_order': (_) => newOrder(),
    'collection': (_) => collect(),
    'ledger': (_) => customerLedger(),
    'printer_settings': (_) => printerSettings(),
    'approvals': (dark) => themed(
        ApprovalInboxScreen(
          loadPending: () async => const ApprovalPage(rows: [ApprovalRecord(approval)]),
          approve: (_, __) async {},
          reject: (_, __) async {},
        ),
        dark),
    'notifications': (dark) => themed(
        NotificationsScreen(
          fetch: () async => const NotificationPage(items: [
            NotificationRecord({'id': 'n1', 'title': 'বিল সই হয়েছে', 'body': 'INV-2610-0391 পাকা', 'read': false, 'url': '/sales/invoices/391'}),
            NotificationRecord({'id': 'n2', 'title': 'দাম বদল', 'body': '২১ অক্টোবর থেকে', 'read': true}),
          ], unread: 1),
          markRead: (_) async {},
          markAllRead: () async {},
        ),
        dark),
    'vouchers': (dark) => themed(
        VoucherListScreen(api: const _Vouchers(), newChangeId: () => 'k', branches: () async => const [], today: DateTime(2026, 10, 20), canCreate: true),
        dark),
    'due_list': (dark) => themed(DueListScreen(refresh: () async {}), dark),
  };

  for (final dark in [false, true]) {
    final mode = dark ? 'dark' : 'light';
    for (final screen in screens.entries) {
      testWidgets('${screen.key} ($mode) looks as it did', (tester) async {
        appFrameInDark = dark;
        appFrameAppTheme = true;
        addTearDown(() {
          appFrameInDark = false;
          appFrameAppTheme = false;
        });
        setScreenSize(tester, const Size(390, 844));
        await tester.runAsync(seedMainData);
        await tester.pumpWidget(RepaintBoundary(key: const ValueKey('shot'), child: screen.value(dark)));
        await tester.pumpAndSettle();
        if (screen.key == 'login') {
          // ⛔ লোগো আর পেছনের "A" ছবি — ফাইল থেকে পড়া অ্যাসিঙ্ক, আগে পড়া শেষের আগেই ছবি তোলা হত, তাই login_light-এ লোগো
          // নেই (ছবি-নিরীক্ষা, ২২ অক্টোবর ২০২৬)। পড়া শেষ করে তারপর ছবি
          final images = find.byType(Image).evaluate().toList();
          expect(images, hasLength(2), reason: 'লোগো আর পেছনের "A"');
          await tester.runAsync(() async {
            for (final e in images) {
              await precacheImage((e.widget as Image).image, e);
            }
          });
          await tester.pumpAndSettle();
          for (final e in images) {
            expect(tester.widget<RawImage>(find.descendant(of: find.byWidget(e.widget), matching: find.byType(RawImage))).image,
                isNotNull, reason: '⛔ ছবি আঁকা হয়নি: ${(e.widget as Image).image}');
          }
        }
        await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('goldens/screens/${screen.key}_$mode.png'));
      });
    }
  }
}

class _Vouchers implements VoucherApi {
  const _Vouchers();

  @override
  Future<VoucherListPage> list({String? type, bool awaiting = false, int page = 1, String? money, String? from, String? to, String? branch}) async =>
      const VoucherListPage(rows: [
        VoucherRow(id: 'v1', no: 'RV-2610-0142', type: 'receipt', state: 'awaiting', amount: 48500, stateLabel: 'সইয়ের অপেক্ষায়', party: 'রহিম ট্রেডার্স'),
        VoucherRow(id: 'v2', no: 'PV-2610-0057', type: 'payment', state: 'posted', amount: 125000, stateLabel: 'পাকা', party: 'মেসার্স রহমান ট্রেডার্স'),
        VoucherRow(id: 'v3', no: 'EV-2610-0031', type: 'expense', state: 'draft', amount: 3200, stateLabel: 'খসড়া', party: 'গাড়িভাড়া'),
      ], count: 37, total: 1842650);

  @override
  Future<VoucherSetup> setup(String type) => throw UnimplementedError();

  @override
  Future<VoucherPage> one(String id) => throw UnimplementedError();

  @override
  Future<PostAnswer> post(String id, {String? instrumentNo}) => throw UnimplementedError();

  @override
  Future<PushOutcome> send(VoucherDraft draft, String changeId) => throw UnimplementedError();
}
