import 'package:abos_mobile/core/books/books_api.dart';
import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/features/books/collect_screen.dart';
import 'package:abos_mobile/features/books/money_in_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ অফিসের লোকের ফোনে টাকা আদায় (0.4.21) — মালিক, ৭ অক্টোবর ২০২৬: "এখন অ্যাপে পেমেন্ট অপশন চালু করো … কেবল
/// অফিসের লোকদের জন্য"। সার্ভারের দাবি: ThePhoneTakesMoneyLikeTheWebTest।
const _cash =
    CollectionAccount(id: 'acc-cash', name: 'প্রধান নগদ', kind: 'cash');
const _bkash =
    CollectionAccount(id: 'acc-bkash', name: 'bKash মার্চেন্ট', kind: 'mfs');

final _shop = CustomerRecord(
    const {'id': 'cus-1', 'nameBn': 'রহিম স্টোর', 'pointName': 'ফুলপুর বাজার'});

/// ⓘ পর্দার পরীক্ষা — তারে কী যায় তার পরীক্ষা আসল পথে: collection_goes_online_test
class _FakeEntry implements CollectionEntryApi {
  _FakeEntry({this.lands, this.refuses, this.offlineTimes = 0});

  final String? lands;
  final String? refuses;
  int offlineTimes;
  CollectionDraft? sent;
  final List<String> keys = [];

  @override
  Future<List<CollectionAccount>> accounts() async => const [_cash, _bkash];

  @override
  Future<PushOutcome> send(CollectionDraft draft, String changeId) async {
    keys.add(changeId);
    if (offlineTimes > 0) {
      offlineTimes--;
      throw const NoNetworkForThis();
    }
    sent = draft;
    return PushOutcome(landedId: lands, refusal: refuses);
  }
}

var _seq = 0;
String _key() => 'key-${_seq++}';

class _FakeBooks implements BooksApi {
  @override
  Future<MoneyInDetail> moneyInDetail(String id) async => const MoneyInDetail(
        row: MoneyInRow(
            id: 'col-1',
            no: 'COL-0042',
            date: '2026-10-07',
            customer: 'রহিম স্টোর',
            account: 'bKash মার্চেন্ট',
            method: 'mfs',
            amount: 750.5),
        status: 'confirmed',
      );

  @override
  dynamic noSuchMethod(Invocation invocation) => super.noSuchMethod(invocation);
}

Future<void> _pump(WidgetTester tester, Widget screen) async {
  await tester.pumpWidget(const SizedBox());
  tester.view.physicalSize = const Size(800, 2600);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(home: screen));
  await tester.pumpAndSettle();
}

