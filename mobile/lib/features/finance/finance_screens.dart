import 'package:flutter/material.dart';

import '../../core/finance/finance_api.dart';
import '../../core/locale/app_locale.dart';
import '../../core/locale/l10n.dart';
import '../../core/navigation/recent_papers.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/day_part.dart' show monthText;
import '../../core/widgets/paged_list.dart';
import '../books/books_widgets.dart';
import '../money/money_screens.dart';

/// ⭐ অর্থ, ফোনে — জমা, ব্যাংকের ঋণ, ভাড়া, হাতধার; কেবল পড়া (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)।
///
/// <p>প্রতিটা তালিকা ওয়েবের তালিকার একই ট্যাব আর ক্রম; প্রতিটা কাগজে সামনের দিন — মেয়াদ, পরের কিস্তি, পরের ভাড়া, ফেরতের দিন —
/// সার্ভারের সেবা থেকে। ⭐ মালিকের নিয়ম: এক লাইনে এক জিনিস, কোনো টেবিল নয়।

/// "আর ২০ দিন" · "আজ" · "১২ দিন পেরিয়েছে"
String daysWords(int? days) {
  if (days == null) return '';
  final l = AppLocale.l10n;
  if (days == 0) return l.financeDaysToday;
  return days > 0 ? l.financeDaysLeft(days) : l.financeDaysOverdue(-days);
}

Color _dayColour(BuildContext context, int? days) =>
    days == null ? context.palette.onSurfaceMuted : days < 0 ? context.palette.danger : days <= 30 ? context.palette.warning : context.palette.onSurfaceMuted;

int? _daysUntil(String? iso, [DateTime? today]) {
  final d = iso == null ? null : DateTime.tryParse(iso);
  if (d == null) return null;
  final now = today ?? DateTime.now();
  return DateTime(d.year, d.month, d.day).difference(DateTime(now.year, now.month, now.day)).inDays;
}

String depositStatus(String s) => switch (s) {
      'active' => AppLocale.l10n.financeStatusActive,
      'awaiting' => AppLocale.l10n.financeStatusAwaitingSign,
      'closed' => AppLocale.l10n.financeStatusEnded,
      'cancelled' => AppLocale.l10n.financeStatusCancelled,
      _ => s,
    };

String depositMove(String k) => switch (k) {
      'opened' => AppLocale.l10n.financeDepositMoveOpened,
      'instalment' => AppLocale.l10n.financeInstalment,
      'payout' => AppLocale.l10n.financeDepositMovePayout,
      'closed' => AppLocale.l10n.financeDepositMoveClosed,
      _ => k,
    };

String loanKind(String k) => switch (k) {
      'cc' => AppLocale.l10n.financeLoanKindCc,
      'term' => AppLocale.l10n.financeLoanKindTerm,
      'ltr' => 'LTR',
      'lease' => AppLocale.l10n.financeLoanKindLease,
      'bg' => AppLocale.l10n.financeLoanKindGuarantee,
      _ => k,
    };

String loanStatus(String s) => switch (s) {
      'confirmed' => AppLocale.l10n.financeStatusActive,
      'closed' => AppLocale.l10n.financeStatusClosed,
      'draft' => AppLocale.l10n.financeStatusDraft,
      'cancelled' => AppLocale.l10n.financeStatusCancelled,
      _ => s,
    };

String instalmentState(String s) => switch (s) {
      'paid' => AppLocale.l10n.financeInstalmentPaid,
      'overdue' => AppLocale.l10n.financeInstalmentOverdue,
      'today' => AppLocale.l10n.financeInstalmentToday,
      'upcoming' => AppLocale.l10n.financeInstalmentUpcoming,
      _ => AppLocale.l10n.financeInstalmentNoDate,
    };

Color _instalmentColour(BuildContext context, String s) => switch (s) {
      'paid' => context.palette.success,
      'overdue' => context.palette.danger,
      'today' => context.palette.warning,
      _ => context.palette.onSurfaceMuted,
    };

String repaymentWords(String r) => switch (r) {
      'lump' => AppLocale.l10n.financeRepaymentLump,
      'monthly' => AppLocale.l10n.financeRepaymentMonthly,
      'whenever' => AppLocale.l10n.financeRepaymentWhenever,
      _ => r,
    };

class _Row extends StatelessWidget {
  const _Row({
    super.key,
    required this.title,
    required this.lines,
    required this.amount,
    this.amountColour,
    this.foot,
    this.footColour,
    this.onTap,
  });

