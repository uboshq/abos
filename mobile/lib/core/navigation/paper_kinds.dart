import '../locale/app_locale.dart';

/// ⭐ ফোনের নিজের পর্দার লেনদেন-তালিকা — সতেরো রকম কাগজ (মালিক, ১৭ অক্টোবর ২০২৬; [PaperListScreen], [[PhonePaperApiController]])।
///
/// <p>⚠️ চাবিগুলো সার্ভারের `module.php`-এর `phone_papers`-এর একই — নতুন ধরন দুই দিকেই বসে। "সাম্প্রতিক" ([RecentPapers]) আর
/// কাগজের পথ (`/home/paper/<চাবি>/<id>`, [PaperRoute]) এখান থেকেই পড়ে।
class PaperKinds {
  const PaperKinds._();

  /// কাগজের চাবি → (মডিউল-সুইচের পথ [ModuleGate], বাংলা নাম)
  static Map<String, (String, String)> get all {
    final l = AppLocale.l10n;
    return {
      'purchase_order': ('purchases', l.paperKindPurchaseOrder),
      'purchase_bill': ('purchases', l.paperKindPurchaseBill),
      'purchase_receipt': ('purchases', l.paperKindGoodsReceipt),
      'purchase_return': ('purchases', l.paperKindPurchaseReturn),
      'purchase_requisition': ('purchases', l.paperKindRequisition),
      'purchase_rfq': ('purchases', l.paperKindRfq),
      'purchase_contract': ('purchases', l.paperKindSupplyContract),
      'sales_return': ('invoices', l.paperKindSalesReturn),
      'sales_gate_pass': ('invoices', l.paperKindGatePass),
      'sales_shipment': ('invoices', l.paperKindTrip),
      'sales_loading_sheet': ('invoices', l.paperKindLoadingSheet),
      'sales_transport': ('invoices', l.paperKindTransport),
      'sales_price_list_customer': ('invoices', l.paperKindPriceCustomer),
      'sales_price_list_tier': ('invoices', l.paperKindPriceTier),
      'sales_price_list_territory': ('invoices', l.paperKindPriceTerritory),
      'sales_price_list_all': ('invoices', l.paperKindPriceAll),
      'inventory_transfer': ('stock', l.paperKindStockTransfer),
      // ⭐ হিসাব আর অর্থ (১৭ অক্টোবর ২০২৬)
      'accounts_inter_company': ('vouchers', l.paperKindInterCompany),
      'finance_capital': ('deposits', l.paperKindCapital),
      'finance_insurance': ('deposits', l.paperKindInsurance),
    };
  }

  static String titleOf(String key) => all[key]?.$2 ?? AppLocale.l10n.paperKindFallback;

  /// কাগজের পথ — `paper/<চাবি>/<id>`, "সাম্প্রতিক"-এর একই পথ ([RecentPapers])
  static String pathOf(String key, String id) => 'paper/$key/${Uri.encodeComponent(id)}';
}

