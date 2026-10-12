import 'package:flutter/material.dart';

import '../../core/api_client/api_client.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../books/books_widgets.dart';

/// ⭐ দোকানের খাতা, সহজ করে — মালিক, ১২ অক্টোবর ২০২৬: *"app e customer list e Customar ledger zate dekha zay sohoze
/// bujarmoto mobile vew sohoz kore koro"*।
///
/// <p>উপরে একটাই বড় অঙ্ক — কত বাকি (বা কত আগাম জমা)। নিচে মাস ধরে লেনদেন, নতুন আগে; প্রতিটা সারি সাধারণ কথায়:
/// "মাল নিয়েছেন" (বাকি বাড়ল, লাল) বা "টাকা দিয়েছেন" (বাকি কমল, সবুজ), আর সারির পরে কত বাকি দাঁড়াল।
/// ডেবিট-ক্রেডিট শব্দ পর্দায় নেই — দোকানের সামনে দাঁড়ানো SR-এর প্রশ্ন "কত বাকি, কবে কী নিল, কবে কত দিল"।
///
/// <p>সার্ভারের খাতাই ([[CustomerLedgerApiController]]) — ওয়েবের গ্রাহক-পাতার একই সারি আর জের, একই শাখা আর ডিলারের দেয়াল।
class CustomerLedgerScreen extends StatefulWidget {
  const CustomerLedgerScreen(
      {super.key, required this.customerId, this.api = const ServerCustomerLedgerApi()});

  final String customerId;
  final CustomerLedgerApi api;

  @override
  State<CustomerLedgerScreen> createState() => _CustomerLedgerScreenState();
}

class _CustomerLedgerScreenState extends State<CustomerLedgerScreen> {
  CustomerLedger? _head;
  final List<CustomerLedgerRow> _rows = [];
  int? _next;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = await widget.api
          .ledger(widget.customerId, page: more ? (_next ?? 1) : 1);
      if (!mounted) return;
      setState(() {
        _head = page;
        if (!more) _rows.clear();
        _rows.addAll(page.rows);
        _next = page.nextPage;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e, fallback: 'খাতা আনা গেল না।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final head = _head;
    final children = <Widget>[
      if (_busy && head == null) const LinearProgressIndicator(),
      if (_error != null) BooksError(_error!),
      if (head != null) _DueHeader(head: head),
      if (head != null && _rows.isEmpty && !_busy)
        const Padding(
          padding: EdgeInsets.all(AppSpacing.lg),
          child: Center(child: Text('এই দোকানের খাতায় এখনো কোনো লেনদেন নেই।')),
        ),
    ];

    String? month;
    for (final r in _rows) {
      final m = monthOf(r.date);
      if (m != month) {
        month = m;
        children.add(Padding(
          padding: const EdgeInsets.fromLTRB(4, AppSpacing.md, 4, AppSpacing.xs),
          child: Text(m,
              style: const TextStyle(
                  fontWeight: FontWeight.w700, color: AppColors.onSurfaceMuted)),
        ));
      }
      children.add(_Row(row: r));
    }

    if (_next != null) {
      children.add(Center(
        child: TextButton.icon(
          key: const ValueKey('ledger-more'),
          onPressed: _busy ? null : () => _load(more: true),
          icon: const Icon(Icons.expand_more),
          label: const Text('আরও আগের লেনদেন'),
        ),
      ));
    }

    return Scaffold(
      appBar: AppBar(title: Text(head?.name ?? 'খাতা')),
      body: RefreshIndicator(
        onRefresh: () => _load(),
        child: ListView(
          physics: const AlwaysScrollableScrollPhysics(),
          padding: const EdgeInsets.all(AppSpacing.md),
          children: children,
        ),
      ),
    );
  }
}

/// উপরের বড় অঙ্ক — বাকি লাল, আগাম জমা সবুজ, মেলানো হলে শান্ত
class _DueHeader extends StatelessWidget {
  const _DueHeader({required this.head});

  final CustomerLedger head;

  @override
  Widget build(BuildContext context) {
    final due = head.due;
    final (label, colour) = due > 0.004
        ? ('বাকি আছে', AppColors.danger)
        : due < -0.004
            ? ('আগাম জমা আছে', AppColors.success)
            : ('হিসাব মিলে আছে', AppColors.onSurface);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: const TextStyle(color: AppColors.onSurfaceMuted)),
            Text(Money.taka(due.abs()),
                key: const ValueKey('ledger-due'),
                style: TextStyle(
                    fontSize: 26, fontWeight: FontWeight.w800, color: colour)),
            if (head.code.isNotEmpty)
              Text('কোড ${head.code}',
                  style: const TextStyle(
                      fontSize: 12, color: AppColors.onSurfaceMuted)),
          ],
        ),
      ),
    );
  }
}

