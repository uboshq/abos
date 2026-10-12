import 'package:flutter/material.dart';

import '../../core/menu/module_gate.dart';
import '../../core/navigation/recent_papers.dart';
import '../../core/orders/delivery_order_api.dart';
import '../../core/widgets/empty_state.dart';
import '../books/money_in_screens.dart';
import '../books/purchase_screens.dart';
import '../claims/claims_screens.dart';
import '../delivery_orders/delivery_order_screens.dart';
import '../quotations/quotation_screens.dart';
import '../vouchers/voucher_screens.dart';

/// ⭐ `/home/paper/<kind>/<id>` — a paper reopened from "সাম্প্রতিক" (মালিক, ১২ অক্টোবর ২০২৬)।
///
/// <p>The very screen the paper's list opens, with that screen's own server
/// API, behind the same module switch as the list ([ModuleGateView] on the
/// list's path) — the server still answers for the paper itself.
class PaperRoute extends StatelessWidget {
  const PaperRoute({super.key, required this.kind, required this.id, this.extra});

  final String kind;
  final String id;

  /// The purchase's own kind (`?k=`).
  final String? extra;

  @override
  Widget build(BuildContext context) {
    final list = RecentPapers.kinds[kind]?.$1;
    final screen = switch (kind) {
      'do' => DeliveryOrderScreen(id: id),
      'order' =>
        DeliveryOrderScreen(id: id, api: const ServerDeliveryOrderApi(orders: true)),
      'quotation' => QuotationScreen(id: id),
      'money-in' => MoneyInScreen(id: id),
      'purchase' => PurchaseScreen(kind: extra ?? '', id: id),
      'voucher' => VoucherScreen(id: id),
      'claim' => ClaimScreen(id: id),
      _ => null,
    };
    if (list == null || screen == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('কাগজ')),
        body: const EmptyState(
          icon: Icons.description_outlined,
          title: 'এই কাগজটা এই অ্যাপে খোলা যায় না',
          message: 'অ্যাপটা হালনাগাদ করে আবার চেষ্টা করুন।',
        ),
      );
    }
    return ModuleGateView(path: list, child: screen);
  }
}
