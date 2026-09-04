import 'package:flutter_test/flutter_test.dart';
import 'package:hive/hive.dart';

import 'package:abos_mobile/core/sync_engine/sync_engine.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// An order taken with no signal must still be sitting on the phone
/// afterwards — this is the one behaviour the whole offline-first design
/// stands on (docs/Contract §০, owner's decision ১), so it is worth a real
/// Hive box rather than only the pure [reasonIfRejected] test.
///
/// <p>Run with `--dart-define=ABOS_API_BASE_URL=http://127.0.0.1:1/api/v1`:
/// [SyncEngine.enqueue] always attempts an immediate `flush`, and nothing
/// meaningful listens on that address for this app's own routes, so the
/// attempt fails fast instead of a slow real timeout against — or, worse,
/// actually reaching — the live server.
///
/// <p><b>One Hive box for the whole file, opened once in [setUpAll].</b>
/// [SyncEngine] is a singleton caching its own box handle; closing Hive
/// between individual `test()` cases (as a per-test setUp/tearDown would)
/// leaves that cached handle pointing at a box Hive itself now considers
/// closed, and the next call throws `HiveError: Box has already been
/// closed` — a real trap this file hit once already. Isolation between
/// cases comes from clearing the box's contents directly instead, by the one
/// name [SyncEngine] itself uses internally.
void main() {
  const queueBoxName = 'abos_sync_queue';
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await SyncEngine.instance.init();
  });

  tearDownAll(() async {
    await SyncEngine.instance.dispose();
    await harness.tearDown();
  });

  setUp(() async {
    if (Hive.isBoxOpen(queueBoxName)) {
      await Hive.box<Map>(queueBoxName).clear();
    }
  });

  test('an order taken offline stays queued after a failed send attempt',
      () async {
    expect(SyncEngine.instance.pendingCount, 0);

    await SyncEngine.instance.enqueue(
      module: 'sales',
      entityType: 'SalesOrder',
      operation: 'CREATE',
      payload: {
        'customerId': '01a0-test-customer',
        'items': [
          {'productId': '01a0-test-product', 'quantity': 3},
        ],
      },
    );

    // enqueue() awaits its own immediate flush attempt before returning;
    // with nothing meaningful behind the configured base URL that attempt
    // fails and the row is left in the queue rather than thrown away — the
    // queue draining "safe to call when offline" behaviour flush()'s own
    // doc comment describes.
    expect(SyncEngine.instance.pendingCount, 1);
    expect(SyncEngine.instance.rejectedCount, 0,
        reason: 'a network/transport failure is not the same as the server '
            'refusing the order — it must be retried, not surfaced as '
            'rejected');
  });

  test('two offline orders both survive, independently', () async {
    await SyncEngine.instance.enqueue(
      module: 'sales',
      entityType: 'SalesOrder',
      operation: 'CREATE',
      payload: {'customerId': 'customer-a', 'items': const []},
    );
    await SyncEngine.instance.enqueue(
      module: 'sales',
      entityType: 'SalesOrder',
      operation: 'CREATE',
      payload: {'customerId': 'customer-b', 'items': const []},
    );

    expect(SyncEngine.instance.pendingCount, 2);
  });
}
