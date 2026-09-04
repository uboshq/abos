import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';

/// `GET /me` cannot be reached from a test run (there is no server, and
/// deliberately no attempt to fake Dio here) — `MenuRepository.menuFor`
/// therefore always exercises its offline fallback in this suite, run with
/// `--dart-define=ABOS_API_BASE_URL=http://127.0.0.1:1/api/v1` (an address
/// nothing listens on) so that failure is immediate rather than a real
/// 15-second connect timeout against the actual server. That fallback is
/// itself permission-filtered the same way the live path is — see
/// menu_repository.dart's own class comment — so this is a real test of the
/// filtering rule, not a workaround around one.
void main() {
  test('menu draws only what permissions and the route registry both allow',
      () async {
    const repository = MenuRepository();

    const salesman = AuthUser(
      id: '1',
      name: 'Salesman',
      email: 'sales@abos.test',
      roles: ['salesman'],
      permissions: ['customer.view', 'sales.order.view'],
    );

    final items = await repository.menuFor(salesman);
    final keys = items.map((item) => item.key).toSet();

    // Present: what the permissions above allow.
    expect(keys, contains('customer.index'));
    expect(keys, contains('sales.order.index'));
    // Every role gets this regardless of permissions — a device fact, not a
    // business one.
    expect(keys, contains('sync_status'));

    // Absent: permissions this user does not have.
    expect(keys, isNot(contains('inventory.product.index')));
    expect(keys, isNot(contains('inventory.stock.index')));
    // Absent: "নতুন অর্ডার" is synthesized only from sales.order.create,
    // which this user does not hold.
    expect(keys, isNot(contains('sales.order.create')));
  });

  test('sales.order.create adds the New Order tile, not a menu row',
      () async {
    const repository = MenuRepository();

    const salesmanWhoCanCreate = AuthUser(
      id: '1',
      name: 'Salesman',
      email: 'sales@abos.test',
      roles: ['salesman'],
      permissions: [
        'customer.view',
        'sales.order.view',
        'sales.order.create',
      ],
    );

    final items = await repository.menuFor(salesmanWhoCanCreate);
    final newOrderTile =
        items.where((item) => item.key == 'sales.order.create');

    expect(newOrderTile, hasLength(1));
    expect(newOrderTile.single.routeName, 'new-order');
    expect(newOrderTile.single.planned, isFalse);
  });

  test('a user with no matching permissions gets only the sync-status tile',
      () async {
    const repository = MenuRepository();

    const noPermissions = AuthUser(
      id: '1',
      name: 'Nobody',
      email: 'nobody@abos.test',
      roles: [],
      permissions: [],
    );

    final items = await repository.menuFor(noPermissions);
    expect(items.map((item) => item.key), ['sync_status']);
  });
}
