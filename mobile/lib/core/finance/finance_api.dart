import '../api_client/api_client.dart';
import '../records/money.dart';

/// ⭐ অর্থ, ফোনে — জমা, ব্যাংকের ঋণ ও কিস্তি, ভাড়া (আমরা দিই · আমরা পাই), হাতধার; কেবল পড়া
/// (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬; সার্ভার `FinanceApiController`)।
///
/// <p>প্রতিটা অঙ্ক সার্ভারের — মডিউলের নিজের সেবার (ঋণের দেনা, পরের কিস্তি, বকেয়া ভাড়ার মাস, হাতধারের জের)। ফোন কিছু গোনে না।

String _s(dynamic v) => v?.toString() ?? '';
String? _sn(dynamic v) => v == null || v.toString().isEmpty ? null : v.toString();
int? _i(dynamic v) => v is num ? v.toInt() : int.tryParse(_s(v));

List<Map<String, dynamic>> _rows(dynamic v) => [
      if (v is List)
        for (final e in v)
          if (e is Map) Map<String, dynamic>.from(e),
    ];

/// এক পাতা সারি, আর পরের পাতা থাকলে তার নম্বর
class FinancePage<T> {
  const FinancePage(this.rows, {this.nextPage});

  final List<T> rows;
  final int? nextPage;
}

class Deposit {
  const Deposit({
    required this.id,
    required this.no,
    this.kind = '',
    this.institution = '',
    this.heldBy = 'business',
    required this.principal,
    this.profitRate,
    this.openedOn,
    this.maturesOn,
    this.daysToMaturity,
    this.status = 'active',
    this.instalmentAmount,
    this.instalmentDay,
    this.referenceNo = '',
    this.branchName = '',
    this.person = '',
    this.closedOn,
    this.pledged = false,
    this.note = '',
    this.movements = const [],
  });

  final String id;
  final String no;
  final String kind;
  final String institution;
  final String heldBy;
  final double principal;
  final String? profitRate;
  final String? openedOn;
  final String? maturesOn;
  final int? daysToMaturity;
  final String status;
  final double? instalmentAmount;
  final int? instalmentDay;
  final String referenceNo;
  final String branchName;
  final String person;
  final String? closedOn;
  final bool pledged;
  final String note;
  final List<Movement> movements;

  factory Deposit.fromJson(Map<String, dynamic> j) => Deposit(
        id: _s(j['id']),
        no: _s(j['no']),
        kind: _s(j['kind']),
        institution: _s(j['institution']),
        heldBy: _s(j['held_by']),
        principal: Money.valueOrZero(j['principal']),
        profitRate: _sn(j['profit_rate']),
        openedOn: _sn(j['opened_on']),
        maturesOn: _sn(j['matures_on']),
        daysToMaturity: _i(j['days_to_maturity']),
        status: _s(j['status']),
        instalmentAmount: Money.value(j['instalment_amount']),
        instalmentDay: _i(j['instalment_day']),
        referenceNo: _s(j['reference_no']),
        branchName: _s(j['branch_name']),
        person: _s(j['person']),
        closedOn: _sn(j['closed_on']),
        pledged: j['pledged'] == true,
        note: _s(j['note']),
        movements: [
          for (final m in _rows(j['movements']))
            Movement(
                date: _sn(m['date']),
                kind: _s(m['kind'] ?? m['direction']),
                amount: Money.valueOrZero(m['amount']),
                returnOn: _sn(m['return_on'])),
        ],
      );
}

/// জমা বা হাতধারের একটা চলাচল
class Movement {
  const Movement({this.date, required this.kind, required this.amount, this.returnOn});

  final String? date;

  /// জমায়: opened · instalment · payout · closed; হাতধারে: out · in
  final String kind;
  final double amount;
  final String? returnOn;
}

class Instalment {
  const Instalment({
    this.month,
    this.dueOn,
    required this.amount,
    this.principal = 0,
    this.interest = 0,
    required this.state,
  });

  final int? month;
  final String? dueOn;
  final double amount;
  final double principal;
  final double interest;

  /// paid · overdue · today · upcoming · undated
  final String state;

  factory Instalment.fromJson(Map<String, dynamic> j) => Instalment(
        month: _i(j['month']),
        dueOn: _sn(j['due_on']),
        amount: Money.valueOrZero(j['amount']),
        principal: Money.valueOrZero(j['principal']),
        interest: Money.valueOrZero(j['interest']),
        state: _s(j['state']),
      );
}

