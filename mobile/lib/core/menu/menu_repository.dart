import 'package:flutter/material.dart';

import '../auth/auth_user.dart';
import 'me_api.dart';
import 'menu_item.dart';
import 'route_registry.dart';

/// Where the signed-in person's home menu comes from: `GET /me`, filtered
/// twice before a row becomes a tile —
///
///  1. by the server, against that person's permissions (already done by the
///     time this file sees the response);
///  2. by [RouteRegistry], against what **this app build** actually has a
///     screen for — see that file's own doc comment for why the server
///     cannot do this filtering itself.
///
/// <p><b>"নতুন অর্ডার" is not one of `/me`'s menu rows</b> — the
/// coordinating session confirmed 4 September that placing an order is an
/// action inside the order list, gated by the `sales.order.create`
/// permission directly, not a navigable menu entry. This file adds that tile
/// itself, from the same `permissions` list every other check already reads,
/// rather than waiting for a menu row that will never arrive.
///
/// <p>Offline, `GET /me` cannot be called at all, and a blank home screen the
/// one time someone opens the app with no signal is worse than a slightly
/// stale menu — so [menuFor] falls back to [_localFallback], built from the
/// same `permissions` list already saved on this device by the last
/// successful login (see `SessionRepository`). The fallback is
/// permission-filtered the identical way, so it degrades to "the same menu,
/// no icons any fresher than the last sign-in" rather than to nothing.
class MenuRepository {
  const MenuRepository();

  static const _newOrderPermission = 'sales.order.create';

  /// One icon per app path segment — `/me` sends a label for every row but
  /// no icon, so the icon is this app's own, keyed by the path it already
  /// resolved the row to rather than by the server's route name (one path
  /// can only ever want one icon; the server's naming is not this app's
  /// concern here).
  static const Map<String, IconData> _iconByAppPath = {
    'customers': Icons.people_alt_outlined,
    'products': Icons.inventory_2_outlined,
    'stock': Icons.warehouse_outlined,
    'orders': Icons.receipt_long_outlined,
  };

  Future<List<MenuItem>> menuFor(AuthUser user) async {
    try {
      final response = await MeApi.fetch();
      final items = <MenuItem>[];
      for (final module in response.menu) {
        for (final entry in module.allEntries) {
          final appPath = RouteRegistry.appPathFor(entry.route);
          if (appPath == null) continue; // no screen for this yet — see class doc.
          items.add(MenuItem(
            key: entry.route,
            label: entry.label,
            icon: _iconByAppPath[appPath] ?? Icons.circle_outlined,
            routeName: appPath,
            planned: entry.planned,
          ));
        }
      }
      return _withSyntheticTiles(items, response.user);
    } catch (_) {
      return _withSyntheticTiles(_localFallback(user), user);
    }
  }

  List<MenuItem> _localFallback(AuthUser user) {
    final items = <MenuItem>[];
    const localLabels = {
      'customer.index': ('গ্রাহক', 'customer.view', Icons.people_alt_outlined),
      'sales.order.index': (
        'অর্ডারের অবস্থা',
        'sales.order.view',
        Icons.receipt_long_outlined
      ),
      'inventory.product.index': (
        'পণ্যের তালিকা',
        'inventory.product.view',
        Icons.inventory_2_outlined
      ),
      'inventory.stock.index': (
        'হাতে থাকা মজুদ',
        'inventory.stock.view',
        Icons.warehouse_outlined
      ),
    };
    localLabels.forEach((serverRoute, tuple) {
      final (label, permission, icon) = tuple;
      final appPath = RouteRegistry.appPathFor(serverRoute);
      if (appPath == null || !user.can(permission)) return;
      items.add(MenuItem(key: serverRoute, label: label, icon: icon, routeName: appPath));
    });
    return items;
  }

  /// Tiles that are not `/me` menu rows at all — see this class's own doc
  /// comment for "নতুন অর্ডার", and [_withSyntheticTiles]'s trailing comment
  /// for the sync-status tile every role gets regardless.
  List<MenuItem> _withSyntheticTiles(List<MenuItem> items, AuthUser user) => [
        ...items,
        if (user.can(_newOrderPermission))
          const MenuItem(
            key: 'sales.order.create',
            label: 'নতুন অর্ডার',
            icon: Icons.add_shopping_cart_outlined,
            routeName: 'new-order',
          ),
        // What this device has and has not sent is a fact about the phone,
        // not a business permission — every signed-in role can open it, live
        // menu or fallback alike.
        const MenuItem(
          key: 'sync_status',
          label: 'সিঙ্কের অবস্থা',
          icon: Icons.sync_outlined,
          routeName: 'sync-status',
        ),
      ];
}
