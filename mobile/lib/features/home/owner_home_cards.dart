import 'package:flutter/material.dart';

import '../../core/approvals/approvals_api.dart';
import '../../core/records/approval_record.dart';
import '../../core/records/money.dart';
import '../../core/records/today_record.dart';
import '../../core/status/app_status_chip.dart';
import '../../core/status/paper_state.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/customer_line.dart';
import '../../core/widgets/state_views.dart';

/// ⭐ মালিকের হোম, ০.৪.৪১ — মালিকের অনুমোদিত পুরো-পাতার নমুনা (২০ অক্টোবর ২০২৬, পাতা ২: "ok ei planei kore felo")।
/// ভিতরের তিন কার্ড: টাকার বড় কার্ড (হাতে ও ব্যাংকে মোট · নগদ · ব্যাংক · আজ এসেছে · বকেয়া), সইয়ের অপেক্ষায় থাকা কাগজ, আর
/// শাখা ধরে আজকের বিক্রি — নিচে মোট।
///
/// <p>⛔ সার্ভার যা পাঠায়নি তা দেখানো হয় না: না-আসা অঙ্ক "—", কখনো ৳0.00 নয় (TodayRecord-এর নিয়ম ক — শূন্য এমন একটা অঙ্ক
/// যার উপর মানুষ কাজ করে; "আজ নগদ নেই" আর "নগদ দেখার অনুমতি নেই" আলাদা কথা)।
///
/// রং কেবল থিম আর [AppPalette] থেকে; চাপার জায়গা ৪৮dp বা বেশি; বড় অক্ষরে (২০০%) সারি নিজের মাপে বাড়ে।

/// মালিকের ব্লক (টাকার বাক্স, আজকের ইনফ্লো, দেনা) এলে তবেই এই হোম — পুরনো সার্ভার বা অন্য লোকের জন্য আগের কার্ড।
bool isOwnersHome(TodayRecord today) => today.inflow != null || today.payable != null || today.money != null;

/// এক শাখার আজকের বিক্রি।
class BranchSale {
  const BranchSale(this.branch, this.amount);

  final String branch;
  final double amount;
}

/// শাখা ধরে আজকের বিক্রি — সার্ভারের `salesByBranch: [{branch, amount}]`। ⚠️ আজকের সার্ভার এটা পাঠায় না: তখন null, আর
/// কার্ডে কেবল সার্ভারের মোট বিক্রি (`sales.amount`) বসে — শাখার সারি বানিয়ে নেওয়া হয় না।
List<BranchSale>? branchSalesOf(TodayRecord today) {
  final list = today.payload['salesByBranch'];
  if (list is! List) return null;
  return [
    for (final row in list)
      if (row is Map && Money.value(row['amount']) != null)
        // ⓘ শাখাহীন বিলের সারি (`branch_id: null`) — "শাখা নেই" (fe, ২০ অক্টোবর ২০২৬)
        BranchSale(_branchName(row), Money.value(row['amount'])!),
  ];
}

String _branchName(Map row) {
  final name = (row['branch'] ?? row['name'] ?? '').toString().trim();
  return name.isEmpty ? 'শাখা নেই' : name;
}

/// সারির যোগফল — পয়সায় গুনে, যাতে ভগ্নাংশের ভুল মোটে না জমে।
double sumOf(Iterable<double> values) => values.fold<int>(0, (sum, v) => sum + (v * 100).round()) / 100;

/// না-আসা অঙ্ক "—"; আসা অঙ্ক [Money.taka]।
String figureText(double? value) => value == null ? '—' : Money.taka(value);

/// TalkBack-এর জন্য: "হাতে নগদ, ১২ হাজার টাকা"; না-আসা অঙ্কে "জানা নেই"।
String figureSpoken(String label, double? value) => '$label, ${value == null ? 'জানা নেই' : Money.spoken(value)}';

/// ── ১ · টাকার বড় কার্ড ──
class OwnerMoneyCard extends StatelessWidget {
  const OwnerMoneyCard({super.key, required this.today, this.onTap});