class BankLoan {
  const BankLoan({
    required this.id,
    required this.no,
    this.kind = '',
    this.bank = '',
    required this.limit,
    required this.outstanding,
    required this.available,
    this.interestRate,
    this.sanctionedOn,
    this.renewsOn,
    this.status = '',
    this.next,
    this.branchName = '',
    this.sanctionNo = '',
    this.instalments,
    this.instalmentAmount,
    this.instalmentsPaid,
    this.instalmentsLeft,
    this.overdue = 0,
    this.schedule = const [],
  });

  final String id;
  final String no;

  /// cc · term · ltr · lease · bg
  final String kind;
  final String bank;
  final double limit;
  final double outstanding;
  final double available;
  final String? interestRate;
  final String? sanctionedOn;
  final String? renewsOn;
  final String status;
  final Instalment? next;
  final String branchName;
  final String sanctionNo;
  final int? instalments;
  final double? instalmentAmount;
  final int? instalmentsPaid;
  final int? instalmentsLeft;
  final int overdue;
  final List<Instalment> schedule;

  factory BankLoan.fromJson(Map<String, dynamic> j) => BankLoan(
        id: _s(j['id']),
        no: _s(j['no']),
        kind: _s(j['kind']),
        bank: _s(j['bank']),
        limit: Money.valueOrZero(j['limit']),
        outstanding: Money.valueOrZero(j['outstanding']),
        available: Money.valueOrZero(j['available']),
        interestRate: _sn(j['interest_rate']),
        sanctionedOn: _sn(j['sanctioned_on']),
        renewsOn: _sn(j['renews_on']),
        status: _s(j['status']),
        next: j['next'] is Map ? Instalment.fromJson(Map<String, dynamic>.from(j['next'] as Map)) : null,
        branchName: _s(j['branch_name']),
        sanctionNo: _s(j['sanction_no']),
        instalments: _i(j['instalments']),
        instalmentAmount: Money.value(j['instalment_amount']),
        instalmentsPaid: _i(j['instalments_paid']),
        instalmentsLeft: _i(j['instalments_left']),
        overdue: _i(j['overdue']) ?? 0,
        schedule: [for (final r in _rows(j['schedule'])) Instalment.fromJson(r)],
      );
}

class RentMonth {
  const RentMonth(this.month, this.amount);

  final String month;
  final double amount;
}

/// একটা ভাড়ার চুক্তি — [side] `pay` আমরা ভাড়া দিই, `let` আমরা ভাড়া দিয়েছি
class Rent {
  const Rent({
    required this.id,
    required this.side,
    required this.no,
    this.party = '',
    this.place = '',
    required this.monthlyRent,
    this.rentDay,
    this.endsOn,
    this.daysLeft,
    this.nextDueOn,
    this.overdueCount = 0,
    this.overdueAmount = 0,
    this.outstanding = 0,
    this.oldestUnpaid,
    this.status = '',
    this.phone = '',
    this.startsOn,
    this.deposit = 0,
    this.depositLeft,
    this.overdueMonths = const [],
    this.note = '',
  });

  final String id;
  final String side;
  final String no;
  final String party;
  final String place;
  final double monthlyRent;
  final int? rentDay;
  final String? endsOn;
  final int? daysLeft;
  final String? nextDueOn;
  final int overdueCount;
  final double overdueAmount;
  final double outstanding;
  final String? oldestUnpaid;
  final String status;
  final String phone;
  final String? startsOn;
  final double deposit;
  final double? depositLeft;
  final List<RentMonth> overdueMonths;
  final String note;

  bool get weLet => side == 'let';

  factory Rent.fromJson(Map<String, dynamic> j) => Rent(
        id: _s(j['id']),
        side: _s(j['side']).isEmpty ? 'pay' : _s(j['side']),
        no: _s(j['no']),
        party: _s(j['party']),
        place: _s(j['place']),
        monthlyRent: Money.valueOrZero(j['monthly_rent']),
        rentDay: _i(j['rent_day']),
        endsOn: _sn(j['ends_on']),
        daysLeft: _i(j['days_left']),
        nextDueOn: _sn(j['next_due_on']),
        overdueCount: _i(j['overdue_count']) ?? 0,
        overdueAmount: Money.valueOrZero(j['overdue_amount']),
        outstanding: Money.valueOrZero(j['outstanding']),
        oldestUnpaid: _sn(j['oldest_unpaid']),
        status: _s(j['status']),
        phone: _s(j['phone']),
        startsOn: _sn(j['starts_on']),
        deposit: Money.valueOrZero(j['deposit']),
        depositLeft: Money.value(j['deposit_left'] ?? j['deposit_held']),
        overdueMonths: [
          for (final m in _rows(j['overdue_months'])) RentMonth(_s(m['month']), Money.valueOrZero(m['amount'])),
        ],
        note: _s(j['note']),
      );
}