  final String title;
  final List<String> lines;
  final String amount;
  final Color? amountColour;
  final String? foot;
  final Color? footColour;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Card(
        margin: const EdgeInsets.only(bottom: AppSpacing.xs),
        child: ListTile(
          onTap: onTap,
          title: Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
          subtitle: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              for (final l in lines.where((l) => l.trim().isNotEmpty))
                Text(l, maxLines: 1, overflow: TextOverflow.ellipsis),
              if (foot != null && foot!.isNotEmpty)
                Text(foot!, style: TextStyle(color: footColour ?? context.palette.onSurfaceMuted, fontWeight: FontWeight.w600)),
            ],
          ),
          trailing: Text(amount, style: TextStyle(fontWeight: FontWeight.w800, color: amountColour)),
        ),
      );
}

// ───────────────────────────── জমা ─────────────────────────────

class DepositListScreen extends StatefulWidget {
  const DepositListScreen({super.key, this.api = const ServerFinanceApi()});

  final FinanceApi api;

  @override
  State<DepositListScreen> createState() => _DepositListScreenState();
}

class _DepositListScreenState extends State<DepositListScreen> {
  String _tab = 'active';

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AbosScreenBar(title: context.l10n.financeDepositListTitle),
        body: PagedList<Deposit>(
          reloadKey: _tab,
          load: (page) async {
            final p = await widget.api.deposits(tab: _tab, page: page);
            return (p.rows, p.nextPage);
          },
          failed: context.l10n.financeLoadFailed,
          whenAbsent: context.l10n.financeNotOnServer,
          emptyIcon: Icons.savings_outlined,
          emptyTitle: context.l10n.financeDepositListEmpty,
          header: [
            TabChips(
              tabs: {
                'active': context.l10n.financeStatusActive,
                'maturing': context.l10n.financeDepositTabMaturing,
                'closed': context.l10n.financeStatusEnded,
              },
              selected: _tab,
              onSelected: (t) => setState(() => _tab = t),
            ),
          ],
          item: (context, d) => _Row(
            key: ValueKey('deposit-${d.id}'),
            title: '${d.no} · ${d.kind}',
            lines: [d.institution, if (d.maturesOn != null) context.l10n.financeDepositMatures(dayOf(d.maturesOn))],
            amount: Money.taka(d.principal),
            foot: d.status == 'active' ? daysWords(d.daysToMaturity) : depositStatus(d.status),
            footColour: d.status == 'active' ? _dayColour(context, d.daysToMaturity) : context.palette.onSurfaceMuted,
            onTap: () => Navigator.of(context)
                .push(MaterialPageRoute<void>(builder: (_) => DepositScreen(id: d.id, api: widget.api))),
          ),
        ),
      );
}

class DepositScreen extends StatelessWidget {
  const DepositScreen({super.key, required this.id, this.api = const ServerFinanceApi()});

  final String id;
  final FinanceApi api;

  @override
  Widget build(BuildContext context) => PaperPage<Deposit>(
        title: context.l10n.financeDepositTitle,
        titleOf: (d) => d.no,
        load: () => api.deposit(id),
        failed: context.l10n.financeDepositLoadFailed,
        onLoaded: (d) => RecentPapers.note(kind: 'deposit', id: id, number: d.no),
        body: (context, d) => [
          TotalStrip(label: context.l10n.financeDepositAmount, value: Money.taka(d.principal)),
          FactRow(context.l10n.financeFactType, d.kind),
          FactRow(context.l10n.financeFactStatus, depositStatus(d.status)),
          FactRow(context.l10n.financeDepositInstitution, [d.institution, d.branchName].where((s) => s.isNotEmpty).join(' · ')),
          if (d.referenceNo.isNotEmpty) FactRow(context.l10n.financeDepositBankRef, d.referenceNo),
          FactRow(
              context.l10n.financeDepositHeldBy,
              d.heldBy == 'owner'
                  ? (d.person.isEmpty ? context.l10n.financeDepositHeldOwner : context.l10n.financeDepositHeldOwnerPerson(d.person))
                  : context.l10n.financeDepositHeldBusiness),
          if (d.profitRate != null) FactRow(context.l10n.financeDepositProfitRate, '${d.profitRate}%'),
          FactRow(context.l10n.financeDepositOpened, dayOf(d.openedOn)),
          if (d.maturesOn != null)
            FactRow(context.l10n.financeDepositMaturity, '${dayOf(d.maturesOn)}${d.status == 'active' ? ' · ${daysWords(d.daysToMaturity)}' : ''}',
                colour: d.status == 'active' ? _dayColour(context, d.daysToMaturity) : null),
          if (d.instalmentAmount != null)
            FactRow(context.l10n.financeDepositMonthlyInstalment,
                '${Money.taka(d.instalmentAmount)}${d.instalmentDay == null ? '' : ' · ${context.l10n.financeDayOfMonth('${d.instalmentDay}')}'}'),
          if (d.closedOn != null) FactRow(context.l10n.financeDepositClosedOn, dayOf(d.closedOn)),
          if (d.pledged) FactRow(context.l10n.financeDepositPledged, context.l10n.financeDepositPledgedNote, colour: context.palette.warning),
          if (d.note.isNotEmpty) FactRow(context.l10n.financeNote, d.note),
          if (d.movements.isNotEmpty) SectionTitle(context.l10n.financeDepositMovements),
          for (final m in d.movements)
            _Row(title: depositMove(m.kind), lines: [dayOf(m.date)], amount: Money.taka(m.amount)),
        ],
      );
}