  final TodayRecord today;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final dark = Theme.of(context).brightness == Brightness.dark;
    // ⓘ নমুনার গাঢ় কার্ড: আলোতে থিমের primary-র উপর onPrimary, অন্ধকারে primaryContainer-এর উপর onPrimaryContainer
    final bg = dark ? cs.primaryContainer : cs.primary;
    final fg = dark ? cs.onPrimaryContainer : cs.onPrimary;
    final money = today.money;
    final cash = money?.cash ?? today.cashInHand?.amount;
    final bank = money?.bank;
    final cameIn = (today.inflow ?? today.collections)?.amount;
    final dues = today.dues?.amount;
    final extra = [
      if (money?.mfs case final mfs? when mfs != 0) 'MFS ${Money.taka(mfs)}',
      if (money?.transit case final t? when t != 0) 'পথে ${Money.taka(t)}',
    ].join(' · ');

    Widget cell(String key, String label, double? value, {String sign = ''}) => Semantics(
          key: ValueKey('owner-money-$key'),
          container: true,
          label: figureSpoken(label, value),
          excludeSemantics: true,
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(label, style: TextStyle(fontSize: 12.5, color: fg)),
            Text('$sign${figureText(value)}',
                key: ValueKey('owner-money-$key-value'),
                style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: fg)),
          ]),
        );

    final cells = [
      cell('cash', 'হাতে নগদ', cash),
      cell('bank', 'ব্যাংকে', bank),
      cell('in', 'আজ এসেছে', cameIn, sign: cameIn != null && cameIn > 0 ? '+' : ''),
      cell('dues', 'বাজারে বকেয়া', dues),
    ];

    return Card(
      key: const ValueKey('owner-money-card'),
      color: bg,
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: ConstrainedBox(
          constraints: const BoxConstraints(minHeight: 48),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Semantics(
                container: true,
                label: figureSpoken('হাতে ও ব্যাংকে মোট', money?.amount),
                excludeSemantics: true,
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text('হাতে ও ব্যাংকে মোট', style: TextStyle(fontSize: 13, color: fg)),
                  // ⓘ বড় অক্ষরে অঙ্ক মাঝখানে ভাঙে না — জায়গা না থাকলে কেবল এই এক লাইন ছোট হয়
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    alignment: Alignment.centerLeft,
                    child: Text(figureText(money?.amount),
                        key: const ValueKey('owner-money-total-value'),
                        style: TextStyle(fontSize: 30, fontWeight: FontWeight.w700, color: fg)),
                  ),
                ]),
              ),
              const SizedBox(height: AppSpacing.sm),
              // ⓘ দুই কলাম; বড় অক্ষরে (১৫০%-এর বেশি) এক কলাম — অঙ্ক কাটে না
              if (MediaQuery.textScalerOf(context).scale(10) > 15)
                for (final c in cells) Padding(padding: const EdgeInsets.only(bottom: 6), child: c)
              else
                for (var i = 0; i < cells.length; i += 2)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 6),
                    child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Expanded(child: cells[i]),
                      const SizedBox(width: AppSpacing.sm),
                      Expanded(child: cells[i + 1]),
                    ]),
                  ),
              if (extra.isNotEmpty)
                Text('মোটের মধ্যে $extra',
                    key: const ValueKey('owner-money-extra'), style: TextStyle(fontSize: 12, color: fg)),
            ]),
          ),
        ),
      ),
    );
  }
}

/// কার্ডের মাথা: শিরোনাম বাঁয়ে, "সব দেখুন →" ডানে (৪৮dp)।
class HomeSectionCard extends StatelessWidget {
  const HomeSectionCard({super.key, required this.title, this.actionLabel, this.onAction, required this.children});

  final String title;
  final String? actionLabel;
  final VoidCallback? onAction;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    return Card(
      margin: const EdgeInsets.only(bottom: AppSpacing.sm),
      shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14), side: BorderSide(color: context.palette.border)),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 4, 4, 6),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Expanded(
              child: Semantics(
                header: true,
                child: Text(title, style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: cs.onSurface)),
              ),
            ),
            if (actionLabel != null && onAction != null)
              TextButton.icon(
                style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
                onPressed: onAction,
                // ⓘ তীর আইকনে, লেখার পরে — "→" অক্ষর সব ফোনের ফন্টে নেই
                iconAlignment: IconAlignment.end,
                icon: const Icon(Icons.arrow_forward, size: 16),
                label: Text(actionLabel!, style: const TextStyle(fontSize: 12.5)),
              )
            else
              const SizedBox(height: 48),
          ]),
          Padding(
            padding: const EdgeInsets.only(right: 8),
            child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              for (var i = 0; i < children.length; i++) ...[
                if (i > 0) Divider(height: 1, color: context.palette.border),
                children[i],
              ],
            ]),
          ),
        ]),
      ),
    );
  }
}

