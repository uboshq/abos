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
/// ভিতরের কার্ড: টাকার বড় কার্ড (হাতে ও ব্যাংকে মোট · নগদ · MFS · ব্যাংক · পথে · আজ এসেছে · আজ গেছে · বকেয়া), সইয়ের
/// অপেক্ষায় থাকা কাগজ, শাখা ধরে আজকের বিক্রি — নিচে মোট; দেনা আর প্রিন্সিপালের কমিশন (০.৪.৪০-এর অঙ্ক ফেরত, মালিক,
/// ২০ অক্টোবর ২০২৬)।
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
///
/// <p>⭐ ০.৪.৪০-এর অঙ্ক ফেরত (মালিক, ২০ অক্টোবর ২০২৬: "MFS অ্যাপের হোমে দেখায় না, আগে দেখাত"): মোটের নিচে চার ভাগ —
/// হাতে নগদ · MFS · ব্যাংকে · পথে — সবসময়, শূন্য হলে ৳0.00; তারপর একটা দাগ, আর আজ এসেছে · আজ গেছে · বাজারে বকেয়া।
/// ⛔ মোট = নগদ + MFS + ব্যাংক, সার্ভারের `money.amount` — পথের টাকা মোটে যোগ হয় না (ওয়েবেও না)।
class OwnerMoneyCard extends StatelessWidget {
  const OwnerMoneyCard({super.key, required this.today, this.onTap, this.onOpenStock});

  final TodayRecord today;
  final VoidCallback? onTap;

  /// মজুদের মূল্যে চাপলে মজুদের পাতা — মেনুতে যার সেই পাতা আছে কেবল তার; নইলে null, কেবল অঙ্ক
  final VoidCallback? onOpenStock;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final dark = Theme.of(context).brightness == Brightness.dark;
    // ⓘ নমুনার গাঢ় কার্ড: আলোতে থিমের primary-র উপর onPrimary, অন্ধকারে primaryContainer-এর উপর onPrimaryContainer
    final bg = dark ? cs.primaryContainer : cs.primary;
    final fg = dark ? cs.onPrimaryContainer : cs.onPrimary;
    final money = today.money;
    final cash = money?.cash ?? today.cashInHand?.amount;
    final cameIn = (today.inflow ?? today.collections)?.amount;
    final out = today.outflow;
    final duesFigure = today.dues;
    final mfsAccounts = money?.mfsAccounts;

