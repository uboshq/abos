import 'package:flutter/material.dart';

import '../../core/finance/finance_api.dart';
import '../../core/navigation/recent_papers.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/paged_list.dart';
import '../books/books_widgets.dart';

/// ⭐ অর্থ, ফোনে — জমা, ব্যাংকের ঋণ, ভাড়া, হাতধার; কেবল পড়া (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)।
///
/// <p>প্রতিটা তালিকা ওয়েবের তালিকার একই ট্যাব আর ক্রম; প্রতিটা কাগজে সামনের দিন — মেয়াদ, পরের কিস্তি, পরের ভাড়া, ফেরতের দিন —
/// সার্ভারের সেবা থেকে। ⭐ মালিকের নিয়ম: এক লাইনে এক জিনিস, কোনো টেবিল নয়।

const _failed = 'আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।';
const _absent = 'সার্ভারে অর্থের এই অংশ এখনো আসেনি — অফিসে জানান।';

/// "আর ২০ দিন" · "আজ" · "১২ দিন পেরিয়েছে"
String daysWords(int? days) {
  if (days == null) return '';
  if (days == 0) return 'আজ';
  return days > 0 ? 'আর $days দিন' : '${-days} দিন পেরিয়েছে';
}

Color _dayColour(int? days) =>
    days == null ? AppColors.onSurfaceMuted : days < 0 ? AppColors.danger : days <= 30 ? AppColors.warning : AppColors.onSurfaceMuted;

int? _daysUntil(String? iso, [DateTime? today]) {
  final d = iso == null ? null : DateTime.tryParse(iso);
  if (d == null) return null;
  final now = today ?? DateTime.now();
  return DateTime(d.year, d.month, d.day).difference(DateTime(now.year, now.month, now.day)).inDays;
}

String depositStatus(String s) => switch (s) {
      'active' => 'চালু',
      'awaiting' => 'সইয়ের অপেক্ষায়',
      'closed' => 'শেষ',
      'cancelled' => 'বাতিল',
      _ => s,
    };

String depositMove(String k) => switch (k) {
      'opened' => 'টাকা রাখা',
      'instalment' => 'কিস্তি',
      'payout' => 'মুনাফা তোলা',
      'closed' => 'ভাঙানো / মেয়াদপূর্তি',
      _ => k,
    };

String loanKind(String k) => switch (k) {
      'cc' => 'ক্যাশ ক্রেডিট (CC)',
      'term' => 'মেয়াদি ঋণ',
      'ltr' => 'LTR',
      'lease' => 'লিজ',
      'bg' => 'ব্যাংক গ্যারান্টি',
      _ => k,
    };

String loanStatus(String s) => switch (s) {
      'confirmed' => 'চালু',
      'closed' => 'বন্ধ',
      'draft' => 'খসড়া',
      'cancelled' => 'বাতিল',
      _ => s,
    };

String instalmentState(String s) => switch (s) {
      'paid' => 'দেওয়া',
      'overdue' => 'দিন পেরিয়েছে',
      'today' => 'আজ দিতে হবে',
      'upcoming' => 'সামনে',
      _ => 'তারিখ নেই',
    };

Color _instalmentColour(String s) => switch (s) {
      'paid' => AppColors.success,
      'overdue' => AppColors.danger,
      'today' => AppColors.warning,
      _ => AppColors.onSurfaceMuted,
    };

