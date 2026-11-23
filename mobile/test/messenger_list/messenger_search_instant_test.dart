import 'package:abos_mobile/core/auth/me_features.dart';
import 'package:abos_mobile/core/locale/app_locale.dart';
import 'package:abos_mobile/core/messenger/messenger_api.dart';
import 'package:abos_mobile/core/messenger/messenger_unread.dart';
import 'package:abos_mobile/core/theme/app_look.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/messenger/list/chat_list_page.dart';
import 'package:abos_mobile/features/messenger/list/narrow_now.dart';
import 'package:abos_mobile/features/messenger/list/send_paper_sheet.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../fakes/fake_messenger_api.dart';

/// ⭐ বার্তার খোঁজা হোয়াটসঅ্যাপের মতো সাথে সাথে (২৩ নভেম্বর ২০২৬): ফোনে যা আছে তা প্রতিটা চাপে ছাঁকা;
/// সার্ভারের ডাক তবু ৩ অক্ষর / ৬০০ ms-এর নিয়মে।
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

  Widget host(MessengerApi api, Widget body) => ProviderScope(
        overrides: [
          messengerApiProvider.overrideWithValue(api),
          meFeaturesProvider.overrideWith((ref) => const MeFeatures(messaging: true, calls: true)),
        ],
        child: MaterialApp(
          theme: AppTheme.light(),
          locale: AppLocale.locale,
          supportedLocales: AppLocale.supported,
          localizationsDelegates: AppLocale.delegates,
          home: Scaffold(body: body),
        ),
      );

  Finder key(String k) => find.byKey(ValueKey(k));

  void phone(WidgetTester tester) {
    tester.view.physicalSize = const Size(412, 900);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
  }

  int serverCalls(FakeMessengerApi api, String prefix) => api.calls.where((c) => c.startsWith(prefix)).length;

  group('narrowNow', () {
    List<String> run(String typed, {String answeredFor = '', List<String>? answered, List<String> everything = const ['Rakib', 'Nasir', 'Mitu']}) =>
        narrowNow<String>(
          typed: typed,
          answeredFor: answeredFor,
          answered: answered ?? everything,
          everything: everything,
          fields: (s) => [s],
        );

    test('every key narrows what is on the phone, any case, from the first letter', () {
      expect(run('K'), ['Rakib']);
      expect(run('r'), ['Rakib', 'Nasir']);
      expect(run('I'), ['Rakib', 'Nasir', 'Mitu']);
      expect(run('mi'), ['Mitu']);
      expect(run(''), ['Rakib', 'Nasir', 'Mitu']);
    });

    test('the server answer for the same text is shown untouched (it may match inside messages)', () {
      expect(run('hello', answeredFor: 'hello', answered: ['Nasir']), ['Nasir']);
    });

    test('a longer text narrows the server answer; a different text goes back to the whole list', () {
      expect(run('nasi', answeredFor: 'nas', answered: ['Nasir', 'Nasreen']), ['Nasir']);
      expect(run('mi', answeredFor: 'nas', answered: ['Nasir']), ['Mitu']);
    });
  });

  testWidgets('chat list: one or two letters narrow at once with no server call; 3+ letters still ask the server after 600 ms',
      (tester) async {
    phone(tester);
    final api = FakeMessengerApi();
    await tester.pumpWidget(host(api, ChatListPage(now: () => FakeMessengerApi.at(12, 0))));
    await tester.pumpAndSettle();
    final before = serverCalls(api, 'threads:');
    expect(find.text('রাকিব হাসান'), findsOneWidget);

    await tester.enterText(key('msg-search'), 'না');
    await tester.pump();
    expect(find.text('নাসির'), findsOneWidget);
    expect(find.text('রাকিব হাসান'), findsNothing, reason: 'সাথে সাথে ছাঁকা');
    expect(serverCalls(api, 'threads:'), before, reason: '২ অক্ষরে সার্ভার নয়');

    await tester.enterText(key('msg-search'), 'হিসাব');
    await tester.pump();
    expect(find.text('হিসাব বিভাগ'), findsOneWidget);
    expect(find.text('রাকিব হাসান'), findsOneWidget, reason: 'পদবি "হিসাবরক্ষক"-এ মেলে');
    expect(find.text('নাসির'), findsNothing);
    expect(serverCalls(api, 'threads:'), before, reason: '৬০০ ms-এর আগে সার্ভার নয়');
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pumpAndSettle();
    expect(api.calls.last, 'threads:all:হিসাব');

    await tester.enterText(key('msg-search'), '');
    await tester.pump();
    expect(find.text('নাসির'), findsOneWidget, reason: 'খালি করলে পুরো তালিকা সাথে সাথে');
    await tester.pumpAndSettle();
  });

  testWidgets('people picker: narrows at once; the server is asked only at 3+ letters after 600 ms', (tester) async {
    phone(tester);
    final api = FakeMessengerApi();
    await tester.pumpWidget(host(api, ChatListPage(now: () => FakeMessengerApi.at(12, 0))));
    await tester.pumpAndSettle();
    await tester.tap(key('msg-new'));
    await tester.pumpAndSettle();
    final before = serverCalls(api, 'people:');
    expect(key('people-row-rak'), findsOneWidget);

    await tester.enterText(key('people-search'), 'মি');
    await tester.pump();
    expect(key('people-row-mit'), findsOneWidget);
    expect(key('people-row-rak'), findsNothing);
    expect(serverCalls(api, 'people:'), before);

    await tester.enterText(key('people-search'), 'গুদাম');
    await tester.pump();
    expect(key('people-row-jam'), findsOneWidget, reason: 'পদবিতেও মেলে');
    expect(key('people-row-mit'), findsNothing);
    await tester.pump(const Duration(milliseconds: 600));
    await tester.pumpAndSettle();
    expect(api.calls.last, 'people:গুদাম:');
  });

  testWidgets('send-paper sheet: the recent list narrows at once; no server call under 600 ms', (tester) async {
    phone(tester);
    final api = FakeMessengerApi();
    const paper = PaperRef(type: 'SalesInvoice', id: 'inv517', no: 'INV-0517', title: 'বিক্রয় চালান');
    await tester.pumpWidget(host(
      api,
      Builder(builder: (c) => Center(child: TextButton(onPressed: () => showSendPaperSheet(c, paper), child: const Text('go')))),
    ));
    await tester.tap(find.text('go'));
    await tester.pumpAndSettle();
    final before = serverCalls(api, 'people:');
    expect(key('send-row-person:rak'), findsOneWidget);
    expect(key('send-row-thread:t-acc'), findsOneWidget);

    await tester.enterText(key('send-paper-search'), 'হিসাব বি');
    await tester.pump();
    expect(key('send-row-thread:t-acc'), findsOneWidget);
    expect(key('send-row-person:rak'), findsNothing);
    expect(serverCalls(api, 'people:'), before);

    await tester.enterText(key('send-paper-search'), 'zz');
    await tester.pump();
    expect(find.byWidgetPredicate((w) => w.key is ValueKey<String> && (w.key! as ValueKey<String>).value.startsWith('send-row-')),
        findsNothing,
        reason: 'কিছু না মিললে সাথে সাথে খালি');
    expect(find.text('কিছু পাওয়া যায়নি'), findsOneWidget);
    expect(serverCalls(api, 'people:'), before);
    await tester.pumpAndSettle();
  });
}