    Widget figure(String key, String label, double? value, {String sign = '', String? under}) =>
        Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
          Text(label, style: TextStyle(fontSize: 12.5, color: fg)),
          Text('${value == null ? '' : sign}${figureText(value)}',
              key: ValueKey('owner-money-$key-value'),
              style: TextStyle(fontSize: 15, fontWeight: FontWeight.w700, color: fg)),
          if (under != null)
            Text(under, key: ValueKey('owner-money-$key-under'), style: TextStyle(fontSize: 11.5, color: fg)),
        ]);

    Widget cell(String key, String label, double? value, {String sign = '', String? under}) => Semantics(
          key: ValueKey('owner-money-$key'),
          container: true,
          label: [figureSpoken(label, value), if (under != null) Money.speakable(under)].join(', '),
          excludeSemantics: true,
          child: figure(key, label, value, sign: sign, under: under),
        );

    // ⭐ MFS-এর খাতগুলো সার্ভার পাঠালে MFS নিজে চাপা যায় — খাত ধরে তালিকা; পুরনো সার্ভারে কেবল লেখা
    final Widget mfsCell = mfsAccounts == null
        ? cell('mfs', 'MFS', money?.mfs)
        : Semantics(
            key: const ValueKey('owner-money-mfs'),
            container: true,
            button: true,
            label: figureSpoken('MFS', money?.mfs),
            hint: 'MFS খাতগুলো দেখুন',
            onTap: () => showMfsAccounts(context, mfsAccounts),
            excludeSemantics: true,
            child: InkWell(
              key: const ValueKey('owner-money-mfs-tap'),
              borderRadius: BorderRadius.circular(8),
              onTap: () => showMfsAccounts(context, mfsAccounts),
              child: ConstrainedBox(
                constraints: const BoxConstraints(minHeight: 48, minWidth: 48),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Flexible(child: figure('mfs', 'MFS', money?.mfs)),
                  const SizedBox(width: 4),
                  Icon(Icons.expand_more, size: 18, color: fg),
                ]),
              ),
            ),
          );

    final parts = [
      cell('cash', 'হাতে নগদ', cash),
      mfsCell,
      cell('bank', 'ব্যাংকে', money?.bank),
      cell('transit', 'পথে', money?.transit),
    ];
    final stock = today.stockValue;
    final flows = [
      // ⓘ ইনফ্লোর নিচে তার আদায়ের ভাগ — ০.৪.৪০-এর "আদায় ৳…"
      cell('in', 'আজ এসেছে', cameIn,
          sign: cameIn != null && cameIn > 0 ? '+' : '',
          under: today.inflow != null && today.collections != null
              ? 'আদায় ${figureText(today.collections!.amount)}'
              : null),
      // ⓘ নতুন অঙ্ক: পুরনো সার্ভার চাবিই পাঠায় না — তখন ঘরই নেই, "—" নয়
      if (out != null) cell('out', 'আজ গেছে', out.amount, sign: (out.amount ?? 0) > 0 ? '-' : ''),
      cell('dues', 'বাজারে বকেয়া', duesFigure?.amount,
          under: duesFigure?.shops == null ? null : '${duesFigure!.shops} টি দোকান'),
      // ⭐ বকেয়ার পাশে মজুদের মূল্য (মালিক, ২১ অক্টোবর ২০২৬) — সার্ভার না পাঠালে ঘরই নেই, "—"-ও নয়
      if (stock != null)
        onOpenStock == null
            ? cell('stock', 'মজুদের মূল্য', stock.amount, under: 'খরচ-দামে')
            : Semantics(
                key: const ValueKey('owner-money-stock'),
                container: true,
                button: true,
                label: '${figureSpoken('মজুদের মূল্য', stock.amount)}, খরচ-দামে',
                hint: 'মজুদের পাতা খুলুন',
                onTap: onOpenStock,
                excludeSemantics: true,
                child: InkWell(
                  key: const ValueKey('owner-money-stock-tap'),
                  borderRadius: BorderRadius.circular(8),
                  onTap: onOpenStock,
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(minHeight: 48, minWidth: 48),
                    child: figure('stock', 'মজুদের মূল্য', stock.amount, under: 'খরচ-দামে'),
                  ),
                ),
              ),
    ];
    // ⓘ দুই কলাম; বড় অক্ষরে (১৫০%-এর বেশি) এক কলাম — অঙ্ক কাটে না
    final oneColumn = MediaQuery.textScalerOf(context).scale(10) > 15;

    List<Widget> grid(List<Widget> cells) => [
          if (oneColumn)
            for (final c in cells) Padding(padding: const EdgeInsets.only(bottom: 6), child: c)
          else
            for (var i = 0; i < cells.length; i += 2)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Expanded(child: cells[i]),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(child: i + 1 < cells.length ? cells[i + 1] : const SizedBox.shrink()),
                ]),
              ),
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
              // ⓘ টাকার বাক্স এলে চার ভাগ সবসময় — পুরনো সার্ভারে বাক্স নেই, তখন কেবল নগদ
              if (money != null) ...grid(parts) else ...grid([parts.first]),
              Divider(key: const ValueKey('owner-money-divider'), height: 13, color: fg.withValues(alpha: 0.35)),
              ...grid(flows),
            ]),
          ),
        ),
      ),
    );
  }
}