class HandLoan {
  const HandLoan({
    required this.id,
    required this.name,
    this.mobile = '',
    required this.balance,
    this.dueOn,
    this.repayment = '',
    this.status = '',
    this.principal,
    this.interestRate,
    this.note = '',
    this.movements = const [],
  });

  final String id;
  final String name;
  final String mobile;

  /// ধনাত্মক = তিনি আমাদের দেবেন; ঋণাত্মক = আমরা তাঁকে দেব
  final double balance;
  final String? dueOn;
  final String repayment;
  final String status;
  final double? principal;
  final String? interestRate;
  final String note;
  final List<Movement> movements;

  factory HandLoan.fromJson(Map<String, dynamic> j) => HandLoan(
        id: _s(j['id']),
        name: _s(j['name']),
        mobile: _s(j['mobile']),
        balance: Money.valueOrZero(j['balance']),
        dueOn: _sn(j['due_on']),
        repayment: _s(j['repayment']),
        status: _s(j['status']),
        principal: Money.value(j['principal']),
        interestRate: _sn(j['interest_rate']),
        note: _s(j['note']),
        movements: [
          for (final m in _rows(j['movements']))
            Movement(
                date: _sn(m['date']),
                kind: _s(m['direction']),
                amount: Money.valueOrZero(m['amount']),
                returnOn: _sn(m['return_on'])),
        ],
      );
}

class HandLoanBook {
  const HandLoanBook({required this.rows, this.owedToUs = 0, this.weOwe = 0});

  final List<HandLoan> rows;
  final double owedToUs;
  final double weOwe;
}

abstract class FinanceApi {
  Future<FinancePage<Deposit>> deposits({String tab = 'active', int page = 1});
  Future<Deposit> deposit(String id);
  Future<FinancePage<BankLoan>> loans({String tab = 'open', int page = 1});
  Future<BankLoan> loan(String id);
  Future<FinancePage<Rent>> rents({String side = 'pay', String tab = 'running', int page = 1});
  Future<Rent> rent(String side, String id);
  Future<HandLoanBook> handLoans();
  Future<HandLoan> handLoan(String id);
}

class ServerFinanceApi implements FinanceApi {
  const ServerFinanceApi();

  Future<Map<String, dynamic>> _get(String path, [Map<String, dynamic>? query]) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>('/finance$path', queryParameters: query);
    return response.data ?? const <String, dynamic>{};
  }

  FinancePage<T> _page<T>(Map<String, dynamic> body, T Function(Map<String, dynamic>) one) =>
      FinancePage([for (final r in _rows(body['rows'])) one(r)], nextPage: _i(body['next_page']));

  @override
  Future<FinancePage<Deposit>> deposits({String tab = 'active', int page = 1}) async =>
      _page(await _get('/deposits', {'tab': tab, 'page': page}), Deposit.fromJson);

  @override
  Future<Deposit> deposit(String id) async => Deposit.fromJson(await _get('/deposits/${Uri.encodeComponent(id)}'));

  @override
  Future<FinancePage<BankLoan>> loans({String tab = 'open', int page = 1}) async =>
      _page(await _get('/loans', {'tab': tab, 'page': page}), BankLoan.fromJson);

  @override
  Future<BankLoan> loan(String id) async => BankLoan.fromJson(await _get('/loans/${Uri.encodeComponent(id)}'));

  @override
  Future<FinancePage<Rent>> rents({String side = 'pay', String tab = 'running', int page = 1}) async =>
      _page(await _get('/rents', {'side': side, 'tab': tab, 'page': page}), Rent.fromJson);

  @override
  Future<Rent> rent(String side, String id) async =>
      Rent.fromJson(await _get('/rents/${side == 'let' ? 'let' : 'pay'}/${Uri.encodeComponent(id)}'));

  @override
  Future<HandLoanBook> handLoans() async {
    final body = await _get('/hand-loans');
    return HandLoanBook(
      rows: [for (final r in _rows(body['rows'])) HandLoan.fromJson(r)],
      owedToUs: Money.valueOrZero(body['owed_to_us']),
      weOwe: Money.valueOrZero(body['we_owe']),
    );
  }

  @override
  Future<HandLoan> handLoan(String id) async => HandLoan.fromJson(await _get('/hand-loans/${Uri.encodeComponent(id)}'));
}
