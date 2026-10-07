import 'dart:convert';
import 'dart:typed_data';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:hive/hive.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// ⛔ অফলাইন সারি মানুষের সাথে বাঁধা — সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬: একটা ফোন দুইজন চালালে প্রথম জনের না-যাওয়া
/// অর্ডার দ্বিতীয় জনের সেশনে, তাঁর নামে বসত। আসল [SyncEngine], কেবল সার্ভার নকল।
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
    SyncEngine.instance.actAs(null);
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  setUp(() async {
    server = _Server();
    ApiClient.dio.httpClientAdapter = server;
    if (Hive.isBoxOpen('abos_sync_queue')) await Hive.box<Map>('abos_sync_queue').clear();
  });

  Future<void> anOrderBy(String who) async {
    SyncEngine.instance.actAs(who);
    server.down = true; // ⓘ নেট নেই — সারিতে থাকে
    await SyncEngine.instance.enqueue(
      module: 'sales',
      entityType: 'SalesOrder',
      operation: 'CREATE',
      payload: const {'customerId': 'cus-1', 'lines': []},
    );
    server.down = false;
    server.pushes.clear();
  }

  test('the next person neither sends nor sees the previous person\'s order; its writer does', () async {
    await anOrderBy('rahim');

    SyncEngine.instance.actAs('karim');
    expect(SyncEngine.instance.pendingCount, 0, reason: '⛔ আগের জনের অর্ডার নতুন জনের "অপেক্ষমাণ"-এ');
    expect(SyncEngine.instance.heldForOthersCount, 1);
    await SyncEngine.instance.flushAll();
    expect(server.pushes, isEmpty, reason: '⛔ আগের জনের অর্ডার নতুন জনের নামে গেল');

    SyncEngine.instance.actAs('rahim');
    expect(SyncEngine.instance.pendingCount, 1);
    await SyncEngine.instance.flushAll();
    expect(server.pushes, hasLength(1));
    expect(SyncEngine.instance.pendingCount, 0);
  });

  test('signed out, nothing goes at all', () async {
    await anOrderBy('rahim');

    SyncEngine.instance.actAs(null);
    // ⓘ লেখক-চিহ্ন ছাড়া সারিও (এই সংস্করণের আগের মতো) — কেউ না থাকলে সেটাও যায় না
    server.down = true;
    await SyncEngine.instance.enqueue(
        module: 'sales', entityType: 'SalesOrder', operation: 'CREATE', payload: const {'customerId': 'cus-2', 'lines': []});
    server.down = false;
    server.pushes.clear();

    await SyncEngine.instance.flushAll();
    await SyncEngine.instance.flush('sales');
    expect(server.pushes, isEmpty, reason: '⛔ কেউ নেই, তবু পাঠাল');
  });

  test('a failed try counts only against the writer\'s own rows', () async {
    await anOrderBy('rahim');
    SyncEngine.instance.actAs('karim');
    await anOrderBy('karim');

    server.down = true;
    for (var i = 0; i < 6; i++) {
      await SyncEngine.instance.flush('sales');
    }
    server.down = false;

    SyncEngine.instance.actAs('rahim');
    expect(SyncEngine.instance.rejectedCount, 0, reason: '⛔ অন্যের ব্যর্থ চেষ্টায় রহিমের অর্ডার "ফেরত" হলো');
    expect(SyncEngine.instance.pendingCount, 1);
  });
}

class _Server implements HttpClientAdapter {
  bool down = false;
  final List<String> pushes = [];

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream, Future<void>? cancelFuture) async {
    if (down) throw DioException(requestOptions: options, type: DioExceptionType.connectionError);
    pushes.add(options.path);
    final rows = (options.data is String ? jsonDecode(options.data as String) : options.data) as List;
    return ResponseBody.fromString(
      jsonEncode({
        'outcomes': [
          for (final r in rows) {'changeId': (r as Map)['changeId'], 'status': 'APPLIED', 'entityId': 'so-1'}
        ]
      }),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