String repaymentWords(String r) => switch (r) {
      'lump' => 'একবারে ফেরত',
      'monthly' => 'মাসে মাসে',
      'whenever' => 'যখন পারেন',
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
                Text(foot!, style: TextStyle(color: footColour ?? AppColors.onSurfaceMuted, fontWeight: FontWeight.w600)),
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
        appBar: AppBar(title: const Text('জমা ও সঞ্চয়')),
        body: PagedList<Deposit>(
          reloadKey: _tab,
          load: (page) async {
            final p = await widget.api.deposits(tab: _tab, page: page);
            return (p.rows, p.nextPage);
          },
          failed: _failed,
          whenAbsent: _absent,
          emptyIcon: Icons.savings_outlined,
          emptyTitle: 'এই ট্যাবে কোনো জমা নেই',
          header: [
            TabChips(
              tabs: const {'active': 'চালু', 'maturing': 'মেয়াদ আসছে', 'closed': 'শেষ'},
              selected: _tab,
              onSelected: (t) => setState(() => _tab = t),
            ),
          ],
          item: (context, d) => _Row(
            key: ValueKey('deposit-${d.id}'),
            title: '${d.no} · ${d.kind}',
            lines: [d.institution, if (d.maturesOn != null) 'মেয়াদ ${dayOf(d.maturesOn)}'],
            amount: Money.taka(d.principal),
            foot: d.status == 'active' ? daysWords(d.daysToMaturity) : depositStatus(d.status),
            footColour: d.status == 'active' ? _dayColour(d.daysToMaturity) : AppColors.onSurfaceMuted,
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
        title: 'জমা',
        titleOf: (d) => d.no,
        load: () => api.deposit(id),
        failed: 'জমাটা আনা গেল না।',
        onLoaded: (d) => RecentPapers.note(kind: 'deposit', id: id, number: d.no),
        body: (context, d) => [
          TotalStrip(label: 'জমার অঙ্ক', value: Money.taka(d.principal)),
          FactRow('ধরন', d.kind),
          FactRow('অবস্থা', depositStatus(d.status)),
          FactRow('প্রতিষ্ঠান', [d.institution, d.branchName].where((s) => s.isNotEmpty).join(' · ')),
          if (d.referenceNo.isNotEmpty) FactRow('ব্যাংকের নম্বর', d.referenceNo),
          FactRow('কার নামে', d.heldBy == 'owner' ? 'মালিকের${d.person.isEmpty ? '' : ' — ${d.person}'}' : 'ব্যবসার'),
          if (d.profitRate != null) FactRow('মুনাফার হার', '${d.profitRate}%'),
          FactRow('খোলা', dayOf(d.openedOn)),
          if (d.maturesOn != null)
            FactRow('মেয়াদ', '${dayOf(d.maturesOn)}${d.status == 'active' ? ' · ${daysWords(d.daysToMaturity)}' : ''}',
                colour: d.status == 'active' ? _dayColour(d.daysToMaturity) : null),
          if (d.instalmentAmount != null)
            FactRow('মাসের কিস্তি',
                '${Money.taka(d.instalmentAmount)}${d.instalmentDay == null ? '' : ' · প্রতি মাসের ${d.instalmentDay} তারিখ'}'),
          if (d.closedOn != null) FactRow('শেষ হয়েছে', dayOf(d.closedOn)),
          if (d.pledged) const FactRow('বাঁধা', 'ঋণের বিপরীতে বাঁধা — ভাঙানো যাবে না', colour: AppColors.warning),
          if (d.note.isNotEmpty) FactRow('নোট', d.note),
          if (d.movements.isNotEmpty) const SectionTitle('চলাচল'),
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
        appBar: AppBar(title: const Text('ব্যাংক ঋণ ও কিস্তি')),
        body: PagedList<BankLoan>(
          reloadKey: _tab,
          load: (page) async {
            final p = await widget.api.loans(tab: _tab, page: page);
            return (p.rows, p.nextPage);
          },
          failed: _failed,
          whenAbsent: _absent,
          emptyIcon: Icons.account_balance_outlined,
          emptyTitle: 'এই ট্যাবে কোনো ঋণ নেই',
          header: [
            TabChips(
              tabs: const {'open': 'চালু', 'closed': 'বন্ধ'},
              selected: _tab,
              onSelected: (t) => setState(() => _tab = t),
            ),
          ],
          item: (context, l) => _Row(
            key: ValueKey('loan-${l.id}'),
            title: '${l.bank} · ${loanKind(l.kind)}',
            lines: [l.no, 'সীমা ${Money.taka(l.limit)}'],
            amount: Money.taka(l.outstanding),
            amountColour: AppColors.danger,
            foot: l.next == null
                ? null
                : 'পরের কিস্তি ${dayOf(l.next!.dueOn)} · ${Money.taka(l.next!.amount)} · ${instalmentState(l.next!.state)}',
            footColour: l.next == null ? null : _instalmentColour(l.next!.state),
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
        title: 'ব্যাংক ঋণ',
        titleOf: (l) => l.no,
        load: () => api.loan(id),
        failed: 'ঋণটা আনা গেল না।',
        onLoaded: (l) => RecentPapers.note(kind: 'bank-loan', id: id, number: l.no),
        body: (context, l) => [
          TotalStrip(label: 'এখনকার দেনা', value: Money.taka(l.outstanding)),
          FactRow('ব্যাংক', [l.bank, l.branchName].where((s) => s.isNotEmpty).join(' · ')),
          FactRow('ধরন', loanKind(l.kind)),
          FactRow('অবস্থা', loanStatus(l.status)),
          FactRow('সীমা', Money.taka(l.limit)),
          FactRow('সীমার বাকি', Money.taka(l.available)),
          if (l.interestRate != null) FactRow('সুদের হার', '${l.interestRate}%'),
          if (l.sanctionNo.isNotEmpty) FactRow('মঞ্জুরির নম্বর', l.sanctionNo),
          FactRow('মঞ্জুরি', dayOf(l.sanctionedOn)),
          if (l.renewsOn != null) FactRow('নবায়ন', dayOf(l.renewsOn)),
          if (l.instalments != null)
            FactRow('কিস্তি', '${l.instalmentsPaid ?? 0} দেওয়া · ${l.instalmentsLeft ?? 0} বাকি · মোট ${l.instalments}'),
          if (l.instalmentAmount != null) FactRow('কিস্তির অঙ্ক', Money.taka(l.instalmentAmount)),
          if (l.overdue > 0)
            FactRow('দিন পেরোনো কিস্তি', '${l.overdue}টি', colour: AppColors.danger),
          if (l.next != null)
            Card(
              key: const ValueKey('loan-next'),
              color: l.next!.state == 'overdue' ? AppColors.dangerSurface : AppColors.warningSurface,
              child: ListTile(
                title: const Text('পরের কিস্তি'),
                subtitle: Text('${dayOf(l.next!.dueOn)} · ${instalmentState(l.next!.state)}'),
                trailing: Text(Money.taka(l.next!.amount), style: const TextStyle(fontWeight: FontWeight.w800)),
              ),
            ),
          if (l.schedule.isNotEmpty) const SectionTitle('সামনের কিস্তি'),
          for (final r in l.schedule)
            _Row(
              title: '${r.month ?? ''} নম্বর কিস্তি · ${dayOf(r.dueOn)}',
              lines: ['আসল ${Money.taka(r.principal)} · সুদ ${Money.taka(r.interest)}'],
              amount: Money.taka(r.amount),
              foot: instalmentState(r.state),
              footColour: _instalmentColour(r.state),
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
        appBar: AppBar(title: const Text('ভাড়া')),
        body: PagedList<Rent>(
          reloadKey: '$_side/$_tab',
          load: (page) async {
            final p = await widget.api.rents(side: _side, tab: _tab, page: page);
            return (p.rows, p.nextPage);
          },
          failed: _failed,
          whenAbsent: _absent,
          emptyIcon: Icons.home_work_outlined,
          emptyTitle: 'এখানে কোনো ভাড়ার চুক্তি নেই',
          header: [
            TabChips(
              tabs: const {'pay': 'আমরা ভাড়া দিই', 'let': 'আমরা ভাড়া পাই'},
              selected: _side,
              onSelected: (s) => setState(() => _side = s),
            ),
            TabChips(
              tabs: const {'running': 'চালু', 'closed': 'শেষ'},
              selected: _tab,
              onSelected: (t) => setState(() => _tab = t),
            ),
          ],
          item: (context, r) => _Row(
            key: ValueKey('rent-${r.id}'),
            title: r.party.isEmpty ? r.no : r.party,
            lines: [
              [r.place, r.no].where((s) => s.isNotEmpty).join(' · '),
              if (r.nextDueOn != null) 'পরের ভাড়া ${dayOf(r.nextDueOn)}',
            ],
            amount: Money.taka(r.monthlyRent),
            foot: _rentDue(r),
            footColour: _rentDueOwed(r) ? AppColors.danger : null,
            onTap: () => Navigator.of(context)
                .push(MaterialPageRoute<void>(builder: (_) => RentScreen(side: r.side, id: r.id, api: widget.api))),
          ),
        ),
      );
}

bool _rentDueOwed(Rent r) => r.weLet ? r.outstanding > 0.004 : r.overdueCount > 0;

/// "৩ মাসের ভাড়া বাকি · ৳৩৬,০০০" — আমরা দিলে আমাদের বাকি, আমরা পেলে ভাড়াটের কাছে পাওনা
String? _rentDue(Rent r) {
  if (r.weLet) {
    return r.outstanding > 0.004 ? 'ভাড়াটের কাছে পাওনা ${Money.taka(r.outstanding)}' : null;
  }
  return r.overdueCount > 0 ? '${r.overdueCount} মাসের ভাড়া বাকি · ${Money.taka(r.overdueAmount)}' : null;
}

class RentScreen extends StatelessWidget {
  const RentScreen({super.key, required this.side, required this.id, this.api = const ServerFinanceApi()});

  final String side;
  final String id;
  final FinanceApi api;

  @override
  Widget build(BuildContext context) => PaperPage<Rent>(
        title: 'ভাড়া',
        titleOf: (r) => r.party.isEmpty ? r.no : r.party,
        load: () => api.rent(side, id),
        failed: 'ভাড়ার চুক্তিটা আনা গেল না।',
        onLoaded: (r) => RecentPapers.note(kind: 'rent', id: id, number: r.no, extra: side),
        body: (context, r) => [
          TotalStrip(label: r.weLet ? 'মাসের ভাড়া (আমরা পাই)' : 'মাসের ভাড়া (আমরা দিই)', value: Money.taka(r.monthlyRent)),
          if (_rentDue(r) != null)
            Card(
              key: const ValueKey('rent-due'),
              color: AppColors.dangerSurface,
              child: Padding(
                padding: const EdgeInsets.all(AppSpacing.md),
                child: Text(_rentDue(r)!, style: const TextStyle(color: AppColors.danger, fontWeight: FontWeight.w700)),
              ),
            ),
          FactRow(r.weLet ? 'ভাড়াটে' : 'বাড়িওয়ালা', r.party),
          if (r.phone.isNotEmpty) FactRow('ফোন', r.phone),
          if (r.place.isNotEmpty) FactRow('জায়গা', r.place),
          FactRow('চুক্তির নম্বর', r.no),
          if (r.rentDay != null) FactRow('ভাড়ার দিন', 'প্রতি মাসের ${r.rentDay} তারিখ'),
          if (r.nextDueOn != null) FactRow('পরের ভাড়া', dayOf(r.nextDueOn), colour: _dayColour(_daysUntil(r.nextDueOn))),
          FactRow('শুরু', dayOf(r.startsOn)),
          FactRow('শেষ', '${dayOf(r.endsOn)}${r.daysLeft == null ? '' : ' · ${daysWords(r.daysLeft)}'}',
              colour: _dayColour(r.daysLeft)),
          FactRow('জামানত', Money.taka(r.deposit)),
          if (r.depositLeft != null) FactRow(r.weLet ? 'হাতে থাকা জামানত' : 'জামানতের বাকি', Money.taka(r.depositLeft)),
          if (r.weLet && r.oldestUnpaid != null) FactRow('সবচেয়ে পুরনো বাকি মাস', monthWords(r.oldestUnpaid)),
          if (r.note.isNotEmpty) FactRow('নোট', r.note),
          if (r.overdueMonths.isNotEmpty) const SectionTitle('বাকি মাস'),
          for (final m in r.overdueMonths)
            _Row(title: monthWords(m.month), lines: const [], amount: Money.taka(m.amount), amountColour: AppColors.danger),
        ],
      );
}

const _months = [
  'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন',
  'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর',
];

/// `2026-09-01` → "সেপ্টেম্বর 2026"
String monthWords(String? iso) {
  final d = iso == null ? null : DateTime.tryParse(iso);
  return d == null ? '—' : '${_months[d.month - 1]} ${d.year}';
}

// ───────────────────────────── হাতধার ─────────────────────────────

class HandLoanListScreen extends StatefulWidget {
  const HandLoanListScreen({super.key, this.api = const ServerFinanceApi()});

  final FinanceApi api;

  @override
  State<HandLoanListScreen> createState() => _HandLoanListScreenState();
}

class _HandLoanListScreenState extends State<HandLoanListScreen> {
  HandLoanBook? _book;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('হাতধার')),
        body: PagedList<HandLoan>(
          load: (page) async {
            final book = await widget.api.handLoans();
            if (mounted) setState(() => _book = book);
            return (book.rows, null);
          },
          failed: _failed,
          whenAbsent: _absent,
          emptyIcon: Icons.handshake_outlined,
          emptyTitle: 'কোনো খোলা হাতধার নেই',
          header: [
            if (_book != null) ...[
              TotalStrip(key: const ValueKey('hand-to-us'), label: 'আমরা পাব', value: Money.taka(_book!.owedToUs)),
              TotalStrip(key: const ValueKey('hand-we-owe'), label: 'আমরা দেব', value: Money.taka(_book!.weOwe)),
            ],
          ],
          item: (context, h) => _Row(
            key: ValueKey('hand-${h.id}'),
            title: h.name,
            lines: [if (h.dueOn != null) 'ফেরতের দিন ${dayOf(h.dueOn)}', repaymentWords(h.repayment)],
            amount: Money.taka(h.balance.abs()),
            amountColour: h.balance > 0.004 ? AppColors.success : h.balance < -0.004 ? AppColors.danger : null,
            foot: handLoanSide(h.balance),
            onTap: () => Navigator.of(context)
                .push(MaterialPageRoute<void>(builder: (_) => HandLoanScreen(id: h.id, api: widget.api))),
          ),
        ),
      );
}

/// জেরের দিক — কে কাকে দেবেন
String handLoanSide(double balance) => balance > 0.004
    ? 'তিনি আমাদের দেবেন'
    : balance < -0.004
        ? 'আমরা তাঁকে দেব'
        : 'মিটে গেছে';

class HandLoanScreen extends StatelessWidget {
  const HandLoanScreen({super.key, required this.id, this.api = const ServerFinanceApi()});

  final String id;
  final FinanceApi api;

  @override
  Widget build(BuildContext context) => PaperPage<HandLoan>(
        title: 'হাতধার',
        titleOf: (h) => h.name,
        load: () => api.handLoan(id),
        failed: 'হাতধারটা আনা গেল না।',
        onLoaded: (h) => RecentPapers.note(kind: 'hand-loan', id: id, number: h.name),
        body: (context, h) => [
          TotalStrip(label: handLoanSide(h.balance), value: Money.taka(h.balance.abs())),
          if (h.mobile.isNotEmpty) FactRow('মোবাইল', h.mobile),
          if (h.principal != null) FactRow('আসল', Money.taka(h.principal)),
          if (h.interestRate != null) FactRow('সুদের হার', '${h.interestRate}%'),
          if (h.repayment.isNotEmpty) FactRow('ফেরতের ধরন', repaymentWords(h.repayment)),
          if (h.dueOn != null)
            FactRow('ফেরতের দিন', '${dayOf(h.dueOn)} · ${daysWords(_daysUntil(h.dueOn))}',
                colour: _dayColour(_daysUntil(h.dueOn))),
          FactRow('অবস্থা', h.status == 'settled' ? 'চুকে গেছে' : 'চালু'),
          if (h.note.isNotEmpty) FactRow('নোট', h.note),
          if (h.movements.isNotEmpty) const SectionTitle('লেনদেন'),
          for (final m in h.movements)
            _Row(
              title: m.kind == 'out' ? 'টাকা দেওয়া' : 'টাকা পাওয়া',
              lines: [dayOf(m.date), if (m.returnOn != null) 'ফেরতের কথা ${dayOf(m.returnOn)}'],
              amount: Money.taka(m.amount),
              amountColour: m.kind == 'out' ? AppColors.danger : AppColors.success,
            ),
        ],
      );
}
