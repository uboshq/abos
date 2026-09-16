import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/api_client/api_client.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/reference_sync.dart';
import 'package:abos_mobile/core/sync_engine/sync_engine.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// The download half, driven for real against a stubbed server.
///
/// <p><b>Why this file exists at all:</b> [ReferenceSync] had no test. Its own
/// class comment calls the watermark rule "the whole difficulty of this file",
/// and getting it wrong does not throw, does not log, and does not show —
/// records between a partial batch's cutoff and "now" would be marked synced
/// without ever having been pulled, and would never be sent again. Missing,
/// silently, forever. Then on 17 September its entry point was edited (to keep
/// the reason a pull failed) with nothing in the suite to notice if the edit
/// broke the pull itself.
///
/// <p>These drive the real `syncAll()` through a stubbed [HttpClientAdapter],
/// so the request sequence is the thing under test — not a seam standing in
/// for it.
void main() {
  late HiveTestHarness harness;
  late _StubAdapter stub;
  late HttpClientAdapter realAdapter;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
    realAdapter = ApiClient.dio.httpClientAdapter;
  });

  tearDownAll(() async {
    ApiClient.dio.httpClientAdapter = realAdapter;
    await harness.tearDown();
  });

  setUp(() async {
    stub = _StubAdapter();
    ApiClient.dio.httpClientAdapter = stub;
    ReferenceSync.forgetLastFailure();
    await ReferenceCache.instance.clearAll();
  });

  String customerRecord(String id, String name) => jsonEncode({
        'entityType': 'Customer',
        'entityId': id,
        'updatedAt': '2026-09-17T09:00:00Z',
        'payloadJson': jsonEncode({'id': id, 'nameBn': name, 'isActive': true}),
      });

  Map<String, dynamic> pullBody(List<String> records, {bool hasMore = false}) =>
      {
        'records': records.map((r) => jsonDecode(r)).toList(),
        'hasMore': hasMore,
      };

  group('a clean pull', () {
    setUp(() {
      stub
        ..on('/sync/capabilities', [
          {'module': 'customers', 'entityType': 'Customer'},
        ])
        ..on(
            '/sync/customers/pull',
            pullBody([customerRecord('c1', 'রহিম স্টোর')]))
        ..on('/sync/customers/pull-complete', <String, dynamic>{});
    });

    test('records land in the cache and the watermark is advanced', () async {
      final outcomes = await ReferenceSync.syncAll();

      expect(outcomes.single.module, 'customers');
      expect(outcomes.single.recordCount, 1);
      expect(outcomes.single.caughtUp, isTrue);

      expect(CustomerRecord.byId('c1')?.name, 'রহিম স্টোর');
      expect(stub.paths, contains('/sync/customers/pull-complete'));
    });

    test('leaves no reason behind, so an empty list means an empty list',
        () async {
      ReferenceSync.rememberFailureForTest(
          const SyncAttemptFailure(isNetwork: true));

      await ReferenceSync.syncAll();

      expect(ReferenceSync.troubleSentence, isNull);
    });
  });

  group('⛔ the watermark rule', () {
    test('a batch with hasMore is stored but NOT marked complete', () async {
      stub
        ..on('/sync/capabilities', [
          {'module': 'customers', 'entityType': 'Customer'},
        ])
        ..on('/sync/customers/pull',
            pullBody([customerRecord('c1', 'রহিম')], hasMore: true))
        ..on('/sync/customers/pull-complete', <String, dynamic>{});

      final outcomes = await ReferenceSync.syncAll();

      // The data is good and is kept — the push side keeps unsent rows the
      // same way.
      expect(CustomerRecord.byId('c1'), isNotNull);
      expect(outcomes.single.caughtUp, isFalse);

      // ⛔ The one that matters. pull-complete moves the watermark to *now*,
      // not to this batch's cutoff. Called here, every record between the
      // cutoff and now — including the ones the page limit left out — would
      // be marked synced without ever being pulled, and never sent again.
      expect(stub.paths, isNot(contains('/sync/customers/pull-complete')));
    });

    test('an unreadable entity type also withholds the watermark', () async {
      stub
        ..on('/sync/capabilities', [
          {'module': 'customers', 'entityType': 'Customer'},
        ])
        ..on('/sync/customers/pull', {
          'records': [jsonDecode(customerRecord('c1', 'রহিম'))],
          'hasMore': false,
          // The server read everything it had queued, but one entity type's
          // handler threw. A 200 with real records in it is still not a
          // complete delta.
          'unreadable': ['Price'],
        })
        ..on('/sync/customers/pull-complete', <String, dynamic>{});

      final outcomes = await ReferenceSync.syncAll();

      expect(outcomes.single.caughtUp, isFalse);
      expect(outcomes.single.unreadableEntityTypes, ['Price']);
      expect(stub.paths, isNot(contains('/sync/customers/pull-complete')));
    });
  });

  group('when it fails', () {
    test('a refused capabilities call is recorded, then rethrown', () async {
      // 403 rather than 401 on purpose: a 401 goes down ApiClient's token
      // refresh path, which is a different mechanism with its own test. The
      // question here is only what syncAll does with a refusal.
      stub.fail('/sync/capabilities', 403);

      // Rethrown, because swallowing it would make an outright failure
      // indistinguishable from "nothing to sync" — a "সিঙ্ক করুন" button
      // reporting "0 records, all caught up" for a server that never answered.
      await expectLater(ReferenceSync.syncAll(), throwsA(isA<DioException>()));

      // And recorded on the way past, which is what the list screens read.
      expect(ReferenceSync.troubleSentence, contains('403'));
      expect(ReferenceSync.troubleSentence, contains('অফিসে জানান'));
    });

    test('one module failing does not take the others down', () async {
      stub
        ..on('/sync/capabilities', [
          {'module': 'customers', 'entityType': 'Customer'},
          {'module': 'products', 'entityType': 'Product'},
        ])
        ..fail('/sync/customers/pull', 500)
        ..on('/sync/products/pull',
            pullBody([customerRecord('c9', 'পণ্যের সাথে আসা গ্রাহক')]))
        ..on('/sync/products/pull-complete', <String, dynamic>{});

      final outcomes = await ReferenceSync.syncAll();

      expect(outcomes.length, 2);
      final failed = outcomes.firstWhere((o) => o.module == 'customers');
      final worked = outcomes.firstWhere((o) => o.module == 'products');
      expect(failed.caughtUp, isFalse);
      expect(failed.recordCount, 0);
      expect(worked.recordCount, 1);

      // The failing module did not stop the working one from finishing.
      expect(stub.paths, contains('/sync/products/pull-complete'));
      // And the reason survives for the screen to show.
      expect(ReferenceSync.troubleSentence, contains('500'));
    });

    test('a malformed record does not throw the module away', () async {
      stub
        ..on('/sync/capabilities', [
          {'module': 'customers', 'entityType': 'Customer'},
        ])
        ..on('/sync/customers/pull', {
          'records': [
            // No payloadJson — skipped, not fatal.
            {'entityType': 'Customer', 'entityId': 'bad'},
            jsonDecode(customerRecord('c1', 'রহিম স্টোর')),
          ],
          'hasMore': false,
        })
        ..on('/sync/customers/pull-complete', <String, dynamic>{});

      await ReferenceSync.syncAll();

      expect(CustomerRecord.byId('c1'), isNotNull);
      expect(CustomerRecord.byId('bad'), isNull);
    });
  });
}

