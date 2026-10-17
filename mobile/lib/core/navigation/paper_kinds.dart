/// ⭐ ফোনের নিজের পর্দার লেনদেন-তালিকা — সতেরো রকম কাগজ (মালিক, ১৭ অক্টোবর ২০২৬; [PaperListScreen], [[PhonePaperApiController]])।
///
/// <p>⚠️ চাবিগুলো সার্ভারের `module.php`-এর `phone_papers`-এর একই — নতুন ধরন দুই দিকেই বসে। "সাম্প্রতিক" ([RecentPapers]) আর
/// কাগজের পথ (`/home/paper/<চাবি>/<id>`, [PaperRoute]) এখান থেকেই পড়ে।
class PaperKinds {
  const PaperKinds._();

  /// কাগজের চাবি → (মডিউল-সুইচের পথ [ModuleGate], বাংলা নাম)
  static const Map<String, (String, String)> all = {
    'purchase_order': ('purchases', 'ক্রয়াদেশ'),
    'purchase_bill': ('purchases', 'ক্রয় বিল'),
    'purchase_receipt': ('purchases', 'মাল গ্রহণ'),
    'purchase_return': ('purchases', 'ক্রয় ফেরত'),
    'purchase_requisition': ('purchases', 'ক্রয়ের চাহিদা'),
    'purchase_rfq': ('purchases', 'দর চাওয়া'),
    'purchase_contract': ('purchases', 'সরবরাহের চুক্তি'),
    'sales_return': ('invoices', 'বিক্রয় ফেরত'),
    'sales_gate_pass': ('invoices', 'গেট পাস'),
    'sales_shipment': ('invoices', 'ট্রিপ'),
    'sales_loading_sheet': ('invoices', 'লোডিং শিট'),
    'sales_transport': ('invoices', 'পরিবহন'),
    'sales_price_list_customer': ('invoices', 'গ্রাহকভিত্তিক দাম'),
    'sales_price_list_tier': ('invoices', 'ধরনভিত্তিক দাম'),
    'sales_price_list_territory': ('invoices', 'এলাকাভিত্তিক দাম'),
    'sales_price_list_all': ('invoices', 'বিশেষ দাম — সবার জন্য'),
    'inventory_transfer': ('stock', 'মাল স্থানান্তর'),
  };

  static String titleOf(String key) => all[key]?.$2 ?? 'কাগজ';

  /// কাগজের পথ — `paper/<চাবি>/<id>`, "সাম্প্রতিক"-এর একই পথ ([RecentPapers])
  static String pathOf(String key, String id) => 'paper/$key/${Uri.encodeComponent(id)}';
}