/// ⭐ "MFS খাত" — প্রতিটা MFS খাত আর তার জের, নিচে মোট (সারির যোগফল, পয়সায় গোনা)।
Future<void> showMfsAccounts(BuildContext context, List<(String name, double? balance)> accounts) =>
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (context) => SafeArea(
        child: SingleChildScrollView(
          key: const ValueKey('mfs-accounts-sheet'),
          padding: const EdgeInsets.fromLTRB(AppSpacing.md, 0, AppSpacing.md, AppSpacing.md),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
            Semantics(
              header: true,
              child: Text('MFS খাত',
                  style: TextStyle(
                      fontSize: 16, fontWeight: FontWeight.w700, color: Theme.of(context).colorScheme.onSurface)),
            ),
            const SizedBox(height: AppSpacing.xs),
            for (var i = 0; i < accounts.length; i++) ...[
              if (i > 0) Divider(height: 1, color: context.palette.border),
              AmountLine(
                  key: ValueKey('mfs-account-$i'),
                  label: accounts[i].$1.isEmpty ? '—' : accounts[i].$1,
                  amount: accounts[i].$2),
            ],
            Divider(height: 1, thickness: 2, color: context.palette.border),
            AmountLine(
                key: const ValueKey('mfs-accounts-total'),
                label: 'মোট',
                amount: sumOf([for (final a in accounts) if (a.$2 != null) a.$2!]),
                total: true),
          ]),
        ),
      ),
    );

/// কার্ডের মাথা: শিরোনাম বাঁয়ে, "সব দেখুন →" ডানে (৪৮dp)।
class HomeSectionCard extends StatelessWidget {
  const HomeSectionCard(
      {super.key, required this.title, this.subtitle, this.subtitleKey, this.actionLabel, this.onAction, required this.children});

  final String title;

  /// শিরোনামের নিচে মলিন এক লাইন (যেমন "বিল ৳… · ফেরত ৳…")
  final String? subtitle;
  final Key? subtitleKey;
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
          if (subtitle != null)
            Padding(
              padding: const EdgeInsets.only(right: 8, bottom: 4),
              child: Text(subtitle!, key: subtitleKey, style: TextStyle(fontSize: 12.5, color: cs.onSurfaceVariant)),
            ),
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
      // ⓘ সার্ভার যা বলে তা-ই — নিট পাঠালে "নিট বিক্রি" (VAT আর ফেরত বাদে, IFRS 15); পুরনো সার্ভারের মোট বিল "আজ বিল হয়েছে"
      // ([TodayFigure.salesHeading]); নাম আর অঙ্ক কখনো আলাদা কথা বলে না
      title: today.sales?.salesHeading ?? 'আজ বিল হয়েছে',
      // ⭐ ০.৪.৪০-এর বিক্রির নিচের লাইন ফেরত — আজকের পাতার একই লেখা ([TodayFigure.salesDetail])
      subtitle: today.sales?.salesDetail,
      subtitleKey: const ValueKey('branch-sales-detail'),
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

/// ── ৪ · দেনা ── ০.৪.৪০-এর "দেনা (Payable)" ফেরত, হাতধারসহ (মালিক, ২০ অক্টোবর ২০২৬)
class PayableCard extends StatelessWidget {
  const PayableCard({super.key, required this.payable, this.handLoans});

  final TodayFigure payable;
  final double? handLoans;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final loans = handLoans ?? 0;
    final loansText = loans > 0 ? 'হাতধার ${Money.taka(loans)} সহ' : null;
    return HomeSectionCard(
      key: const ValueKey('payable-card'),
      title: 'দেনা',
      children: [
        Semantics(
          container: true,
          label: [figureSpoken('দেনা', payable.amount), if (loans > 0) 'হাতধার ${Money.spoken(loans)} সহ'].join(', '),
          excludeSemantics: true,
          child: Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerLeft,
                child: Text(figureText(payable.amount),
                    key: const ValueKey('payable-value'),
                    style: TextStyle(fontSize: 22, fontWeight: FontWeight.w700, color: context.palette.danger)),
              ),
              if (loansText != null)
                Text(loansText,
                    key: const ValueKey('payable-hand-loans'),
                    style: TextStyle(fontSize: 12.5, color: cs.onSurfaceVariant)),
            ]),
          ),
        ),
      ],
    );
  }
}