/// Answers dio without a network, so the request sequence itself is the thing
/// under test.
class _StubAdapter implements HttpClientAdapter {
  final Map<String, Object> _bodies = {};
  final Map<String, int> _failures = {};

  /// Every path this pull actually asked for, in order — the assertion target
  /// for "pull-complete was not called".
  final List<String> paths = [];

  /// [body] is encoded as-is — GET /sync/capabilities answers with a bare
  /// JSON array, the pulls with an object, and a stub that could only produce
  /// one of those shapes would be testing a server that does not exist.
  void on(String path, Object body) => _bodies[path] = body;

  void fail(String path, int statusCode) => _failures[path] = statusCode;

  @override
  Future<ResponseBody> fetch(RequestOptions options, Stream<Uint8List>? stream,
      Future<void>? cancelFuture) async {
    final path = options.path;
    paths.add(path);

    final failure = _failures[path];
    if (failure != null) {
      return ResponseBody.fromString(
        jsonEncode({'message': 'refused by the stub'}),
        failure,
        headers: {
          Headers.contentTypeHeader: [Headers.jsonContentType],
        },
      );
    }

    return ResponseBody.fromString(
      jsonEncode(_bodies[path] ?? const <String, dynamic>{}),
      200,
      headers: {
        Headers.contentTypeHeader: [Headers.jsonContentType],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
