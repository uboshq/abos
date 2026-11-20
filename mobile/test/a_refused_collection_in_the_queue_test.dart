import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/config/app_config.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:abos_mobile/features/orders/rejected_order_card.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hive/hive.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// ⛔ টাকা নেওয়ার এক কাগজ — মালিক, ২০ নভেম্বর ২০২৬: সার্ভার আর নতুন আদায় (COL) নেয় না, কারণসহ ফেরায়।
///
/// <p>আজকের অ্যাপ আদায় সারিতে তোলেই না ([SyncEngine.enqueue] শুধু অর্ডার আর হাজিরা নেয়), তবু পুরনো ফোনের সারিতে কিছু পড়ে থাকলে
/// সেটা চুপচাপ মোছা হয় না, চিরকাল আবার পাঠানোও হয় না — "ফেরত"-এ যায়, সার্ভারের নিজের কথাসহ, আর মানুষ সেটা কার্ডে পড়েন।
/// সার্ভারটা নকল (Dio-র অ্যাডাপ্টার); সারি আর ইঞ্জিন আসল।
void main() {
  const box = 'abos_sync_queue';
  const refusal = 'নতুন আদায় আর নেওয়া হয় না — টাকা জমার খবর পাঠান, হিসাব রসিদ কাটবে।';
  late HiveTestHarness harness;
  late HttpClientAdapter realAdapter;
  late _Server server;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await SyncEngine.instance.init();
    realAdapter = ApiClient.dio.httpClientAdapter;
  });

  tearDownAll(() async {
    ApiClient.dio.httpClientAdapter = realAdapter;
    SyncEngine.instance.actAs(null);
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  setUp(() async {
    server = _Server();
    ApiClient.dio.httpClientAdapter = server;
    SyncEngine.instance.actAs('u-1');
    if (Hive.isBoxOpen(box)) await Hive.box<Map>(box).clear();
  });

  /// পুরনো ফোনের সারিতে পড়ে থাকা একটা নতুন আদায় — যেমনটা সারির নিজের গড়নে থাকে
  Future<void> leftoverCollection() => Hive.box<Map>(box).add(<String, dynamic>{
        'changeId': 'old-col-1',
        'userId': 'u-1',
        'module': 'sales',
        'entityType': 'Collection',
        'entityId': null,
        'operation': 'CREATE',
        'payloadJson': jsonEncode({'customerId': 'cus-1', 'amount': '750.50'}),
        'clientVersion': 1,
        'attempts': 0,
        'enqueuedAt': DateTime(2026, 11, 19, 10).toIso8601String(),
      });

  test('the server refuses the change: it lands in "ফেরত" with the server\'s own words, and is never sent again', () async {
    await leftoverCollection();
    server.answer = (change) => {'changeId': change['changeId'], 'status': 'REJECTED', 'message': refusal};

    await SyncEngine.instance.flush('sales');

    expect(server.calls, 1);
    expect(SyncEngine.instance.pendingCount, 0, reason: '⛔ ফেরানো আদায় এখনো "অপেক্ষায়"');
    final rejected = SyncEngine.instance.rejectedItems.single;
    expect(rejected.entityType, 'Collection');
    expect(rejected.reason, refusal, reason: '⛔ সার্ভারের কারণ হারাল');

    await SyncEngine.instance.flush('sales');
    expect(server.calls, 1, reason: '⛔ ফেরানো আদায় আবার পাঠানো হলো');
  });

  test('the server refuses the whole request (422 with a message): after the last try the reason is the server\'s, not "failed again"',
      () async {
    await leftoverCollection();
    server.status = 422;
    server.body = {'message': refusal};

    for (var i = 0; i < AppConfig.syncMaxAttempts; i++) {
      await SyncEngine.instance.flush('sales');
    }

    expect(server.calls, AppConfig.syncMaxAttempts);
    expect(SyncEngine.instance.lastFailureFor('sales')?.sentence, contains(refusal),
        reason: 'চেষ্টার মাঝে "অপেক্ষায়"-এর নিচে সার্ভারের কথা নেই');
    expect(SyncEngine.instance.pendingCount, 0);
    expect(SyncEngine.instance.rejectedItems.single.reason, refusal, reason: '⛔ শেষে সার্ভারের কথা নয়, "বারবার পাঠানো গেল না"');

    await SyncEngine.instance.flush('sales');
    expect(server.calls, AppConfig.syncMaxAttempts, reason: '⛔ চিরকাল আবার পাঠানো চলছে');
  });

  testWidgets('the person reads it: the refused card names a collection and says the server\'s reason', (tester) async {
    await tester.runAsync(() async {
      await leftoverCollection();
      server.answer = (change) => {'changeId': change['changeId'], 'status': 'REJECTED', 'message': refusal};
      await SyncEngine.instance.flush('sales');
    });
    final item = SyncEngine.instance.rejectedItems.single;

    await tester.pumpWidget(MaterialApp(home: Scaffold(body: RejectedOrderCard(item: item, onDismissed: () {}))));
    expect(find.text(refusal), findsOneWidget, reason: '⛔ ফেরতের কার্ডে সার্ভারের কারণ নেই');
    expect(find.text('আদায়'), findsOneWidget, reason: 'কার্ড বলে না যে এটা একটা আদায়');
  });
}

/// সার্ভারের নকল — পুশের দরজা
class _Server implements HttpClientAdapter {
  int calls = 0;
  int status = 200;
  Map<String, dynamic>? body;
  Map<String, dynamic> Function(Map<String, dynamic> change) answer =
      (change) => {'changeId': change['changeId'], 'status': 'APPLIED', 'entityId': 'x'};

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream, Future<void>? cancelFuture) async {
    calls++;
    final sent = options.data is String ? jsonDecode(options.data as String) : options.data;
    final rows = [for (final r in (sent as List)) Map<String, dynamic>.from(r as Map)];
    return ResponseBody.fromString(
      jsonEncode(body ?? {'outcomes': [for (final r in rows) answer(r)]}),
      status,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
