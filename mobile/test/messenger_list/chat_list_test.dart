import 'package:abos_mobile/core/auth/me_features.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/messenger/messenger_api.dart';
import 'package:abos_mobile/core/messenger/messenger_push.dart';
import 'package:abos_mobile/core/messenger/messenger_unread.dart';
import 'package:abos_mobile/core/navigation/top_bar/abos_top_bar.dart';
import 'package:abos_mobile/core/sync_engine/sync_status.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/messenger/chat/chat_screen.dart';
import 'package:abos_mobile/features/messenger/list/chat_list_look.dart';
import 'package:abos_mobile/features/messenger/list/chat_list_page.dart';
import 'package:abos_mobile/features/messenger/list/send_paper_sheet.dart';
import 'package:abos_mobile/core/printing/printer_settings.dart';
import 'package:abos_mobile/features/printing/document_actions_sheet.dart';
import 'package:abos_mobile/features/printing/row_actions.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fakes/fake_messenger_api.dart';

/// ⭐ বার্তার তালিকা, "নতুন বার্তা", "বার্তায় পাঠান" আর উপরের বারের বার্তা-বোতাম — নমুনা 3nuwRUdn v5 "ক" আর "গ" ২–৩।
class _NotAllowedApi extends FakeMessengerApi {
  @override
  Future<ThreadPage> threads({ThreadFilter filter = ThreadFilter.all, String query = ''}) async =>
      throw const MessengerNotAllowed('আপনার রোলে বার্তা চালু নেই');
}

