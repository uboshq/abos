import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/sync_engine/reference_cache.dart';
import 'package:abos_mobile/core/sync_engine/reference_sync.dart';
import 'package:flutter_test/flutter_test.dart';

import 'support/fake_secure_storage.dart';
import 'support/hive_test_harness.dart';

/// ⛔ Owner, 6 Oct 2026: "the app shows another branch's customers and their balances too" (0.4.20).
///
/// <p>The branch lives on the person: choosing one in the web header moves the phone's view too. The server now names
/// the view on every pull; the phone wipes what it holds before storing a row of a new view. The due list shows the
/// viewed branch's due (the web list's own figure); the credit check keeps the whole company's.
void main() {
  late HiveTestHarness harness;

  setUpAll(() async {
    FakeSecureStorage.install();
    harness = await HiveTestHarness.setUp();
    await ReferenceCache.instance.init();
  });

  tearDownAll(() async => harness.tearDown());

  setUp(() async => ReferenceCache.instance.clearAll());

  Future<void> shop(String id) => ReferenceCache.instance.put(
        entityType: CustomerRecord.entityType,
        entityId: id,
        payload: {'id': id, 'nameEn': 'Shop $id'},
        updatedAt: DateTime(2026, 10, 6),
      );

  test(
      'a new view wipes the other branch\'s shops before anything of it is stored; the same view keeps them',
      () async {
    expect(await ReferenceSync.adoptView('7:3'), isTrue,
        reason: 'a cache with no view yet is wiped once — today\'s mix');
    await shop('a');

    expect(await ReferenceSync.adoptView('7:3'), isFalse);
    expect(ReferenceCache.instance.countOf(CustomerRecord.entityType), 1,
        reason:
            '⛔ the same view wiped the cache — every pull would start empty');

    expect(await ReferenceSync.adoptView('7:4'), isTrue);
    expect(ReferenceCache.instance.countOf(CustomerRecord.entityType), 0,
        reason:
            '⛔ branch A\'s shop is still on the phone after the view moved to B');
    expect(ReferenceCache.instance.view, '7:4');
  });

  test('an older server that names no view changes nothing', () async {
    await ReferenceSync.adoptView('7:3');
    await shop('a');
    expect(await ReferenceSync.adoptView(null), isFalse);
    expect(ReferenceCache.instance.countOf(CustomerRecord.entityType), 1);
  });

  test(
      'the lists show the viewed branch\'s due, the credit check the whole company\'s',
      () {
    const due = CustomerDueRecord({
      'customerId': 'a',
      'outstanding': '140.00',
      'outstandingInView': '100.00',
      'creditLimit': '500',
    });
    expect(due.outstandingInView, 100);
    expect(due.outstandingLabel, contains('100'),
        reason: '⛔ the list counts the other branch\'s papers too');
    expect(due.outstanding, 140,
        reason: 'the limit is absolute — the whole company');
    expect(due.wholeOutstandingLabel, contains('140'));

    const old = CustomerDueRecord({'customerId': 'a', 'outstanding': '140.00'});
    expect(old.outstandingInView, 140,
        reason: 'an older server: the whole figure, as before');
  });
}
