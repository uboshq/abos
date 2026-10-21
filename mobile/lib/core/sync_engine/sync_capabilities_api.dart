import '../api_client/api_client.dart';
import '../locale/app_locale.dart';

/// `GET /sync/capabilities` — what this server can sync, **discovered rather
/// than assumed**.
///
/// The download side builds its plan from this list rather than from a
/// hardcoded module/entityType table, so a handler added or removed on the
/// server is reflected on the phone without a mobile release. That matters
/// more for ABOS than it looks: which entity types are syncable at all is a
/// business decision (see the offline-scope decision doc), and it will change
/// as modules land.
class SyncCapabilitiesApi {
  const SyncCapabilitiesApi._();

  static Future<List<SyncCapability>> list() async {
    final response =
        await ApiClient.dio.get<List<dynamic>>('/sync/capabilities');
    return (response.data ?? const [])
        .map((e) => SyncCapability.fromJson(e as Map<String, dynamic>))
        .toList();
  }
}

class SyncCapability {
  const SyncCapability({required this.module, required this.entityType});

  final String module;
  final String entityType;

  factory SyncCapability.fromJson(Map<String, dynamic> json) => SyncCapability(
        module: json['module'] as String? ?? '',
        entityType: json['entityType'] as String? ?? '',
      );
}

/// The module a queued change belongs to, in words.
///
/// <p>The server names them `sales`, `inventory`, `hr` — lowercase English.
/// Printed raw, a rep checking whether their day had gone up would read an
/// English word next to a Bangla timestamp.
///
/// <p>Falls back to the raw name, so a module added on the server shows up as
/// unfamiliar rather than absent.
String syncModuleLabel(String module) {
  final w = AppLocale.l10n;
  final labels = {
    'sales': w.syncModuleSales,
    'purchase': w.syncModulePurchase,
    'inventory': w.syncModuleInventory,
    'accounts': w.syncModuleAccounts,
    'finance': w.syncModuleFinance,
    'customer': w.syncModuleCustomer,
    'supplier': w.syncModuleSupplier,
    'hr': w.syncModuleHr,
  };
  return labels[module] ?? module;
}

/// What each catalogue on the sync screen holds, in words.
///
/// <p>Same reasoning as [syncModuleLabel]: the server sends its own entity
/// names, and those are English class names. This list is **not yet complete**
/// — it will be filled in against what `GET /sync/capabilities` actually
/// returns once the server side exists, rather than guessed ahead of it. The
/// fallback is what makes an unlisted name harmless in the meantime.
String syncEntityLabel(String entityType) {
  final w = AppLocale.l10n;
  final labels = {
    'Customer': w.syncEntityCustomer,
    'Supplier': w.syncEntitySupplier,
    'Product': w.syncEntityProduct,
    'ProductPrice': w.syncEntityProductPrice,
    'SalesOrder': w.syncEntitySalesOrder,
    'Collection': w.syncEntityCollection,
    'CustomerDue': w.syncEntityCustomerDue,
    'StockOnHand': w.syncEntityStockOnHand,
  };
  return labels[entityType] ?? entityType;
}