void main() {
  late AppLooks saved;
  setUp(() {
    saved = AppLooks.instance;
    final box = <String, String>{};
    AppLooks.instance = AppLooks(read: (k) async => box[k], write: (k, v) async => box[k] = v);
    AppLooks.instance.look.value = AppLook.abos;
    MessengerUnread.count.value = null;
  });
  tearDown(() {
    AppLooks.instance = saved;
    MessengerUnread.count.value = null;
  });

  DateTime now() => FakeMessengerApi.at(12, 0);

  Widget host(MessengerApi api, Widget body, {MeFeatures features = const MeFeatures(messaging: true, calls: true)}) => ProviderScope(
        overrides: [messengerApiProvider.overrideWithValue(api), meFeaturesProvider.overrideWith((ref) => features)],
        child: MaterialApp(
          theme: AppTheme.light(),
          locale: AppLocale.locale,
          supportedLocales: AppLocale.supported,
          localizationsDelegates: AppLocale.delegates,
          home: Scaffold(body: body),
        ),
      );

  Finder key(String k) => find.byKey(ValueKey(k));

  void phone(WidgetTester tester, {double w = 412, double h = 900}) {
    tester.view.physicalSize = Size(w, h);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
  }

  group('the chat list', () {
    testWidgets('rows, chips and the sample times: today HH:mm, গতকাল, বুধ; unread rows green', (tester) async {
      phone(tester);
      final api = FakeMessengerApi();
      await tester.pumpWidget(host(api, ChatListPage(now: now)));
      await tester.pumpAndSettle();

      expect(find.text('নাম বা বার্তা খুঁজুন'), findsOneWidget);
      for (final c in ['সব', 'না-পড়া 8', 'গ্রুপ', 'কাগজ']) {
        expect(find.text(c), findsOneWidget, reason: c);
      }
      expect(find.text('রাকিব হাসান'), findsOneWidget);
      expect(find.text('হিসাব বিভাগ'), findsOneWidget);
      expect(tester.widget<Text>(key('msg-time-t-rak')).data, '10:42');
      expect(tester.widget<Text>(key('msg-time-t-far')).data, 'গতকাল');
      expect(tester.widget<Text>(key('msg-time-t-jam')).data, 'বুধ');
      expect(tester.widget<Text>(key('msg-time-t-rak')).style!.color, const Color(0xFF1DAA61), reason: 'না-পড়া সারির সময় সবুজ');
      expect(key('msg-unread-t-acc'), findsOneWidget);
      expect(key('msg-unread-t-tds'), findsNothing);
      expect(find.textContaining('আপনি:'), findsNWidgets(2));
      expect(find.textContaining('ফারজানা:'), findsOneWidget, reason: 'গ্রুপে পাঠানোর নাম');
      expect(find.textContaining('ভয়েস বার্তা · 0:18'), findsOneWidget);
      expect(find.textContaining('RCV-0142'), findsOneWidget);
      expect(key('msg-new'), findsOneWidget);
      expect(find.text('বার্তা'), findsNothing, reason: '⛔ "বার্তা" শিরোনাম নেই');
      expect(MessengerUnread.count.value, 8, reason: 'উপরের বারের গোনাও নতুন');
      expect(tester.takeException(), isNull);
    });

    testWidgets('a chip asks the server again with its filter; search goes with q', (tester) async {
      phone(tester);
      final api = FakeMessengerApi();
      await tester.pumpWidget(host(api, ChatListPage(now: now)));
      await tester.pumpAndSettle();
      await tester.tap(key('msg-chip-groups'));
      await tester.pumpAndSettle();
      expect(api.calls.last, 'threads:groups:');
      expect(find.text('রাকিব হাসান'), findsNothing);
      expect(find.text('হিসাব বিভাগ'), findsOneWidget);
      await tester.tap(key('msg-chip-all'));
      await tester.pumpAndSettle();
      await tester.enterText(key('msg-search'), 'নাসির');
      await tester.pump(const Duration(milliseconds: 600));
      await tester.pumpAndSettle();
      expect(api.calls.last, 'threads:all:নাসির');
      expect(find.text('রাকিব হাসান'), findsNothing);
    });

    testWidgets('a push "chat" event and the 10 s poll both read the list again', (tester) async {
      phone(tester);
      final api = FakeMessengerApi();
      await tester.pumpWidget(host(api, ChatListPage(now: now)));
      await tester.pumpAndSettle();
      final before = api.calls.length;
      MessengerPush.instance.emit(const MessengerEvent('chat', {'thread_id': 't-rak'}));
      await tester.pumpAndSettle();
      expect(api.calls.length, before + 1);
      await tester.pump(ChatListPage.poll);
      await tester.pumpAndSettle();
      expect(api.calls.length, before + 2);
      MessengerPush.instance.emit(const MessengerEvent('call', {'call_id': 'c1'}));
      await tester.pumpAndSettle();
      expect(api.calls.length, before + 2, reason: 'কলের খবরে তালিকা নয়');
    });

    testWidgets('server without the doors: a calm line, no button, no crash', (tester) async {
      phone(tester);
      await tester.pumpWidget(host(FakeMessengerApi(doorsMissing: true), ChatListPage(now: now)));
      await tester.pumpAndSettle();
      expect(find.text('বার্তা এখনো চালু হয়নি'), findsOneWidget);
      expect(key('msg-new'), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a 403 shows the server\'s own sentence', (tester) async {
      phone(tester);
      await tester.pumpWidget(host(_NotAllowedApi(), ChatListPage(now: now)));
      await tester.pumpAndSettle();
      expect(find.text('আপনার রোলে বার্তা চালু নেই'), findsOneWidget);
    });

    testWidgets('a row opens its chat; "নতুন বার্তা" picks a person, opens the direct chat', (tester) async {
      phone(tester);
      final api = FakeMessengerApi();
      await tester.pumpWidget(host(api, ChatListPage(now: now)));
      await tester.pumpAndSettle();
      await tester.tap(key('msg-row-t-nas'));
      await tester.pumpAndSettle();
      expect(find.byType(ChatScreen), findsOneWidget);
      expect(tester.widget<ChatScreen>(find.byType(ChatScreen)).thread.id, 't-nas');
      Navigator.of(tester.element(find.byType(ChatScreen))).pop();
      await tester.pumpAndSettle();

      await tester.tap(key('msg-new'));
      await tester.pumpAndSettle();
      expect(find.text('নাম খুঁজুন'), findsOneWidget);
      await tester.tap(key('people-row-mit'));
      await tester.pumpAndSettle();
      expect(api.calls, contains('direct:mit'));
      expect(find.byType(ChatScreen), findsOneWidget);
    });

    testWidgets('the last row ends above the green button (it never hides a row)', (tester) async {
      phone(tester, w: 360, h: 640);
      await tester.pumpWidget(host(FakeMessengerApi(), ChatListPage(now: now)));
      await tester.pumpAndSettle();
      final list = tester.state<ScrollableState>(find.descendant(of: key('msg-list'), matching: find.byType(Scrollable)).first);
      list.position.jumpTo(list.position.maxScrollExtent);
      await tester.pumpAndSettle();
      final last = tester.getRect(key('msg-row-t-jam'));
      final fab = tester.getRect(key('msg-new'));
      expect(last.bottom, lessThanOrEqualTo(fab.top));
    });

    testWidgets('200% text: nothing overflows at 320dp', (tester) async {
      phone(tester, w: 320, h: 700);
      await tester.pumpWidget(MediaQuery(
          data: const MediaQueryData(size: Size(320, 700), textScaler: TextScaler.linear(2)),
          child: host(FakeMessengerApi(), ChatListPage(now: now))));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
    });
  });

  test('time column: today, yesterday, this week, older', () {
    final l = AppLocale.l10n;
    final n = DateTime(2026, 11, 21, 12);
    expect(chatListTime(DateTime(2026, 11, 21, 9, 5), n, l), '09:05');
    expect(chatListTime(DateTime(2026, 11, 20, 23), n, l), 'গতকাল');
    expect(chatListTime(DateTime(2026, 11, 18, 8), n, l), 'বুধ');
    expect(chatListTime(DateTime(2026, 11, 10, 8), n, l), '10/11/2026');
    expect(voiceLength(18000), '0:18');
  });

  test('paperRefFor strips the number out of the screen title', () {
    final p = paperRefFor(type: 'SalesInvoice', id: 'abc', title: 'বিক্রয় চালান · INV-0517', number: 'INV-0517');
    expect(p.no, 'INV-0517');
    expect(p.title, 'বিক্রয় চালান');
    expect(p.toJson(), {'paper_type': 'SalesInvoice', 'paper_id': 'abc'});
    expect(paperRefFor(type: 'X', id: '1', title: 'INV-1').no, 'INV-1');
  });

  group('send a paper in messages', () {
    const paper = PaperRef(type: 'SalesInvoice', id: 'inv517', no: 'INV-0517', title: 'বিক্রয় চালান', amount: '৳86,400', party: 'রহমান এন্টারপ্রাইজ');

    Widget opener(FakeMessengerApi api, {PaperRef p = paper}) => host(
          api,
          Builder(builder: (c) => Center(child: TextButton(onPressed: () => showSendPaperSheet(c, p), child: const Text('go')))),
        );

    testWidgets('recent people and groups, amber lock line, send to person:/thread: with the note', (tester) async {
      phone(tester);
      final api = FakeMessengerApi();
      await tester.pumpWidget(opener(api));
      await tester.tap(find.text('go'));
      await tester.pumpAndSettle();

      expect(find.text('বার্তায় পাঠান'), findsOneWidget);
      expect(find.text('বিক্রয় চালান INV-0517'), findsOneWidget);
      expect(find.text('৳86,400 · রহমান এন্টারপ্রাইজ'), findsOneWidget);
      expect(find.text('কাকে পাঠাবেন? নাম খুঁজুন'), findsOneWidget);
      expect(find.text('সাম্প্রতিক'), findsOneWidget);
      expect(find.descendant(of: key('send-row-person:far'), matching: find.text('এই কাগজ খোলার অনুমতি নেই — শুধু নম্বর দেখবেন')),
          findsOneWidget);
      expect(find.descendant(of: key('send-row-person:rak'), matching: key('person-locked')), findsNothing);
      expect(tester.widget<ButtonStyleButton>(key('send-paper-go')).onPressed, isNull,
          reason: '০ জনে বন্ধ');

      await tester.tap(key('send-row-person:rak'));
      await tester.tap(key('send-row-thread:t-acc'));
      await tester.pump();
      expect(find.text('পাঠান · 2 জন'), findsOneWidget);
      await tester.enterText(key('send-paper-note'), 'দেখে নিন');
      await tester.tap(key('send-paper-go'));
      await tester.pumpAndSettle();
      expect(api.calls.last, 'sendPaper:SalesInvoice:inv517:person:rak,thread:t-acc:দেখে নিন');
      expect(key('send-paper-sheet'), findsNothing);
      expect(find.text('পাঠানো হয়েছে'), findsOneWidget);
    });

    testWidgets('a locked person can still be picked; empty paper fields are hidden', (tester) async {
      phone(tester);
      final api = FakeMessengerApi();
      await tester.pumpWidget(opener(api, p: const PaperRef(type: 'MoneyReceipt', id: 'r1', no: 'RCV-0142', title: 'রসিদ')));
      await tester.tap(find.text('go'));
      await tester.pumpAndSettle();
      expect(find.text('রসিদ RCV-0142'), findsOneWidget);
      expect(find.textContaining(' · '), findsWidgets);
      expect(find.textContaining('৳'), findsNothing);
      await tester.tap(key('send-row-person:far'));
      await tester.pump();
      expect(find.text('পাঠান · 1 জন'), findsOneWidget);
    });

    testWidgets('pickRecipients returns the picked list and sends nothing (chat forward)', (tester) async {
      phone(tester);
      final api = FakeMessengerApi();
      List<String>? got;
      await tester.pumpWidget(host(
        api,
        Builder(builder: (c) => TextButton(onPressed: () async => got = await pickRecipients(c), child: const Text('go'))),
      ));
      await tester.tap(find.text('go'));
      await tester.pumpAndSettle();
      expect(key('send-paper-card'), findsNothing);
      expect(key('send-paper-note'), findsNothing);
      expect(key('person-locked'), findsNothing);
      await tester.tap(key('send-row-thread:t-srn'));
      await tester.pump();
      await tester.tap(key('send-paper-go'));
      await tester.pumpAndSettle();
      expect(got, ['thread:t-srn']);
      expect(api.calls.where((c) => c.startsWith('sendPaper')), isEmpty);
    });

    testWidgets('the row ⋯ menu offers "বার্তায় পাঠান" and opens the sheet', (tester) async {
      phone(tester);
      await tester.pumpWidget(host(
        FakeMessengerApi(),
        RowActionsButton(number: 'INV-0517', state: null, onView: () {}, paperType: 'SalesInvoice', paperId: 'inv517', paperTitle: 'বিক্রয় চালান'),
      ));
      await tester.tap(key('row-actions-INV-0517'));
      await tester.pumpAndSettle();
      expect(key('row-action-send-chat'), findsOneWidget);
      await tester.tap(key('row-action-send-chat'));
      await tester.pumpAndSettle();
      expect(key('send-paper-sheet'), findsOneWidget);
      expect(find.text('বিক্রয় চালান INV-0517'), findsOneWidget);
    });

    testWidgets('messaging off (old server): no "বার্তায় পাঠান" in the row ⋯ menu', (tester) async {
      phone(tester);
      await tester.pumpWidget(host(
        FakeMessengerApi(),
        RowActionsButton(number: 'INV-0517', state: null, onView: () {}, paperType: 'SalesInvoice', paperId: 'inv517'),
        features: MeFeatures.none,
      ));
      await tester.tap(key('row-actions-INV-0517'));
      await tester.pumpAndSettle();
      expect(key('row-action-print'), findsOneWidget);
      expect(key('row-action-send-chat'), findsNothing);
    });

    testWidgets('the print/send sheet: "বার্তায় পাঠান" only with messaging, never for a report', (tester) async {
      phone(tester);
      Widget sheet(String type, MeFeatures f) => KeyedSubtree(key: ValueKey('$type-${f.messaging}'), child: host(
            FakeMessengerApi(),
            DocumentActionsSheet(type: type, id: 'x1', title: 'বিক্রয় চালান · INV-0517', fileStem: 'INV-0517', loadPapers: () async => const ['a4'],
                settingsStore: MemoryPrinterSettingsStore(const PrinterSettings(permissionExplained: true))),
            features: f,
          ));
      await tester.pumpWidget(sheet('SalesInvoice', const MeFeatures(messaging: true)));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('document-send-chat')), findsOneWidget);
      await tester.pumpWidget(sheet('SalesInvoice', MeFeatures.none));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('document-send-chat')), findsNothing);
      await tester.pumpWidget(sheet('report', const MeFeatures(messaging: true)));
      await tester.pumpAndSettle();
      expect(find.byKey(const Key('document-send-chat')), findsNothing);
    });
  });

  test('MeFeatures reads messaging and calls; a missing key is false', () {
    expect(MeFeatures.fromJson(const {'messaging': true, 'calls': true}), const MeFeatures(messaging: true, calls: true));
    expect(MeFeatures.fromJson(const {'profile_photo': true}).messaging, isFalse);
    expect(MeFeatures.fromJson(const {'messaging': 'yes', 'calls': 1}), MeFeatures.none);
  });

  group('the top bar messages button', () {
    Future<void> pumpBar(WidgetTester tester, AppLook look, {bool active = false, VoidCallback? onTap}) async {
      AppLooks.instance.look.value = look;
      phone(tester);
      await tester.pumpWidget(MaterialApp(
        theme: switch (look) { AppLook.expressive => AppTheme.expressive(), _ => AppTheme.light() },
        locale: AppLocale.locale,
        supportedLocales: AppLocale.supported,
        localizationsDelegates: AppLocale.delegates,
        home: Scaffold(
          appBar: AbosTopBar(
            company: 'ADI Corporation',
            branch: 'TD শাখা',
            demo: false,
            syncStatus: SyncStatus(),
            onSearch: () {},
            onMessages: onTap ?? () {},
            messagesActive: active,
            avatarName: 'শুভ',
            showAvatar: false,
            onAvatar: (_) {},
          ),
          body: const SizedBox.expand(),
        ),
      ));
      await tester.pump();
    }

    testWidgets('messaging off: no messages button at all (no "coming soon")', (tester) async {
      AppLooks.instance.look.value = AppLook.abos;
      phone(tester);
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          appBar: AbosTopBar(
              company: 'ADI', branch: 'TD শাখা', demo: false, syncStatus: SyncStatus(), onSearch: () {}, avatarName: 'শুভ', showAvatar: false, onAvatar: (_) {}),
        ),
      ));
      await tester.pump();
      expect(key('top-messages'), findsNothing);
      expect(key('top-search'), findsOneWidget);
    });

    testWidgets('closed: red unread count; tap toggles', (tester) async {
      MessengerUnread.count.value = 8;
      var taps = 0;
      await pumpBar(tester, AppLook.abos, onTap: () => taps++);
      expect(key('top-messages-badge'), findsOneWidget);
      expect(find.descendant(of: key('top-messages-badge'), matching: find.text('8')), findsOneWidget);
      await tester.tap(key('top-messages'));
      expect(taps, 1);
    });

    for (final (look, ink, bg) in [
      (AppLook.abos, const Color(0xFFFFFFFF), const Color(0xFF3B82F6)),
      (AppLook.expressive, const Color(0xFF3B82F6), const Color(0x293B82F6)),
    ]) {
      testWidgets('${look.name} open: filled glyph on the active blue, no count', (tester) async {
        MessengerUnread.count.value = 8;
        await pumpBar(tester, look, active: true);
        expect(key('top-messages-badge'), findsNothing);
        final button = tester.widget<IconButton>(find.descendant(of: key('top-messages'), matching: find.byType(IconButton)));
        expect(button.style!.backgroundColor!.resolve({WidgetState.selected}), bg);
        final icon = tester.widget<Icon>(find.descendant(of: key('top-messages'), matching: find.byType(Icon)));
        expect(icon.fill, 1);
        expect(icon.color, ink);
        final r = tester.getRect(key('top-messages'));
        expect(r.width, greaterThanOrEqualTo(48));
      });
    }
  });

  test('ChatListLook: the sample green and the chip colours', () {
    expect(ChatListLook.green, const Color(0xFF00A884));
    expect(ChatListLook.badge, const Color(0xFF25D366));
  });
}