/// একটা লেনদেন — সাধারণ কথায়, ডেবিট-ক্রেডিট শব্দ ছাড়া
class _Row extends StatelessWidget {
  const _Row({required this.row});

  final CustomerLedgerRow row;

  @override
  Widget build(BuildContext context) {
    final took = row.debit > 0.004;
    final colour = took ? AppColors.danger : AppColors.success;
    final amount = took ? row.debit : row.credit;

    return Card(
      margin: const EdgeInsets.only(bottom: AppSpacing.xs),
      child: ListTile(
        leading: CircleAvatar(
          backgroundColor: colour.withValues(alpha: 0.12),
          child: Icon(took ? Icons.shopping_bag_outlined : Icons.payments_outlined,
              color: colour, size: 20),
        ),
        title: Text(took ? 'মাল নিয়েছেন' : 'টাকা দিয়েছেন',
            style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Text(
          [
            [dayOf(row.date), if (row.no.isNotEmpty) row.no].join(' · '),
            if (row.narration.isNotEmpty) row.narration,
          ].join('\n'),
          maxLines: 3,
          overflow: TextOverflow.ellipsis,
        ),
        isThreeLine: row.narration.isNotEmpty,
        trailing: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          crossAxisAlignment: CrossAxisAlignment.end,
          children: [
            Text('${took ? '+' : '−'}${Money.taka(amount)}',
                style: TextStyle(fontWeight: FontWeight.w700, color: colour)),
            Text(dueWords(row.balance),
                style: const TextStyle(
                    fontSize: 11.5, color: AppColors.onSurfaceMuted)),
          ],
        ),
      ),
    );
  }
}

/// সারির পরে কত দাঁড়াল — "বাকি ৳…" বা "জমা ৳…"
String dueWords(double balance) {
  if (balance.abs() < 0.005) return 'মিলে গেছে';
  return balance > 0
      ? 'বাকি ${Money.taka(balance)}'
      : 'জমা ${Money.taka(-balance)}';
}

const _months = [
  'জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন',
  'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর',
];

const _digits = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

/// `2026-10-12` → "অক্টোবর ২০২৬"
String monthOf(String? iso) {
  final d = iso == null ? null : DateTime.tryParse(iso);
  if (d == null) return 'তারিখ নেই';
  final year = d.year.toString().split('').map((c) => _digits[int.parse(c)]).join();
  return '${_months[d.month - 1]} $year';
}

class CustomerLedgerRow {
  const CustomerLedgerRow({
    this.date,
    this.no = '',
    this.narration = '',
    required this.debit,
    required this.credit,
    required this.balance,
  });

  final String? date;
  final String no;
  final String narration;
  final double debit;
  final double credit;
  final double balance;
}

class CustomerLedger {
  const CustomerLedger({
    required this.name,
    this.code = '',
    required this.due,
    required this.rows,
    this.nextPage,
  });

  final String name;
  final String code;
  final double due;
  final List<CustomerLedgerRow> rows;
  final int? nextPage;
}

abstract class CustomerLedgerApi {
  Future<CustomerLedger> ledger(String customerId, {int page = 1});
}

class ServerCustomerLedgerApi implements CustomerLedgerApi {
  const ServerCustomerLedgerApi();

  @override
  Future<CustomerLedger> ledger(String customerId, {int page = 1}) async {
    final response = await ApiClient.dio.get<Map<String, dynamic>>(
        '/sales/customers/$customerId/ledger',
        queryParameters: {'page': page});
    final body = response.data ?? const <String, dynamic>{};
    final entries = body['entries'];

    return CustomerLedger(
      name: body['name']?.toString() ?? '',
      code: body['code']?.toString() ?? '',
      due: Money.valueOrZero(body['due']),
      rows: [
        if (entries is List)
          for (final e in entries)
            if (e is Map)
              CustomerLedgerRow(
                date: e['date']?.toString(),
                no: e['no']?.toString() ?? '',
                narration: e['narration']?.toString() ?? '',
                debit: Money.valueOrZero(e['debit']),
                credit: Money.valueOrZero(e['credit']),
                balance: Money.valueOrZero(e['balance']),
              ),
      ],
      nextPage: (body['next_page'] as num?)?.toInt(),
    );
  }
}
