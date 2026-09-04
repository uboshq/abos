/// Maps a server-side route **name** (`GET /me`'s `menu[].groups[].route`,
/// e.g. `sales.order.index`) to the path segment this app actually has a
/// screen for (`app_router.dart`'s `/home/<segment>`).
///
/// <p><b>Why a name and not a URL travels over the wire at all</b> — see
/// docs, the `/me` briefing: a URL would tie this app to the web side's own
/// addresses, so moving a route on the web would silently misdirect the
/// phone. A name is stable across that kind of change; this table is the
/// only place that ever has to catch up.
///
/// <p><b>This is this app's own filter, not the server's.</b> `/me` sends
/// every route the signed-in person's permissions allow, whether or not this
/// particular build has a screen behind it yet — see [MenuModule]'s own doc
/// comment. A row whose route is not a key here is simply left out of the
/// home grid; that is correct behaviour, not a gap to fill by guessing at a
/// screen for it.
///
/// <p>Confirmed 4 September against a live `sales@abos.test` (salesman)
/// `/me` response by the coordinating session:
///  - `customer.index`, `sales.order.index` — real, as guessed.
///  - `sales.direct.create`, `sales.collection.index`,
///    `sales.challan.index`, `sales.invoice.index`, `sales.return.index` —
///    real rows this app has no screen for yet, so deliberately absent
///    below; see the class doc comment for why that is correct rather than
///    a gap.
///
/// <p>`inventory.product.index` and `inventory.stock.index` remain this
/// app's own best guess — salesman lacks both permissions, so neither name
/// has appeared in a real payload yet. `inventory.stock.index` is kept
/// (never deleted, per the owner's own instruction) for the warehouse/
/// delivery roles that will actually receive it — a salesman's own `/me`
/// simply never sends that row, so it never appears on that phone regardless
/// of what this table contains.
class RouteRegistry {
  const RouteRegistry._();

  static const Map<String, String> _serverRouteToAppPath = {
    'customer.index': 'customers',
    'sales.order.index': 'orders',
    'inventory.product.index': 'products',
    'inventory.stock.index': 'stock',
  };

  /// The `/home/<segment>` this route name opens, or null if this app has no
  /// screen for it yet.
  static String? appPathFor(String serverRouteName) =>
      _serverRouteToAppPath[serverRouteName];
}
