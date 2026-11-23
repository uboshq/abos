import '../../core/locale/l10n.dart';

/// ⭐ The module a report belongs to, by name — the server sends its code (`ReportApiController`: `module?->code`, e.g.
/// "sales"), and the list showed that raw code in both languages (a4, 22 Oct 2026).
///
/// <p>The same words as the module tiles and the bar's "› বিক্রয় › …" ([NavCatalog.modules], the `navModule*` keys);
/// the server's modules the phone has no tile for use the web's module names (`reportModule*`). ⓘ An unknown code
/// shows itself, so a new server module is never blank.
const Map<String, String Function(AppL10n l)> reportModuleNames = {
  'sales': _sales,
  'purchase': _purchase,
  'inventory': _inventory,
  'accounts': _accounts,
  'finance': _finance,
  'customer': _customer,
  'supplier': _supplier,
  'approval': _approval,
  'hr': _hr,
  'documents': _documents,
  'executive': _executive,
  'promotion': _promotion,
  'credit_control': _creditControl,
  'notification': _notification,
  // ⭐ Copilot-এর নিজের টাইল (মালিক, ২৩ নভেম্বর ২০২৬) — টাইলের একই নাম
  'assistant': _assistant,
  'backup': _backup,
  'fixed_assets': _fixedAssets,
  'governance': _governance,
  'master_data': _masterData,
  'restaurant': _restaurant,
  'system_admin': _systemAdmin,
};

/// The name of the module [code] in [l]'s language; the code itself when the phone does not know it
String reportModuleName(String code, AppL10n l) => reportModuleNames[code]?.call(l) ?? code;

String _sales(AppL10n l) => l.navModuleSales;
String _purchase(AppL10n l) => l.navModulePurchase;
String _inventory(AppL10n l) => l.navModuleInventory;
String _accounts(AppL10n l) => l.navModuleAccounts;
String _finance(AppL10n l) => l.navModuleFinance;
String _customer(AppL10n l) => l.navModuleCustomer;
String _supplier(AppL10n l) => l.navModuleSupplier;
String _approval(AppL10n l) => l.navModuleApproval;
String _hr(AppL10n l) => l.navModuleHr;
String _documents(AppL10n l) => l.navModuleDocuments;
String _executive(AppL10n l) => l.navModuleExecutive;
String _promotion(AppL10n l) => l.navModulePromotion;
String _creditControl(AppL10n l) => l.navModuleCreditControl;
String _notification(AppL10n l) => l.navModuleNotification;
String _assistant(AppL10n l) => l.lookTabCopilot;
String _backup(AppL10n l) => l.reportModuleBackup;
String _fixedAssets(AppL10n l) => l.reportModuleFixedAssets;
String _governance(AppL10n l) => l.reportModuleGovernance;
String _masterData(AppL10n l) => l.reportModuleMasterData;
String _restaurant(AppL10n l) => l.reportModuleRestaurant;
String _systemAdmin(AppL10n l) => l.reportModuleSystemAdmin;