// ─────────────────────────── ব্যাংকের ঋণ ───────────────────────────

class LoanListScreen extends StatefulWidget {
  const LoanListScreen({super.key, this.api = const ServerFinanceApi()});

  final FinanceApi api;

  @override
  State<LoanListScreen> createState() => _LoanListScreenState();
}

class _LoanListScreenState extends State<LoanListScreen> {
  String _tab = 'open';

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AbosScreenBar(title: context.l10n.financeLoanListTitle),
        body: PagedList<BankLoan>(
          reloadKey: _tab,
          load: (page) async {
            final p = await widget.api.loans(tab: _tab, page: page);
            return (p.rows, p.nextPage);
          },
          failed: context.l10n.financeLoadFailed,
          whenAbsent: context.l10n.financeNotOnServer,
          emptyIcon: Icons.account_balance_outlined,
          emptyTitle: context.l10n.financeLoanListEmpty,
          header: [
            TabChips(
              tabs: {'open': context.l10n.financeStatusActive, 'closed': context.l10n.financeStatusClosed},
              selected: _tab,
              onSelected: (t) => setState(() => _tab = t),
            ),
          ],
          item: (context, l) => _Row(
            key: ValueKey('loan-${l.id}'),
            title: '${l.bank} · ${loanKind(l.kind)}',
            lines: [l.no, context.l10n.financeLoanLimitLine(Money.taka(l.limit))],
            amount: Money.taka(l.outstanding),
            amountColour: context.palette.danger,
            foot: l.next == null
                ? null
                : context.l10n.financeLoanNextLine(dayOf(l.next!.dueOn), Money.taka(l.next!.amount), instalmentState(l.next!.state)),
            footColour: l.next == null ? null : _instalmentColour(context, l.next!.state),
            onTap: () => Navigator.of(context)
                .push(MaterialPageRoute<void>(builder: (_) => LoanScreen(id: l.id, api: widget.api))),
          ),
        ),
      );
}

class LoanScreen extends StatelessWidget {
  const LoanScreen({super.key, required this.id, this.api = const ServerFinanceApi()});

  final String id;
  final FinanceApi api;