/// একটা লাইন: নাম বাঁয়ে, টাকা ডানে (w700)। মোটের লাইন মোটা। জায়গা না হলে টাকা নিচের লাইনে ডানে নামে — কাটে না।
class AmountLine extends StatelessWidget {
  const AmountLine(
      {super.key, required this.label, required this.amount, this.total = false, this.labelIsName = false});

  final String label;
  final double? amount;
  final bool total;

  /// গ্রাহকের নাম হলে [CustomerLine] — "নাম · পয়েন্ট · শাখা", লম্বা হলে "…"
  final bool labelIsName;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final style = TextStyle(fontSize: 13.5, fontWeight: total ? FontWeight.w700 : FontWeight.w400, color: cs.onSurface);
    return Semantics(
      container: true,
      label: figureSpoken(label, amount),
      excludeSemantics: true,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 48),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: Row(children: [
            Expanded(flex: 3, child: labelIsName ? CustomerLine(label, style: style) : Text(label, style: style)),
            const SizedBox(width: AppSpacing.sm),
            // ⓘ টাকা ডানে (নকশার নিয়ম §৩), সারির ৪০%-এর বেশি নেয় না; বড় অক্ষরে মোড়ে, কাটে না
            Flexible(
              flex: 2,
              child: Align(
                alignment: Alignment.centerRight,
                child: Text(figureText(amount),
                    textAlign: TextAlign.end,
                    style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: cs.onSurface)),
              ),
            ),
          ]),
        ),
      ),
    );
  }
}

/// ── ২ · সইয়ের অপেক্ষায় ──
///
/// <p>সারি সার্ভারের সইয়ের সারি থেকে ([ApprovalsApi.pending]); এখানে প্রথম তিনটা। একটা চাপলে বা "সব দেখুন" চাপলে অনুমোদনের পাতা।
class WaitingPapersCard extends StatefulWidget {
  const WaitingPapersCard({super.key, required this.load, this.count, this.onOpenAll, this.shown = 3});

  final Future<ApprovalPage> Function() load;

  /// সার্ভারের `approvals.pending` — কতটা অপেক্ষায় (তালিকার মাথায়)
  final int? count;
  final VoidCallback? onOpenAll;
  final int shown;

  @override
  State<WaitingPapersCard> createState() => _WaitingPapersCardState();
}

class _WaitingPapersCardState extends State<WaitingPapersCard> {
  late Future<ApprovalPage> _future = widget.load();

  void _retry() => setState(() => _future = widget.load());

  @override
  Widget build(BuildContext context) {
    final count = widget.count;
    return HomeSectionCard(
      key: const ValueKey('waiting-papers'),
      title: count == null ? 'সইয়ের অপেক্ষায়' : 'সইয়ের অপেক্ষায় · $countটি',
      actionLabel: 'সব দেখুন',
      onAction: widget.onOpenAll,
      children: [
        FutureBuilder<ApprovalPage>(
          future: _future,
          builder: (context, snap) {
            if (snap.connectionState != ConnectionState.done) return const LoadingSkeleton(rows: 2);
            if (snap.hasError) {
              return ErrorState(message: 'সইয়ের কাগজ আনা গেল না। নেট দেখে আবার চেষ্টা করুন।', onRetry: _retry);
            }
            final rows = snap.data!.rows.take(widget.shown).toList();
            if (rows.isEmpty) {
              return Padding(
                padding: const EdgeInsets.symmetric(vertical: 12),
                child: Text('এখন কিছু সইয়ের অপেক্ষায় নেই।',
                    style: TextStyle(fontSize: 13, color: Theme.of(context).colorScheme.onSurfaceVariant)),
              );
            }
            return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              for (var i = 0; i < rows.length; i++) ...[
                if (i > 0) Divider(height: 1, color: context.palette.border),
                WaitingPaperRow(record: rows[i], onTap: widget.onOpenAll),
              ],
            ]);
          },
        ),
      ],
    );
  }
}

