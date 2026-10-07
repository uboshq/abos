import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/books/collection_entry.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hive/hive.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// ⛔ অফিসের "নতুন আদায়" কখনো সার্ভারে পৌঁছাত না — সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬।
///
/// <p>০.৪.২১–০.৪.২২ আদায়টা সিঙ্কের সারিতে দিত, আর সারি মালিকের নিয়মে ("নেট না থাকলে শুধু অর্ডার") আদায় নেয়ই না —
/// প্রতিবার "আদায় জমা করা গেল না"। collect_screen_test নকল API চালাত, তাই ধরেনি। ⭐ এখানে আসল পথ: আসল
/// [ServerCollectionEntryApi] আর আসল [SyncEngine], কেবল সার্ভারটা নকল (Dio-র অ্যাডাপ্টার) — তারে কী যায়, কী ফেরে,
/// আর ফোনের সারিতে কিছু থাকে কি না।
void main() {
  late HiveTestHarness harness;
  late _Server server;
  late HttpClientAdapter realAdapter;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await SyncEngine.instance.init();
    realAdapter = ApiClient.dio.httpClientAdapter;
  });

  tearDownAll(() async {
    ApiClient.dio.httpClientAdapter = realAdapter;
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  setUp(() async {
    server = _Server();
    ApiClient.dio.httpClientAdapter = server;
    if (Hive.isBoxOpen('abos_sync_queue')) await Hive.box<Map>('abos_sync_queue').clear();
  });

  final draft = CollectionDraft(
      customerId: 'cus-1', amount: '750.50', date: DateTime(2026, 10, 7), accountId: 'acc-1', confirm: true);

  test('it goes to the push door now, with the phone\'s key, and lands — nothing is queued', () async {
    server.answer = (change) => {'changeId': change['changeId'], 'status': 'APPLIED', 'entityId': 'col-9'};

    final outcome = await const ServerCollectionEntryApi().send(draft, 'local-key-1');

    expect(outcome.landedId, 'col-9');
    expect(outcome.refusal, isNull);
    expect(server.paths, ['/sync/sales/push']);
    final sent = server.changes.single;
    expect(sent['changeId'], 'local-key-1');
    expect(sent['entityType'], 'Collection');
    expect(sent['operation'], 'CREATE');
    expect(jsonDecode(sent['payloadJson'] as String), containsPair('amount', '750.50'));
    expect(SyncEngine.instance.pendingCount, 0, reason: '⛔ আদায় ফোনের সারিতে রয়ে গেল');
  });

  test('the same key again is the same collection — the server says DUPLICATE and the receipt still opens', () async {
    server.answer = (change) => {'changeId': change['changeId'], 'status': 'DUPLICATE', 'entityId': 'col-9'};

    final outcome = await const ServerCollectionEntryApi().send(draft, 'local-key-1');

    expect(outcome.landedId, 'col-9');
  });

  test('a refusal comes back with its reason, not as a landing', () async {
    // ⓘ ফেরানো সারিতে আইডি এলেও সেটা "বসা" নয়
    server.answer = (change) => {'changeId': change['changeId'], 'status': 'REJECTED', 'message': 'খাতটা দল-খাত', 'entityId': 'col-x'};

    final outcome = await const ServerCollectionEntryApi().send(draft, 'local-key-2');

    expect(outcome.landedId, isNull);
    expect(outcome.refusal, 'খাতটা দল-খাত');
  });

  test('no network: it says so, and nothing waits on the phone', () async {
    server.offline = true;

    await expectLater(const ServerCollectionEntryApi().send(draft, 'local-key-3'), throwsA(isA<NoNetworkForThis>()));
    expect(SyncEngine.instance.pendingCount, 0, reason: '⛔ নেট না থাকায় আদায় সারিতে উঠল — মালিকের নিয়মে কেবল অর্ডার');
  });
}

/// সার্ভারের নকল — পুশের দরজা; বাকিটা আসল
class _Server implements HttpClientAdapter {
  final List<String> paths = [];
  final List<Map<String, dynamic>> changes = [];
  bool offline = false;
  Map<String, dynamic> Function(Map<String, dynamic> change) answer =
      (change) => {'changeId': change['changeId'], 'status': 'APPLIED', 'entityId': 'x'};

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream, Future<void>? cancelFuture) async {
    if (offline) {
      throw DioException(requestOptions: options, type: DioExceptionType.connectionError);
    }
    paths.add(options.path);
    final body = options.data is String ? jsonDecode(options.data as String) : options.data;
    final rows = [for (final r in (body as List)) Map<String, dynamic>.from(r as Map)];
    changes.addAll(rows);
    return ResponseBody.fromString(
      jsonEncode({'outcomes': [for (final r in rows) answer(r)]}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