  @override
  Widget build(BuildContext context) => PaperPage<BankLoan>(
        title: context.l10n.financeLoanTitle,
        titleOf: (l) => l.no,
        load: () => api.loan(id),
        failed: context.l10n.financeLoanLoadFailed,
        onLoaded: (l) => RecentPapers.note(kind: 'bank-loan', id: id, number: l.no),
        body: (context, l) => [
          TotalStrip(label: context.l10n.financeLoanOutstanding, value: Money.taka(l.outstanding)),
          FactRow(context.l10n.financeLoanBank, [l.bank, l.branchName].where((s) => s.isNotEmpty).join(' · ')),
          FactRow(context.l10n.financeFactType, loanKind(l.kind)),
          FactRow(context.l10n.financeFactStatus, loanStatus(l.status)),
          FactRow(context.l10n.financeLoanLimit, Money.taka(l.limit)),
          FactRow(context.l10n.financeLoanAvailable, Money.taka(l.available)),
          if (l.interestRate != null) FactRow(context.l10n.financeInterestRate, '${l.interestRate}%'),
          if (l.sanctionNo.isNotEmpty) FactRow(context.l10n.financeLoanSanctionNo, l.sanctionNo),
          FactRow(context.l10n.financeLoanSanctioned, dayOf(l.sanctionedOn)),
          if (l.renewsOn != null) FactRow(context.l10n.financeLoanRenewal, dayOf(l.renewsOn)),
          if (l.instalments != null)
            FactRow(context.l10n.financeInstalment,
                context.l10n.financeLoanInstalmentCounts('${l.instalmentsPaid ?? 0}', '${l.instalmentsLeft ?? 0}', '${l.instalments}')),
          if (l.instalmentAmount != null) FactRow(context.l10n.financeLoanInstalmentAmount, Money.taka(l.instalmentAmount)),
          if (l.overdue > 0)
            FactRow(context.l10n.financeLoanOverdueInstalments, context.l10n.financeLoanOverdueCount('${l.overdue}'), colour: context.palette.danger),
          if (l.next != null)
            Card(
              key: const ValueKey('loan-next'),
              color: l.next!.state == 'overdue' ? context.palette.dangerSurface : context.palette.warningSurface,
              child: ListTile(
                title: Text(context.l10n.financeLoanNextInstalment),
                subtitle: Text('${dayOf(l.next!.dueOn)} · ${instalmentState(l.next!.state)}'),
                trailing: Text(Money.taka(l.next!.amount), style: const TextStyle(fontWeight: FontWeight.w800)),
              ),
            ),
          if (l.schedule.isNotEmpty) SectionTitle(context.l10n.financeLoanSchedule),
          for (final r in l.schedule)
            _Row(
              title: context.l10n.financeLoanScheduleTitle('${r.month ?? ''}', dayOf(r.dueOn)),
              lines: [context.l10n.financeLoanScheduleSplit(Money.taka(r.principal), Money.taka(r.interest))],
              amount: Money.taka(r.amount),
              foot: instalmentState(r.state),
              footColour: _instalmentColour(context, r.state),
            ),
        ],
      );
}

// ───────────────────────────── ভাড়া ─────────────────────────────

class RentListScreen extends StatefulWidget {
  const RentListScreen({super.key, this.api = const ServerFinanceApi()});

  final FinanceApi api;

  @override
  State<RentListScreen> createState() => _RentListScreenState();
}

class _RentListScreenState extends State<RentListScreen> {
  String _side = 'pay';
  String _tab = 'running';

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AbosScreenBar(title: context.l10n.financeRentTitle),
        body: PagedList<Rent>(
          reloadKey: '$_side/$_tab',
          load: (page) async {
            final p = await widget.api.rents(side: _side, tab: _tab, page: page);
            return (p.rows, p.nextPage);
          },
          failed: context.l10n.financeLoadFailed,
          whenAbsent: context.l10n.financeNotOnServer,
          emptyIcon: Icons.home_work_outlined,
          emptyTitle: context.l10n.financeRentListEmpty,
          header: [
            TabChips(
              tabs: {'pay': context.l10n.financeRentSidePay, 'let': context.l10n.financeRentSideLet},
              selected: _side,
              onSelected: (s) => setState(() => _side = s),
            ),
            TabChips(
              tabs: {'running': context.l10n.financeStatusActive, 'closed': context.l10n.financeStatusEnded},
              selected: _tab,
              onSelected: (t) => setState(() => _tab = t),
            ),
          ],
          item: (context, r) => _Row(
            key: ValueKey('rent-${r.id}'),
            title: r.party.isEmpty ? r.no : r.party,
            lines: [
              [r.place, r.no].where((s) => s.isNotEmpty).join(' · '),
              if (r.nextDueOn != null) context.l10n.financeRentNextLine(dayOf(r.nextDueOn)),
            ],
            amount: Money.taka(r.monthlyRent),
            foot: _rentDue(context.l10n, r),
            footColour: _rentDueOwed(r) ? context.palette.danger : null,
            onTap: () => Navigator.of(context)
                .push(MaterialPageRoute<void>(builder: (_) => RentScreen(side: r.side, id: r.id, api: widget.api))),
          ),
        ),
      );
}

bool _rentDueOwed(Rent r) => r.weLet ? r.outstanding > 0.004 : r.overdueCount > 0;

/// "৩ মাসের ভাড়া বাকি · ৳৩৬,০০০" — আমরা দিলে আমাদের বাকি, আমরা পেলে ভাড়াটের কাছে পাওনা
String? _rentDue(AppL10n l, Rent r) {
  if (r.weLet) {
    return r.outstanding > 0.004 ? l.financeRentTenantOwes(Money.taka(r.outstanding)) : null;
  }
  return r.overdueCount > 0 ? l.financeRentMonthsDue(r.overdueCount, Money.taka(r.overdueAmount)) : null;
}