/// "নাম · পয়েন্ট · শাখা" লেখাকে নাম আর জায়গায় ভাগ — নমুনার মতো নাম প্রথম লাইনে, জায়গা নিচে।
(String, String?) splitPlace(String label) {
  final at = label.indexOf(' · ');
  return at < 0 ? (label, null) : (label.substring(0, at), label.substring(at + 3));
}

/// ⭐ তালিকার কাগজের সারি (০.৪.৪১ নমুনা; a4: বাঁয়ে নম্বর · পক্ষ, ডানে চিপ): প্রথম লাইনে "নম্বর · নাম", নিচে
/// "পয়েন্ট · শাখা" আর বাকি কথা; ডানে টাকা (থাকলে) আর তার নিচে a6-এর [AppStatusChip.compact]। পুরো সারি চাপা যায়
/// (অন্তত ৫৬dp)। TalkBack: সারি বলে নম্বর, পুরো নাম আর টাকা কথায়; চিপ নিজে পুরো অবস্থার বাক্য বলে।
class PaperRow extends StatelessWidget {
  const PaperRow(
      {super.key, required this.no, required this.party, this.detail, this.amount, required this.chip, this.onTap});

  final String no;

  /// "নাম · পয়েন্ট · শাখা" ([withPoint] / [CustomerRecord.label])
  final String party;
  final String? detail;
  final double? amount;
  final Widget chip;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final (name, place) = splitPlace(party);
    final first = [no, name].where((s) => s.isNotEmpty).join(' · ');
    final second = [if (place != null) place, if (detail != null) detail!].where((s) => s.isNotEmpty).join(' · ');
    final spoken = [
      [no, party].where((s) => s.isNotEmpty).join(' · '),
      if (detail != null && detail!.isNotEmpty) Money.speakable(detail!),
      if (amount != null) Money.spoken(amount)!,
    ].join(', ');
    return InkWell(
      onTap: onTap,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 56),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: LayoutBuilder(
            builder: (context, box) => Row(children: [
              Expanded(
                child: Semantics(
                  container: true,
                  button: onTap != null,
                  label: spoken,
                  excludeSemantics: true,
                  child:
                      Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                    CustomerLine(first,
                        style: TextStyle(fontSize: 13.5, fontWeight: FontWeight.w600, color: cs.onSurface)),
                    if (second.isNotEmpty) Text(second, style: TextStyle(fontSize: 12, color: cs.onSurfaceVariant)),
                  ]),
                ),
              ),
              const SizedBox(width: AppSpacing.sm),
              // ⓘ টাকা আর চিপ ডানের কিনারে, সারির ৪৫%-এর বেশি নয় — বড় অক্ষরে মোড়ে, ছাপায় না
              ConstrainedBox(
                constraints: BoxConstraints(maxWidth: box.maxWidth * 0.45),
                child: Column(crossAxisAlignment: CrossAxisAlignment.end, mainAxisSize: MainAxisSize.min, children: [
                  if (amount != null)
                    ExcludeSemantics(
                      child: Text(Money.taka(amount),
                          textAlign: TextAlign.end,
                          style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: cs.onSurface)),
                    ),
                  if (amount != null) const SizedBox(height: 2),
                  chip,
                ]),
              ),
            ]),
          ),
        ),
      ),
    );
  }
}

/// একটা সইয়ের কাগজ — নমুনার মতো টাকা নিচের লাইনে, ডানে কেবল চিপ।
class WaitingPaperRow extends StatelessWidget {
  const WaitingPaperRow({super.key, required this.record, this.onTap});

