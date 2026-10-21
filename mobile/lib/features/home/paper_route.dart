import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/auth/auth_state.dart';
import '../../core/locale/l10n.dart';
import '../../core/menu/module_gate.dart';
import '../../core/navigation/paper_kinds.dart';
import '../../core/navigation/recent_papers.dart';
import '../../core/orders/delivery_order_api.dart';
import '../../core/widgets/empty_state.dart';
import '../books/money_in_screens.dart';
import '../books/purchase_screens.dart';
import '../claims/claims_screens.dart';
import '../delivery_orders/delivery_order_screens.dart';
import '../quotations/quotation_screens.dart';
import '../sales_papers/sales_paper_screens.dart';
import '../vouchers/voucher_screens.dart';
import '../documents/document_screens.dart';
import '../finance/finance_screens.dart';
import '../papers/paper_screens.dart';
import '../self_service/self_service_screens.dart';

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
      // ⭐ বাকি মডিউল, দ্বিতীয় ভাগ (১৩ অক্টোবর ২০২৬)
      'deposit' => DepositScreen(id: id),
      'bank-loan' => LoanScreen(id: id),
      'rent' => RentScreen(side: extra == 'let' ? 'let' : 'pay', id: id),
      'hand-loan' => Consumer(
          builder: (context, ref, _) => HandLoanScreen(id: id, canMove: ref.watch(authStateProvider).user?.can('finance.hand_loan.move') ?? false)),
      'document' => DocumentScreen(id: id),
      'payslip' => PayslipScreen(id: id),
      'invoice' => SalesPaperScreen(kind: SalesPaperKind.invoice, id: id),
      'challan' => SalesPaperScreen(kind: SalesPaperKind.challan, id: id),
      // ⭐ ক্রয়, বিক্রয়ের বাকি কাগজ আর স্থানান্তর (মালিক, ১৭ অক্টোবর ২০২৬)
      _ when PaperKinds.all.containsKey(kind) => PaperScreen(paperKey: kind, id: id),
      _ => null,
    };
    if (list == null || screen == null) {
      return Scaffold(
        appBar: AbosScreenBar(title: context.l10n.homePaper),
        body: EmptyState(
          icon: Icons.description_outlined,
          title: context.l10n.homePaperCannotOpen,
          message: context.l10n.homeUpdateAndRetry,
        ),
      );
    }
    return ModuleGateView(path: list, child: screen);
  }
}