class RentScreen extends StatelessWidget {
  const RentScreen({super.key, required this.side, required this.id, this.api = const ServerFinanceApi()});

  final String side;
  final String id;
  final FinanceApi api;

  @override
  Widget build(BuildContext context) => PaperPage<Rent>(
        title: context.l10n.financeRentTitle,
        titleOf: (r) => r.party.isEmpty ? r.no : r.party,
        load: () => api.rent(side, id),
        failed: context.l10n.financeRentLoadFailed,
        onLoaded: (r) => RecentPapers.note(kind: 'rent', id: id, number: r.no, extra: side),
        body: (context, r) => [
          TotalStrip(
              label: r.weLet ? context.l10n.financeRentMonthlyWeLet : context.l10n.financeRentMonthlyWePay,
              value: Money.taka(r.monthlyRent)),
          if (_rentDue(context.l10n, r) != null)
            Card(
              key: const ValueKey('rent-due'),
              color: context.palette.dangerSurface,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text(_rentDue(context.l10n, r)!, style: TextStyle(color: context.palette.danger, fontWeight: FontWeight.w700)),
              ),
            ),
          FactRow(r.weLet ? context.l10n.financeRentTenant : context.l10n.financeRentLandlord, r.party),
          if (r.phone.isNotEmpty) FactRow(context.l10n.financeRentPhone, r.phone),
          if (r.place.isNotEmpty) FactRow(context.l10n.financeRentPlace, r.place),
          FactRow(context.l10n.financeRentAgreementNo, r.no),
          if (r.rentDay != null) FactRow(context.l10n.financeRentDay, context.l10n.financeDayOfMonth('${r.rentDay}')),
          if (r.nextDueOn != null) FactRow(context.l10n.financeRentNext, dayOf(r.nextDueOn), colour: _dayColour(context, _daysUntil(r.nextDueOn))),
          FactRow(context.l10n.financeRentStarts, dayOf(r.startsOn)),
          FactRow(context.l10n.financeRentEnds, '${dayOf(r.endsOn)}${r.daysLeft == null ? '' : ' · ${daysWords(r.daysLeft)}'}',
              colour: _dayColour(context, r.daysLeft)),
          FactRow(context.l10n.financeRentSecurity, Money.taka(r.deposit)),
          if (r.depositLeft != null) FactRow(r.weLet ? context.l10n.financeRentSecurityHeld : context.l10n.financeRentSecurityLeft, Money.taka(r.depositLeft)),
          if (r.weLet && r.oldestUnpaid != null) FactRow(context.l10n.financeRentOldestUnpaid, monthWords(r.oldestUnpaid)),
          if (r.note.isNotEmpty) FactRow(context.l10n.financeNote, r.note),
          if (r.overdueMonths.isNotEmpty) SectionTitle(context.l10n.financeRentUnpaidMonths),
          for (final m in r.overdueMonths)
            _Row(title: monthWords(m.month), lines: const [], amount: Money.taka(m.amount), amountColour: context.palette.danger),
        ],
      );
}

/// `2026-09-01` → "সেপ্টেম্বর 2026" (ইংরেজিতে "September 2026"; [monthText], [locale] না দিলে [AppLocale.current])
String monthWords(String? iso, [Locale? locale]) {
  final d = iso == null ? null : DateTime.tryParse(iso);
  return d == null ? '—' : monthText(d, locale);
}

// ───────────────────────────── হাতধার ─────────────────────────────

class HandLoanListScreen extends StatefulWidget {
  const HandLoanListScreen({super.key, this.api = const ServerFinanceApi(), this.canMove = false});

  final FinanceApi api;

  /// `finance.hand_loan.move` — পাতায় দেওয়া-নেওয়ার বোতাম
  final bool canMove;

  @override
  State<HandLoanListScreen> createState() => _HandLoanListScreenState();
}