  final ApprovalRecord record;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final level = record.currentLevel;
    return PaperRow(
      key: ValueKey('waiting-${record.id}'),
      no: record.documentNo ?? '',
      party: record.party ?? record.summary ?? record.documentTypeLabel,
      detail: record.amount == null ? record.documentTypeLabel : Money.taka(record.amount),
      // ⚠️ সার্ভার মোট ধাপ (levels) পাঠায় না — তাই a6-এর চিপ "2-এর 1" নয়, "সইয়ের অপেক্ষায়" বলে; levels এলে নিজেই বসবে
      chip: AppStatusChip.compact(
          state: PaperState.waiting,
          level: level > 0 ? level : null,
          levels: (record.payload['levels'] as num?)?.toInt(),
          kind: paperKindOf(record.documentType)),
      onTap: onTap,
    );
  }
}

/// ⭐ কাগজের ধরন, সার্ভারের `documentType` থেকে — a6-এর চিপ v4 কথা বদলায় ধরন মেনে (fe, ২০ অক্টোবর ২০২৬): খরচের দাবি
/// [PaperKind.claim]; চালান, ডেলিভারি, মজুদ বদলি আর মাল-গ্রহণ [PaperKind.stock]; বাকি সব (আদায়, বিল, ভাউচার) টাকার।
PaperKind paperKindOf(String? documentType) {
  final t = (documentType ?? '').toLowerCase().replaceAll(RegExp(r'[^a-z]'), '');
  if (t.contains('claim')) return PaperKind.claim;
  const stock = ['challan', 'delivery', 'stock', 'transfer', 'goodsreceipt', 'grn', 'gatepass'];
  return stock.any(t.contains) ? PaperKind.stock : PaperKind.money;
}

/// ── ৩ · আজকের বিক্রি, শাখা ধরে ──
class BranchSalesCard extends StatelessWidget {
  const BranchSalesCard({super.key, required this.today, this.onOpen});

  final TodayRecord today;
  final VoidCallback? onOpen;

  @override
  Widget build(BuildContext context) {
    final lines = branchSalesOf(today);
    // ⭐ শাখার সারি এলে মোট = সারির যোগফল; না এলে সার্ভারের মোট বিক্রি, না-আসা হলে "—"
    final total = lines == null || lines.isEmpty ? today.sales?.amount : sumOf(lines.map((l) => l.amount));
    return HomeSectionCard(
      key: const ValueKey('branch-sales'),
      // ⓘ ফেরত বাদ না দেওয়া মোট বিল — তাই "আজ বিল হয়েছে", "বিক্রি" নয় (fe, ২০ অক্টোবর ২০২৬; IFRS 15: আয় ফেরত বাদে নিট)
      title: 'আজ বিল হয়েছে',
      actionLabel: 'বিস্তারিত',
      onAction: onOpen,
      children: [
        for (final l in lines ?? const <BranchSale>[])
          AmountLine(
              key: ValueKey('branch-sale-${l.branch}'), label: l.branch.isEmpty ? '—' : l.branch, amount: l.amount),
        AmountLine(key: const ValueKey('branch-sales-total'), label: 'মোট', amount: total, total: true),
      ],
    );
  }
}

/// হোমের তিন কার্ড একসাথে — [TodayPanel.figures]-এ বসে।
List<Widget> ownerHomeCards(
  TodayRecord today, {
  Future<ApprovalPage> Function()? loadWaiting,
  VoidCallback? onOpenApprovals,
  VoidCallback? onOpenSales,
  VoidCallback? onOpenMoney,
}) =>
    [
      OwnerMoneyCard(today: today, onTap: onOpenMoney),
      if (today.pendingApprovals == null)
        const SizedBox.shrink()
      else if (loadWaiting == null)
        // ⓘ সারি আনার পথ না থাকলে কেবল সার্ভারের গোনা — নাম বানিয়ে নেওয়া হয় না
        HomeSectionCard(
          key: const ValueKey('waiting-papers'),
          title: 'সইয়ের অপেক্ষায় · ${today.pendingApprovals}টি',
          actionLabel: 'সব দেখুন',
          onAction: onOpenApprovals,
          children: const [],
        )
      else
        WaitingPapersCard(
          // ⓘ নতুন হিসাব (টেনে আনা) এলে তালিকাও নতুন করে
          key: ValueKey('waiting-${today.asOf}'),
          load: loadWaiting,
          count: today.pendingApprovals,
          onOpenAll: onOpenApprovals,
        ),
      BranchSalesCard(today: today, onOpen: onOpenSales),
    ];