Future<void> _pickTheShop(WidgetTester tester) async {
  await tester.tap(find.byKey(const ValueKey('collect-customer')));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const ValueKey('collect-pick-cus-1')));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('nothing is sent without a customer or with a malformed amount',
      (tester) async {
    final api = _FakeEntry();
    await _pump(tester,
        CollectScreen(api: api, books: _FakeBooks(), customers: [_shop], newChangeId: _key));

    await tester.enterText(find.byKey(const ValueKey('collect-amount')), '500');
    await tester.tap(find.byKey(const ValueKey('collect-send')));
    await tester.pumpAndSettle();
    expect(find.text('গ্রাহক বাছুন।'), findsOneWidget);

    await _pickTheShop(tester);
    await tester.enterText(find.byKey(const ValueKey('collect-amount')), '1e5');
    await tester.tap(find.byKey(const ValueKey('collect-send')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('collect-error')), findsOneWidget);
    expect(api.sent, isNull, reason: '⛔ ভুল অঙ্কেও পাঠানো হলো');
  });

  testWidgets(
      'an mfs collection carries the account, the TrxID and "confirm now"; a refusal shows its reason',
      (tester) async {
    final api = _FakeEntry(refuses: 'TrxID আগে ব্যবহার হয়েছে');
    await _pump(
        tester,
        CollectScreen(
            api: api,
            books: _FakeBooks(),
            customers: [_shop],
            newChangeId: _key,
            today: DateTime(2026, 10, 7)));

    await _pickTheShop(tester);
    expect(find.textContaining('ফুলপুর বাজার'), findsOneWidget);
    await tester.enterText(
        find.byKey(const ValueKey('collect-amount')), '750.50');
    await tester.tap(find.byKey(const ValueKey('collect-account')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('bKash মার্চেন্ট · মোবাইল ব্যাংকিং').last);
    await tester.pumpAndSettle();
    await tester.enterText(
        find.byKey(const ValueKey('collect-instrument-no')), 'TRX-7781');
    await tester.tap(find.byKey(const ValueKey('collect-send')));
    await tester.pumpAndSettle();

    final payload = api.sent!.toPayload();
    expect(payload['customerId'], 'cus-1');
    expect(payload['amount'], '750.50');
    expect(payload['trxDate'], '2026-10-07');
    expect(payload['accountId'], 'acc-bkash');
    expect(payload['instrument'], 'মোবাইল ব্যাংকিং');
    expect(payload['instrumentNo'], 'TRX-7781');
    expect(payload['confirm'], true);
    expect(find.text('TrxID আগে ব্যবহার হয়েছে'), findsOneWidget);

    // ⓘ ফেরানো কাজ ঠিক করে আবার — নতুন চাবি (ফেরানো চাবি আবার পাঠালে সার্ভার আবার ফেরাত)
    await tester.tap(find.byKey(const ValueKey('collect-send')));
    await tester.pumpAndSettle();
    expect(api.keys, hasLength(2));
    expect(api.keys[0], isNot(api.keys[1]), reason: '⛔ ফেরানো চাবিতেই আবার পাঠাল');
  });

  testWidgets('no network: "নেট লাগবে", and the retry carries the same key so it lands once',
      (tester) async {
    final api = _FakeEntry(lands: 'col-1', offlineTimes: 1);
    await _pump(tester,
        CollectScreen(api: api, books: _FakeBooks(), customers: [_shop], newChangeId: _key));

    await _pickTheShop(tester);
    await tester.enterText(find.byKey(const ValueKey('collect-amount')), '750.50');
    await tester.tap(find.byKey(const ValueKey('collect-send')));
    await tester.pumpAndSettle();
    expect(find.textContaining('আদায় দিতে নেট লাগবে'), findsOneWidget);
    expect(api.sent, isNull);

    await tester.tap(find.byKey(const ValueKey('collect-send')));
    await tester.pumpAndSettle();
    expect(api.keys, hasLength(2));
    expect(api.keys[0], api.keys[1], reason: '⛔ নেট ফেরার পরে নতুন চাবি — দুবার চাপলে দুটো আদায় বসতে পারত');
    expect(find.text('COL-0042'), findsWidgets, reason: 'বসে গেলে রসিদ');
  });

  testWidgets(
      'once it lands, the receipt opens with its number and that it is posted',
      (tester) async {
    final api = _FakeEntry(lands: 'col-1');
    await _pump(tester,
        CollectScreen(api: api, books: _FakeBooks(), customers: [_shop], newChangeId: _key));

    await _pickTheShop(tester);
    await tester.enterText(
        find.byKey(const ValueKey('collect-amount')), '750.50');
    await tester.tap(find.byKey(const ValueKey('collect-send')));
    await tester.pumpAndSettle();

    expect(find.text('COL-0042'), findsWidgets,
        reason: '⛔ রসিদে আদায়ের নম্বর নেই');
    expect(find.text('পাকা — খাতায় উঠেছে'), findsOneWidget);
  });

  testWidgets(
      'only an office person sees "নতুন আদায়"; the field keeps the deposit request',
      (tester) async {
    await _pump(tester, MoneyInListScreen(api: _FakeBooks()));
    expect(find.byKey(const ValueKey('money-in-new')), findsNothing,
        reason: '⛔ মাঠের লোকও ফোনে আদায় লিখতে পারছে');

    await _pump(tester, MoneyInListScreen(api: _FakeBooks(), canCollect: true));
    expect(find.byKey(const ValueKey('money-in-new')), findsOneWidget);
  });

  test(
      'a cash account sends no instrument and the payload stays what the server reads',
      () {
    final payload = CollectionDraft(
            customerId: 'cus-1',
            amount: '100',
            date: DateTime(2026, 10, 7),
            accountId: 'acc-cash',
            instrument: '')
        .toPayload();
    expect(payload.containsKey('instrument'), isFalse);
    expect(payload['confirm'], true);
  });
}