class _HandLoanListScreenState extends State<HandLoanListScreen> {
  HandLoanBook? _book;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AbosScreenBar(title: context.l10n.financeHandLoanListTitle),
        body: PagedList<HandLoan>(
          load: (page) async {
            final book = await widget.api.handLoans();
            if (mounted) setState(() => _book = book);
            return (book.rows, null);
          },
          failed: context.l10n.financeLoadFailed,
          whenAbsent: context.l10n.financeNotOnServer,
          emptyIcon: Icons.handshake_outlined,
          emptyTitle: context.l10n.financeHandLoanListEmpty,
          header: [
            if (_book != null) ...[
              TotalStrip(key: const ValueKey('hand-to-us'), label: context.l10n.financeHandLoanToUs, value: Money.taka(_book!.owedToUs)),
              TotalStrip(key: const ValueKey('hand-we-owe'), label: context.l10n.financeHandLoanWeOwe, value: Money.taka(_book!.weOwe)),
            ],
          ],
          item: (context, h) => _Row(
            key: ValueKey('hand-${h.id}'),
            title: h.name,
            lines: [if (h.dueOn != null) context.l10n.financeHandLoanDueLine(dayOf(h.dueOn)), repaymentWords(h.repayment)],
            amount: Money.taka(h.balance.abs()),
            amountColour: h.balance > 0.004 ? context.palette.success : h.balance < -0.004 ? context.palette.danger : null,
            foot: handLoanSide(h.balance),
            onTap: () => Navigator.of(context)
                .push(MaterialPageRoute<void>(builder: (_) => HandLoanScreen(id: h.id, api: widget.api, canMove: widget.canMove))),
          ),
        ),
      );
}

/// জেরের দিক — কে কাকে দেবেন
String handLoanSide(double balance) => balance > 0.004
    ? AppLocale.l10n.financeHandLoanTheyOwe
    : balance < -0.004
        ? AppLocale.l10n.financeHandLoanWeOweThem
        : AppLocale.l10n.financeHandLoanSettled;

class HandLoanScreen extends StatelessWidget {
  const HandLoanScreen({super.key, required this.id, this.api = const ServerFinanceApi(), this.canMove = false});

  final String id;
  final FinanceApi api;

  /// `finance.hand_loan.move` — না থাকলে দেওয়া-নেওয়ার বোতাম নেই
  final bool canMove;

  @override
  Widget build(BuildContext context) => PaperPage<HandLoan>(
        title: context.l10n.financeHandLoanTitle,
        titleOf: (h) => h.name,
        load: () => api.handLoan(id),
        failed: context.l10n.financeHandLoanLoadFailed,
        onLoaded: (h) => RecentPapers.note(kind: 'hand-loan', id: id, number: h.name),
        body: (context, h) => [
          TotalStrip(label: handLoanSide(h.balance), value: Money.taka(h.balance.abs())),
          if (h.mobile.isNotEmpty) FactRow(context.l10n.financeHandLoanMobile, h.mobile),
          if (h.principal != null) FactRow(context.l10n.financeHandLoanPrincipal, Money.taka(h.principal)),
          if (h.interestRate != null) FactRow(context.l10n.financeInterestRate, '${h.interestRate}%'),
          if (h.repayment.isNotEmpty) FactRow(context.l10n.financeHandLoanRepaymentType, repaymentWords(h.repayment)),
          if (h.dueOn != null)
            FactRow(context.l10n.financeHandLoanDueDate, '${dayOf(h.dueOn)} · ${daysWords(_daysUntil(h.dueOn))}',
                colour: _dayColour(context, _daysUntil(h.dueOn))),
          FactRow(context.l10n.financeFactStatus,
              h.status == 'settled' ? context.l10n.financeHandLoanStatusSettled : context.l10n.financeStatusActive),
          if (h.note.isNotEmpty) FactRow(context.l10n.financeNote, h.note),
          // ⭐ টাকা দেওয়া-নেওয়া — হাতধারের লেখার চাবিতে (১৭ অক্টোবর ২০২৬; [[HandLoanMoveScreen]])
          if (canMove && h.status != 'settled')
            Padding(
              padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
              child: FilledButton.icon(
                key: const ValueKey('handloan-move'),
                onPressed: () => Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => HandLoanMoveScreen(id: id, name: h.name))),
                icon: const Icon(Icons.swap_vert),
                label: Text(context.l10n.financeHandLoanMoveButton),
              ),
            ),
          if (h.movements.isNotEmpty) SectionTitle(context.l10n.financeHandLoanTransactions),
          for (final m in h.movements)
            _Row(
              title: m.kind == 'out' ? context.l10n.financeHandLoanGiven : context.l10n.financeHandLoanReceived,
              lines: [dayOf(m.date), if (m.returnOn != null) context.l10n.financeHandLoanReturnLine(dayOf(m.returnOn))],
              amount: Money.taka(m.amount),
              amountColour: m.kind == 'out' ? context.palette.danger : context.palette.success,
            ),
        ],
      );
}
