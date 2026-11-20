import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/theme/glass.dart';
import 'package:abos_mobile/features/approvals/approval_inbox_screen.dart';
import 'package:abos_mobile/features/notices/notice_board_screen.dart';
import 'package:abos_mobile/features/profile/profile_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../profile/profile_fakes.dart';
import '../profile/profile_host.dart';
import '../support/bar_fonts.dart';
import '../support/fake_secure_storage.dart';
import '../support/hive_test_harness.dart';
import '../support/main_screens.dart';

/// ⭐ অ্যাপ 0.4.60 — বাকি পর্দা নতুন দুই রূপে (মালিকের খোলা কাজ "অ্যাপের বাকি পর্দা নতুন চেহারায় আনা", ২০ নভেম্বর ২০২৬):
/// গ্রাহকের তালিকা, অনুমোদনের ইনবক্স, নোটিশ, প্রোফাইল আর একটা ফর্ম (নতুন অর্ডার) — Liquid Glass (ঝাপসা 0, 40, 100) আর
/// Material 3 Expressive-এ। ⓘ ABOS-এর ছবি আগের টেস্টগুলোর — সেগুলো একটুও বদলায় না।
class _Notices implements NoticeBoardApi {
  @override
  Future<NoticePage> notices() async => const NoticePage(items: [
        NoticeItem(
          id: 'n-rule',
          no: 'NTC-0004',
          title: 'নতুন হাজিরার নিয়ম',
          summary: 'সকাল ৯টার মধ্যে',
          body: 'সবাইকে সকাল ৯টার মধ্যে হাজিরা দিতে হবে।',
          priority: 'important',
          publishedAt: '2026-10-12T09:00:00+06:00',
          ackRequired: true,
          ackDeadline: '2026-10-20T17:00:00+06:00',
          standing: 'unread',
          author: 'মালিক',
        ),
        NoticeItem(id: 'n-news', title: 'ঈদের ছুটি', body: 'ছুটি তিন দিন।', priority: 'normal', standing: 'read'),
      ], unread: 1);

  @override
  Future<void> markRead(String id) async {}

  @override
  Future<void> acknowledge(String id) async {}
}

InboxPage _inbox() => InboxPage.fromJson({
      'tab': 'waiting',
      'tabs': [
        {'key': 'waiting', 'label': 'অপেক্ষায়', 'count': 3},
        {'key': 'sent', 'label': 'আমার পাঠানো', 'count': 0},
        {'key': 'done', 'label': 'আমি অনুমোদন দিয়েছি', 'count': 12},
      ],
      'modules': [
        {'key': 'accounts', 'label': 'ভাউচার', 'count': 1},
        {'key': 'sales', 'label': 'বিক্রয়', 'count': 2},
      ],
      'allCount': 3,
      'lateCount': 1,
      'rows': [
        {
          'id': 'p1', 'documentType': 'Payment', 'documentNo': 'PAY-0231', 'documentId': 'd1', 'amount': '120000.0000',
          'currentLevel': 2, 'requesterName': 'আল-আমিন', 'requestedAt': '2026-10-21T10:15:00+06:00',
          'party': 'জাবেদ অ্যাগ্রো ফুড · প্রধান শাখা', 'status': 'pending',
          'deadline': {'text': 'দেরি 3 ঘণ্টা', 'tone': 'danger'}, 'step': {'at': 2, 'of': 2}, 'bulkable': false,
        },
        {
          'id': 'p2', 'documentType': 'SalesInvoice', 'documentNo': 'SO-0412', 'amount': '2500.0000', 'currentLevel': 1,
          'action': 'confirm', 'party': 'করিম স্টোর · ফুলপুর · ময়মনসিংহ', 'status': 'pending',
          'deadline': {'text': 'আজ 5:00', 'tone': 'success'}, 'step': {'at': 1, 'of': 1}, 'bulkable': true,
        },
        {
          'id': 'p3', 'documentType': 'Voucher', 'documentNo': 'EXP-0118', 'amount': '8000.0000', 'currentLevel': 1,
          'party': 'বিদ্যুৎ বিল · ময়মনসিংহ', 'status': 'pending', 'deadline': {'text': 'কাল', 'tone': 'success'},
          'step': {'at': 1, 'of': 1}, 'bulkable': true,
        },
      ],
      'totals': {'pageCount': 3, 'pageAmount': '130500.0000', 'count': 3, 'amount': '130500.0000'},
    });

ThemeData _themeOf(AppLook look) => withBarFonts(switch (look) {
      AppLook.glass => AppTheme.glass(GlassSpec.of(AppLooks.instance.blur.value)),
      AppLook.expressive => AppTheme.expressive(),
      AppLook.abos => AppTheme.light(),
    });

/// অ্যাপের মতো মোড়ক — রূপের থিম, Liquid Glass-এ সব পাতার পেছনে কাচের পট ([main.dart]-এর একই)
Widget _frame(AppLook look, Widget child) => MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: _themeOf(look),
      locale: AppLocale.locale,
      supportedLocales: AppLocale.supported,
      localizationsDelegates: AppLocale.delegates,
      builder: (context, inner) => look == AppLook.glass ? GlassBackdrop(child: inner!) : inner!,
      home: child,
    );

