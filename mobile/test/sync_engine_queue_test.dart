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
        // The shape SalesOrderSync::apply() actually reads — see
        // SalesOrderDraft. These fixtures used to say items/quantity, which
        // is the shape the server refuses with order_has_no_lines; a queue
        // test does not care, but a stale fixture is the next person's
        // evidence for what a queued order looks like.
        'lines': [
          {'productId': '01a0-test-product', 'qty': 3, 'rate': '42.5000'},
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
      payload: {'customerId': 'customer-a', 'lines': const []},
    );
    await SyncEngine.instance.enqueue(
      module: 'sales',
      entityType: 'SalesOrder',
      operation: 'CREATE',
      payload: {'customerId': 'customer-b', 'lines': const []},
    );

    expect(SyncEngine.instance.pendingCount, 2);
  });


  test('a collection cannot be queued offline, however it is asked for',
      () async {
    // docs/Contract §০, the owner's decision of 2 September 2026: with no
    // network, orders only — challans, bills, collections and POS are all
    // refused, because offline a phone knows neither the next number, nor
    // the shelf, nor the shop's due, nor today's price, and each of those
    // four needs all of them.
    //
    // <p>The app has no collection screen today, which is exactly why this
    // test exists: the door is open in SyncEngine.enqueue regardless, and the
    // day someone builds that screen this is what stops them — in front of
    // the person writing the code, rather than in front of a rep who watched
    // "অপেক্ষমাণ ১" sit on their phone and learned at sync that the money
    // they wrote down was never going anywhere.
    await expectLater(
      SyncEngine.instance.enqueue(
        module: 'sales',
        entityType: 'Collection',
        operation: 'CREATE',
        payload: const {'customerId': 'customer-a', 'amount': '500.0000'},
      ),
      throwsUnsupportedError,
    );

    expect(SyncEngine.instance.pendingCount, 0,
        reason: 'a refused change must leave nothing behind in the queue');
  });

  test('an order is still queued, so the guard refuses only what it should',
      () async {
    // The other half of the same assertion. A guard that refuses everything
    // would pass the test above and break the one thing the app is for —
    // which is the shape of blindness this suite spent the day removing.
    await SyncEngine.instance.enqueue(
      module: 'sales',
      entityType: 'SalesOrder',
      operation: 'CREATE',
      payload: const {
        'customerId': 'customer-a',
        'lines': [
          {'productId': 'p1', 'qty': 1, 'rate': '42.5000'},
        ],
      },
    );

    expect(SyncEngine.instance.pendingCount, 1);
  });
}