/// ── ৫ · প্রিন্সিপালের কমিশন ── ০.৪.৪০-এর কার্ড ফেরত: জের সবচেয়ে বড় (চিহ্ন ছাড়া) তিন প্রিন্সিপাল, নিচে সবার মোট।
///
/// <p>⛔ মোট সবার, কেবল দেখানো তিনজনের নয়: সার্ভারের `principalsTotal.balance` (ওয়েবের মোটের সারি, bcmath); পুরনো
/// সার্ভারে সব সারির জের যোগ করে।
class PrincipalsHomeCard extends StatelessWidget {
  const PrincipalsHomeCard({super.key, required this.lines, this.total, this.onOpenAll, this.shown = 3});

  final List<PrincipalLine> lines;
  final PrincipalLine? total;
  final VoidCallback? onOpenAll;
  final int shown;

  /// জের চিহ্ন ছাড়া সবচেয়ে বড় [count]টি — সমান হলে সার্ভারের ক্রম
  static List<PrincipalLine> topByBalance(List<PrincipalLine> lines, int count) {
    final indexed = [for (var i = 0; i < lines.length; i++) (i, lines[i])];
    indexed.sort((a, b) {
      final by = (b.$2.balance ?? 0).abs().compareTo((a.$2.balance ?? 0).abs());
      return by != 0 ? by : a.$1.compareTo(b.$1);
    });
    return [for (final e in indexed.take(count)) e.$2];
  }

  /// সবার জের — সার্ভারের মোট থাকলে সেটাই, নইলে সব সারির যোগ
  static double totalBalance(List<PrincipalLine> lines, PrincipalLine? total) =>
      total?.balance ?? sumOf([for (final l in lines) if (l.balance != null) l.balance!]);

  @override
  Widget build(BuildContext context) {
    final rows = topByBalance(lines, shown);
    final sum = PrincipalLine(name: 'মোট', period: '', basisRate: '', balance: totalBalance(lines, total));
    return HomeSectionCard(
      key: const ValueKey('principals-card'),
      title: 'প্রিন্সিপালের কমিশন',
      actionLabel: 'সব দেখুন',
      onAction: onOpenAll,
      children: [
        for (var i = 0; i < rows.length; i++) _PrincipalRow(key: ValueKey('principal-row-$i'), line: rows[i]),
        _PrincipalRow(key: const ValueKey('principals-total'), line: sum, total: true),
      ],
    );
  }
}

class _PrincipalRow extends StatelessWidget {
  const _PrincipalRow({super.key, required this.line, this.total = false});

  final PrincipalLine line;
  final bool total;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final balance = line.balance ?? 0;
    // ⓘ ০.৪.৪০-এর কার্ডের রং: দিতে হবে লাল, পাব বা জের নেই সবুজ
    final colour = balance > 0 ? context.palette.danger : context.palette.success;
    final commission =
        line.isLoss ? 'লোকসান ${Money.taka(-(line.commission ?? 0))}' : 'কমিশন ${figureText(line.commission)}';
    final detail = total ? null : 'এসেছে ${figureText(line.inflow)} · $commission';
    final name = line.name.isEmpty ? '—' : line.name;
    return Semantics(
      container: true,
      label: [name, if (detail != null) Money.speakable(detail), Money.speakable(line.balanceLabel)].join(', '),
      excludeSemantics: true,
      child: ConstrainedBox(
        constraints: const BoxConstraints(minHeight: 48),
        child: Padding(
          padding: const EdgeInsets.symmetric(vertical: 6),
          child: LayoutBuilder(
            builder: (context, box) => Row(children: [
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                  Text(name,
                      style: TextStyle(
                          fontSize: 13.5, fontWeight: total ? FontWeight.w700 : FontWeight.w600, color: cs.onSurface)),
                  if (detail != null) Text(detail, style: TextStyle(fontSize: 12, color: cs.onSurfaceVariant)),
                ]),
              ),
              const SizedBox(width: AppSpacing.sm),
              ConstrainedBox(
                constraints: BoxConstraints(maxWidth: box.maxWidth * 0.5),
                child: Text(line.balanceLabel,
                    key: total ? const ValueKey('principals-total-value') : null,
                    textAlign: TextAlign.end,
                    style: TextStyle(fontSize: 13.5, fontWeight: FontWeight.w700, color: colour)),
              ),
            ]),
          ),
        ),
      ),
    );
  }
}