void main() {
  late HiveTestHarness harness;
  late AppLooks saved;

  setUpAll(() async {
    await loadBarFonts();
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
    await SyncEngine.instance.init();
  });

  tearDownAll(() async {
    appFrameOverride = null;
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  setUp(() {
    saved = AppLooks.instance;
    AppLooks.instance = AppLooks(read: (_) async => null, write: (_, __) async {});
    AppLooks.instance.motion.value = false; // ⓘ ছবিতে ছোপ স্থির
  });
  tearDown(() {
    AppLooks.instance = saved;
    appFrameOverride = null;
  });

  Future<void> shoot(WidgetTester tester, AppLook look, Widget Function() page, String name) async {
    AppLooks.instance.look.value = look;
    appFrameOverride = (child) => _frame(look, child);
    setScreenSize(tester, const Size(412, 900));
    await tester.runAsync(seedMainData);
    await tester.pumpWidget(RepaintBoundary(key: const ValueKey('shot'), child: page()));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('../goldens/app_0460/$name.png'));
  }

  Widget inbox() => appFrame(ApprovalInboxScreen(
        loadInbox: (_, __, ___, ____) async => _inbox(),
        loadSheet: (_) async => throw StateError('not in this shot'),
        bulkPreview: (_) async => throw StateError('not in this shot'),
        bulkApprove: (_, __) async => throw StateError('not in this shot'),
        approve: (_, __) async {},
        reject: (_, __) async {},
        sendBack: (_, __) async {},
        loadAttachment: (_, __) async => const [],
      ));

  Widget profile() {
    final c = profileContainer();
    addTearDown(c.dispose);
    return UncontrolledProviderScope(container: c, child: appFrame(ProfileScreen(api: FakeProfileApi())));
  }

  final screens = <String, Widget Function()>{
    'customer_list': customerList,
    'approvals': inbox,
    'notices': () => appFrame(NoticeBoardScreen(api: _Notices())),
    'profile': profile,
    'new_order': newOrder,
    'customer_detail': customerDetail,
    'collect': collect,
    'ledger': customerLedger,
  };

  for (final s in screens.entries) {
    testWidgets('${s.key} — expressive', (tester) async {
      await shoot(tester, AppLook.expressive, s.value, '${s.key}_expressive');
    });
    testWidgets('${s.key} — glass 40', (tester) async {
      await shoot(tester, AppLook.glass, s.value, '${s.key}_glass');
    });
  }

  // ⭐ ভাসমান অংশ — পপ-আপ আর নিচের শিট: কাচে ঘন কাচ (নিচের লেখা ঢাকে), Material 3-এ বড় গোল কোনা
  for (final look in [AppLook.glass, AppLook.expressive]) {
    testWidgets('dialog and sheet — ${look.name}', (tester) async {
      AppLooks.instance.look.value = look;
      setScreenSize(tester, const Size(412, 900));
      await tester.pumpWidget(RepaintBoundary(
        key: const ValueKey('shot'),
        child: _frame(
          look,
          Builder(
            builder: (context) => Scaffold(
              appBar: AppBar(title: const Text('নোটিশ বোর্ড')),
              body: ListView(padding: const EdgeInsets.all(16), children: [
                for (final t in ['নতুন হাজিরার নিয়ম', 'ঈদের ছুটি', 'বেতনের দিন'])
                  Card(margin: const EdgeInsets.only(bottom: 8), child: ListTile(title: Text(t), subtitle: const Text('সকাল ৯টার মধ্যে'))),
                const SizedBox(height: 8),
                FilledButton(
                  key: const ValueKey('open'),
                  onPressed: () {
                    showModalBottomSheet<void>(
                      context: context,
                      builder: (_) => const SizedBox(
                          height: 200, child: Center(child: Text('সাজান: নাম ক থেকে হ'))),
                    );
                    showDialog<void>(
                      context: context,
                      builder: (_) => AlertDialog(
                        title: const Text('অনুমোদন দিন?'),
                        content: const Text('PAY-0231 · ৳1,20,000.00'),
                        actions: [
                          TextButton(onPressed: () {}, child: const Text('না')),
                          FilledButton(onPressed: () {}, child: const Text('অনুমোদন দিন')),
                        ],
                      ),
                    );
                  },
                  child: const Text('খুলুন'),
                ),
              ]),
            ),
          ),
        ),
      ));
      await tester.tap(find.byKey(const ValueKey('open')));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      await expectLater(find.byKey(const ValueKey('shot')), matchesGoldenFile('../goldens/app_0460/dialog_${look.name}.png'));
    });
  }

  // ⓘ দাগের দুই প্রান্তে লেখা পড়া যায় — আয়নার মতো স্বচ্ছ (0) আর ঘষা কাচ (100)
  for (final level in [0, 100]) {
    testWidgets('customer list — glass at $level', (tester) async {
      await AppLooks.instance.chooseBlur(level);
      await shoot(tester, AppLook.glass, customerList, 'customer_list_glass_$level');
    });
  }
}