/// হোমের কার্ড একসাথে — [TodayPanel.figures]-এ বসে: টাকার বড় কার্ড; সইয়ের অপেক্ষা | আজকের বিল; দেনা | প্রিন্সিপালের কমিশন।
List<Widget> ownerHomeCards(
  TodayRecord today, {
  Future<ApprovalPage> Function()? loadWaiting,
  VoidCallback? onOpenApprovals,
  VoidCallback? onOpenSales,
  VoidCallback? onOpenMoney,
  VoidCallback? onOpenPrincipals,
  VoidCallback? onOpenStock,
}) {
  final payable = today.payable;
  final principals = today.principals;
  final Widget? payableCard = payable == null ? null : PayableCard(payable: payable, handLoans: today.payableHandLoans);
  final Widget? principalsCard = principals == null || principals.isEmpty
      ? null
      : PrincipalsHomeCard(lines: principals, total: today.principalsTotal, onOpenAll: onOpenPrincipals);
  return [
    OwnerMoneyCard(today: today, onTap: onOpenMoney, onOpenStock: onOpenStock),
    WidePair(
      first: today.pendingApprovals == null ? null : _waitingCard(today, loadWaiting, onOpenApprovals),
      second: BranchSalesCard(today: today, onOpen: onOpenSales),
    ),
    // ⓘ দেনা | প্রিন্সিপালের কমিশন — একটা থাকলে সেটা একা, পুরো চওড়ায়
    if (payableCard != null && principalsCard != null)
      WidePair(pairKey: const ValueKey('home-wide-pair-books'), first: payableCard, second: principalsCard)
    else if (payableCard ?? principalsCard case final alone?)
      alone,
  ];
}

/// ⭐ বড় পর্দায় (Material 3 "expanded", জানালা ৮৪০dp বা বেশি) দুই কার্ড পাশাপাশি — টাকার কার্ড পুরো চওড়ায়, তার নিচে
/// সইয়ের অপেক্ষা আর আজকের বিল (fe, ২০ অক্টোবর ২০২৬: এক কলামে টানা কার্ডের ডানে খালি জায়গা)। ছোট পর্দায় একটার নিচে আরেকটা।
class WidePair extends StatelessWidget {
  const WidePair({super.key, required this.first, required this.second, this.pairKey = const ValueKey('home-wide-pair')});

  /// Material 3 window size class "expanded"
  static const double expandedWidth = 840;

  final Widget? first;
  final Widget second;

  /// পাশাপাশি সারির চাবি — দ্বিতীয় জোড়ার আলাদা
  final Key pairKey;

  @override
  Widget build(BuildContext context) {
    final a = first;
    if (a == null) return second;
    if (MediaQuery.sizeOf(context).width < expandedWidth) {
      return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [a, second]);
    }
    return Row(
      key: pairKey,
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(child: a),
        const SizedBox(width: AppSpacing.sm),
        Expanded(child: second),
      ],
    );
  }
}

Widget _waitingCard(TodayRecord today, Future<ApprovalPage> Function()? loadWaiting, VoidCallback? onOpenApprovals) =>
    loadWaiting == null
        // ⓘ সারি আনার পথ না থাকলে কেবল সার্ভারের গোনা — নাম বানিয়ে নেওয়া হয় না
        ? HomeSectionCard(
          key: const ValueKey('waiting-papers'),
          title: 'সইয়ের অপেক্ষায় · ${today.pendingApprovals}টি',
          actionLabel: 'সব দেখুন',
          onAction: onOpenApprovals,
          children: const [],
        )
        : WaitingPapersCard(
          // ⓘ নতুন হিসাব (টেনে আনা) এলে তালিকাও নতুন করে
          key: ValueKey('waiting-${today.asOf}'),
          load: loadWaiting,
          count: today.pendingApprovals,
          onOpenAll: onOpenApprovals,
        );
