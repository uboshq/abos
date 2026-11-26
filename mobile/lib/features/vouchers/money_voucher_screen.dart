import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter/semantics.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../core/accounts/voucher_api.dart';
import '../../core/api_client/network_errors.dart';
import '../../core/books/collection_entry.dart' show paisaText;
import '../../core/locale/l10n.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/records/money.dart';
import '../../core/records/money_input.dart';
import '../../core/status/app_status_chip.dart';
import '../../core/status/paper_state.dart';
import '../../core/sync_engine/sync_engine.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/unsaved_guard.dart';
import '../books/money_form_parts.dart';
import 'voucher_screens.dart' show VoucherPickSheet;

/// ⭐ টাকা দেওয়া — পরিশোধ আর খরচের ভাউচার, ওয়েবের একই ঘর, একই নাম, একই ক্রম (মালিক, ২১ অক্টোবর ২০২৬: আন্তর্জাতিক গড়ন, "ওয়েব
/// আর অ্যাপ দুটোতেই"; ২৪ অক্টোবর ২০২৬: "সবাই ভাউচারে — receipt, payment, expense"; ওয়েব cb 2bddefbb0: simple-form,
/// party-fields, expense-fields আর x-money.method)।
///
/// <p><b>পরিশোধ</b>, এক কলামে: মাথায় তিন অঙ্ক (পাওনা · এই পরিশোধ · বাকি থাকবে); "সাধারণ তথ্য" — পক্ষের ধরন, পক্ষ ("নাম ·
/// পয়েন্ট · শাখা", পাশে পাওনা), তারিখ, টাকা ("পুরো বকেয়া"); পদ্ধতি → কেবল সেই পদ্ধতির খাত ("যে খাত থেকে", নিজের নগদ আগে বাছা) →
/// প্রাপকের ব্যাংক (সরবরাহকারীর রেকর্ড থেকে, কেবল পড়ার) → পদ্ধতির নিজের ঘর; নিচে বিবরণ; স্থির সারি "বাতিল · খসড়া রাখুন · পোস্ট"।
///
/// <p><b>খরচ</b>: "সাধারণ তথ্য" — তারিখ, খরচের খাত, কাকে দেওয়া হলো, বিল/ভাউচার নম্বর; "উৎসে কর্তন" (গুটানো); "কীভাবে দেওয়া
/// হলো" — পদ্ধতি → যে খাত থেকে (বাকিতে হলে দেনার খাত, তখন পক্ষ বাধ্যতামূলক) → পদ্ধতির ঘর; তারপর টাকা আর বিবরণ।
///
/// <p>⛔ পদ্ধতি আর খাত কখনো আলাদা কথা বলে না ([[MoneyMethod::KIND_OF]]: নগদ → cash, MFS → mfs, ট্রান্সফার/চেক → bank): খাতের
/// তালিকায় কেবল চলতি পদ্ধতির খাত, পদ্ধতি বদলালে না-মেলা খাত সরে মেলা খাত বসে, আর পাঠানোর আগে আবার দেখা — সার্ভার যা ফেরাত
/// (`way_does_not_fit_account`) ফোন তা পাঠায়ই না। ⛔ যা নেই, ইচ্ছাকৃত: দাতার ব্যাংক, শাখা, হিসাবধারী, হিসাব নম্বর; "চার্জটা কে
/// দিল" (চার্জ আমাদের খরচ, চার্জের খাতে); সরবরাহকারীর পরিশোধে "কাকে দেওয়া হল — পক্ষ থেকে…" (সার্ভার নিজে দেনার খাত বসায়)।
///
/// <p>⛔ পুরনো সার্ভার: সেটআপে মোডের তালিকা, পক্ষের পাওনা বা প্রাপকের ব্যাংক না এলে সেই ঘর আঁকা হয় না (আন্দাজ নয়); ঘরের নাম
/// সব আগের সার্ভারেরও চেনা ([[VoucherRequest]]), কেবল `value_date` 2bddefbb0-এর আগে নীরবে পড়ে থাকে।
class MoneyVoucherScreen extends StatefulWidget {
  const MoneyVoucherScreen({
    super.key,
    required this.type,
    this.api = const ServerVoucherApi(),
    this.newChangeId = _engineChangeId,
    this.today,
  }) : assert(type == VoucherTypes.payment || type == VoucherTypes.expense);

  static String _engineChangeId() => SyncEngine.instance.newChangeId();

  /// [VoucherTypes.payment] বা [VoucherTypes.expense]
  final String type;
  final VoucherApi api;
  final String Function() newChangeId;
  final DateTime? today;

  /// ⭐ ওয়েবের পদ্ধতি, ক্রমে — পরিশোধ আর খরচে নগদ জমা নেই (simple-form `:methods`, expense-fields `:methods`)
  static const methods = ['cash', 'mfs', 'transfer', 'cheque'];

  /// ⭐ পদ্ধতি → খাতের ধরন — সার্ভারের [[MoneyMethod::KIND_OF]] হুবহু
  static const kindOf = {'cash': 'cash', 'mfs': 'mfs', 'transfer': 'bank', 'deposit': 'bank', 'cheque': 'bank'};

  /// খাতটা এই পদ্ধতির কি না — ধরন খালি (বাকির খাত) হলে পদ্ধতির প্রশ্নই নেই
  static bool fits(String method, String? kind) => kind == null || kind.isEmpty || kindOf[method] == kind;

  /// যে পদ্ধতিতে চার্জ হয় ([[MoneyMethod::CHARGEABLE]]; নগদ জমা এখানে নেই)
  static bool chargeable(String method) => method == 'mfs' || method == 'transfer';

  @override
  State<MoneyVoucherScreen> createState() => _MoneyVoucherScreenState();
}

class _MoneyVoucherScreenState extends State<MoneyVoucherScreen> {
  static final _amountShape = RegExp(r'^\d{1,14}(\.\d{1,2})?$');

  VoucherSetup? _setup;
  String? _error;
  bool _busy = false;
  String? _changeId;

  late DateTime _date;
  DateTime? _valueDate;
  DateTime? _chequeDate;

  String? _partyType;
  VoucherParty? _party;
  VoucherAccount? _toAccount;
  VoucherAccount? _head;

  String _method = 'cash';
  VoucherAccount? _account;
  int? _transferModeId;

  final _amount = TextEditingController();
  final _reference = TextEditingController();
  final _charge = TextEditingController();
  final _chequeBank = TextEditingController();
  final _payee = TextEditingController();
  final _billNo = TextEditingController();
  final _grossText = TextEditingController();
  final _ait = TextEditingController();
  final _vds = TextEditingController();
  final _narration = TextEditingController();
  final _amountFocus = FocusNode(debugLabel: 'amount');

  /// ⭐ যে টাকার ঘরে পেস্টের চিহ্ন এইমাত্র ঝরেছে — আদায়ের পর্দার একই নিয়ম
  final Set<String> _signDropped = {};

  // ── ⭐ চুক্তি ২ (cb, ২৫ অক্টোবর ২০২৬: পরিশোধে মোট টাকা) ──

  /// টিক দেওয়া বিল (public_id) আর তাদের অঙ্কের ঘর; বিলের সারির নিজের ভুল
  final Set<String> _ticked = {};
  final Map<String, TextEditingController> _billText = {};
  final Map<String, String> _billError = {};

  /// শেষ পাঠানো বা দেখা payload-এর বিলের ক্রম — `bills.{i}.amount` এই ক্রম ধরে সারিতে ফেরে
  List<String> _sentBills = const [];

  /// ⭐ সার্ভারের ৪২২ — ঘরের চাবি → কথা ([voucherFieldErrors])
  Map<String, String> _fieldErrors = const {};

  /// সার্ভারের হিসাব (নিট, খাতা) আর কোন payload-এর — payload বদলালে আর দেখানো নয়
  VoucherPreview? _preview;
  String? _previewFor;

  /// হিসাবের দরজা নেই (৪০৪/৪০৫) — বোতাম আর দেখানো নয়
  bool _previewAbsent = false;

  bool get _payment => widget.type == VoucherTypes.payment;

  /// ⭐ নতুন সার্ভার (`voucher_contract` ২): পরিশোধের টাকা মোট, AIT/VDS আর বিল; পুরনোয় সব আগের মতো
  bool get _gross => _payment && (_setup?.contract ?? 1) >= 2;

  DateTime get _today {
    final now = widget.today ?? _setup?.today ?? DateTime.now();
    return DateTime(now.year, now.month, now.day);
  }

  @override
  void initState() {
    super.initState();
    _date = _today;
    for (final c in [_amount, _reference, _charge, _chequeBank, _payee, _billNo, _grossText, _ait, _vds, _narration]) {
      c.addListener(_typed);
    }
    _amountFocus.addListener(() {
      if (!_amountFocus.hasFocus) _formatAmount();
    });
    widget.api.setup(widget.type).then(_loaded, onError: (Object e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.vouchersFormSetupFailed));
    });
  }

  @override
  void dispose() {
    for (final c in [_amount, _reference, _charge, _chequeBank, _payee, _billNo, _grossText, _ait, _vds, _narration, ..._billText.values]) {
      c.dispose();
    }
    _amountFocus.dispose();
    super.dispose();
  }

  void _typed() {
    if (mounted) setState(() {});
  }

  void _loaded(VoucherSetup s) {
    if (!mounted) return;
    setState(() {
      _setup = s;
      _date = _today;
      _valueDate = _today;
      final types = s.partyTypes.map((t) => t.$1).toList();
      _partyType = types.contains('supplier') ? 'supplier' : types.firstOrNull;
      // ⭐ প্রথম পদ্ধতি — যে পদ্ধতির খাত আছে, নগদ আগে ([[MoneyMethod::startWith()]]); খাত নিজের নগদ ([[DepositFormOptions]]
      // কেবল নিজের টিলের নগদ পাঠায়), তাই নগদ থাকলে সেটাই বসে
      _method = MoneyVoucherScreen.methods.firstWhere(_hasAccounts, orElse: () => 'cash');
      _account = _accountsFor(_method).firstOrNull;
    });
  }

  // ── খাত আর পদ্ধতি ──

  List<VoucherAccount> get _fromAll => _setup?.from?.accounts ?? const [];

  /// টাকার খাত — ধরন আছে এমন
  List<VoucherAccount> get _moneyAccounts => _fromAll.where((a) => (a.kind ?? '').isNotEmpty).toList();

  /// বাকির খাত — খরচের "যে খাত থেকে"-র দেনার দল (ধরন নেই)
  List<VoucherAccount> get _creditAccounts => _payment ? const [] : _fromAll.where((a) => (a.kind ?? '').isEmpty).toList();

  bool _hasAccounts(String method) => _moneyAccounts.any((a) => a.kind == MoneyVoucherScreen.kindOf[method]);

  /// ⭐ এই পদ্ধতির খাত কেবল — বাকিগুলো তালিকায় থাকেই না (fe, ২৪ অক্টোবর ২০২৬)
  List<VoucherAccount> _accountsFor(String method) =>
      _moneyAccounts.where((a) => a.kind == MoneyVoucherScreen.kindOf[method]).toList();

  bool get _onCredit => _account != null && (_account!.kind ?? '').isEmpty;

  /// ⭐ পদ্ধতি বদলালে আগের খাত মিললে থাকে; না মিললে সেই পদ্ধতির প্রথম খাত (নগদে নিজের নগদ) — কখনো না-মেলা জোড়া নয়
  void _chooseMethod(String method) {
    setState(() {
      _method = method;
      if (_account == null || _onCredit || !MoneyVoucherScreen.fits(method, _account!.kind)) {
        _account = _accountsFor(method).firstOrNull;
      }
    });
  }

  void _chooseAccount(VoucherAccount? a) {
    if (a == null) return;
    setState(() {
      _account = a;
      // ⓘ খাতের ধরন পদ্ধতি ঠিক করে, যদি না মেলে — খরচের পুরনো পথ (expense-fields: খাত বদলালে পদ্ধতি সরে)
      if (!MoneyVoucherScreen.fits(_method, a.kind)) {
        _method = MoneyVoucherScreen.methods.firstWhere((m) => MoneyVoucherScreen.kindOf[m] == a.kind, orElse: () => _method);
      }
    });
  }

  // ── অঙ্ক ──

  static int _paisa(String text) => ((Money.typed(text) ?? 0) * 100).round();

  int get _amountPaisa => _paisa(_amount.text);

  bool get _amountValid => _amountShape.hasMatch(Money.typedText(_amount.text)) && _amountPaisa > 0;

  void _formatAmount() {
    if (!_amountShape.hasMatch(Money.typedText(_amount.text))) return;
    final formatted = Money.amount(_amountPaisa / 100);
    if (_amount.text != formatted) _amount.text = formatted;
  }

  MoneyInputFormatter _money(String field) => MoneyInputFormatter(onEdit: (dropped) {
        if (dropped) {
          SemanticsService.sendAnnouncement(View.of(context), context.l10n.collectSignDropped, Directionality.of(context));
        }
        if (dropped == _signDropped.contains(field)) return;
        setState(() => dropped ? _signDropped.add(field) : _signDropped.remove(field));
      });

  String? _signNote(String field) => _signDropped.contains(field) ? context.l10n.collectSignDropped : null;

  int? get _due => _party?.due;

  // ── পদ্ধতির ঘর ──

  bool get _movesMoney => _account != null && !_onCredit;
  bool get _needsReference => _movesMoney && _method != 'cash';
  bool get _showsCharge => _movesMoney && MoneyVoucherScreen.chargeable(_method);
  bool get _isTransfer => _movesMoney && _method == 'transfer';
  bool get _isCheque => _movesMoney && _method == 'cheque';

  bool get _toSupplier => _payment && _partyType == 'supplier';

  /// ⛔ সরবরাহকারীর ব্যাংকে টাকা — তাঁর ব্যাংক তথ্য দুজনের সইয়ে যাচাই না হলে নয় ([[SupplierBank::assertBankPayable()]]; ওয়েবের
  /// পরিশোধ ট্রান্সফার আর নগদ জমায় থামায়, এখানে নগদ জমা নেই)। ⓘ সার্ভার ব্যাংকের কথা না পাঠালে (2bddefbb0 পর্যন্ত) ফোন জানে
  /// না — তখন আটকায় না, সার্ভারের নিয়মই চলে
  bool get _bankBlocked => _toSupplier && _isTransfer && _party?.bank != null && !_party!.bank!.signed;

  // ── ⭐ বিল (চুক্তি ২, অংশ ৩) ──

  /// ⭐ পক্ষের খোলা বিল — পক্ষ বাছলে দরজা থেকে ([VoucherBillsSource]); দরজা নেই বা পুরনো সার্ভার হলে খালি
  List<VoucherBill> _partyBills = const [];

  /// ⭐ আগের জের (ওপেনিং) বা অগ্রিম — দরজা পাঠালে; আগের জেরে টিক দিয়ে টাকা লেখা যায় (মোটে ধরা), কিন্তু কখনো পাঠানো নয়
  ///   — টাকাটা পক্ষের খাতায় থাকে, জের থেকেই কমে (মালিকের নমুনা v2, ২৬ নভেম্বর ২০২৬)
  VoucherBill? _opening;

  /// আগের জেরে লেখা টাকা — অগ্রিমে কখনো নয়
  int get _openingAmount => switch (_opening) { final o? when o.tickable => _billAmount(o), _ => 0 };

  List<VoucherBill> get _bills => _gross ? _partyBills : const [];

  /// ⭐ পক্ষ বাছা — বিল আর টিক নতুন করে; চুক্তি ২-এ তার খোলা বিল আনা (৪০৪/৪০৫ হলে বিল নেই)
  Future<void> _chooseParty(VoucherParty? p) async {
    setState(() {
      _party = p;
      _partyBills = const [];
      _opening = null;
      _ticked.clear();
      _billError.clear();
    });
    final api = widget.api;
    final Object source = api;
    if (p == null || !_gross || source is! VoucherBillsSource) return;
    try {
      final bills = await source.openBills(p.type, p.id);
      if (!mounted || !identical(_party, p)) return;
      setState(() {
        _opening = bills?.where((b) => b.opening).firstOrNull;
        _partyBills = [...?bills?.where((b) => !b.opening)]..sort((a, b) => a.date.compareTo(b.date));
      });
    } catch (e) {
      if (mounted && identical(_party, p)) setState(() => _error = errorMessageFor(e, fallback: context.l10n.vouchersFormSetupFailed));
    }
  }

  int _billAmount(VoucherBill b) => _ticked.contains(b.id) ? _paisa(_billText[b.id]?.text ?? '') : 0;

  int get _billsSum => _bills.fold(_openingAmount, (sum, b) => sum + _billAmount(b));

  /// ⛔ বিলের যোগফল মোটের বেশি — সার্ভার ফেরায় (`bills_over_gross`); পাঠানো বন্ধ
  bool get _billsOverGross => _billsSum > _amountPaisa;

  /// ⭐ বিল বাছলে মোট টাকায় বাছা বিলগুলোর বকেয়ার যোগফল; সারির অঙ্ক তার বকেয়া
  void _tickBill(VoucherBill b, bool on) {
    setState(() {
      _billError.remove(b.id);
      final c = _billText.putIfAbsent(b.id, TextEditingController.new);
      if (on) {
        _ticked.add(b.id);
        c.text = Money.amount(b.outstanding / 100);
      } else {
        _ticked.remove(b.id);
        c.text = '';
      }
      final opening = _opening;
      final dues = _bills.where((x) => _ticked.contains(x.id)).fold(
          opening != null && opening.tickable && _ticked.contains(opening.id) ? opening.outstanding : 0, (sum, x) => sum + x.outstanding);
      _amount.text = dues == 0 ? '' : Money.amount(dues / 100);
    });
  }

  /// ⛔ বিলের অঙ্ক বিলের বকেয়ার বেশি নয় — বেশি লিখলে বকেয়াতেই থামে, কারণসহ (সার্ভারের `bill_over_due`)
  void _typeBill(VoucherBill b, String text) {
    final want = _paisa(text);
    setState(() {
      _billError.remove(b.id);
      if (want > b.outstanding) {
        _billError[b.id] = context.l10n.payBillOverDue(Money.taka(b.outstanding / 100));
        final c = _billText[b.id]!;
        final capped = Money.amount(b.outstanding / 100);
        c.value = TextEditingValue(text: capped, selection: TextSelection.collapsed(offset: capped.length));
      }
    });
  }

  VoucherDraft _draft({required bool keep}) => VoucherDraft(
        type: widget.type,
        date: _date,
        narration: _narration.text,
        fromAccountId: _account?.id,
        // ⓘ পরিশোধে সরবরাহকারী হলে ফাঁকা — সার্ভার দেনার খাত (২১১১) নিজে বসায় ([[VoucherRequest::fillAccountFromParty()]])
        toAccountId: _payment ? (_toSupplier ? null : _toAccount?.id) : _head?.id,
        expenseAccountId: _payment ? null : _head?.id,
        // ⭐ চুক্তি ২: টাকার ঘর মোট (`gross_amount`), পুরনো `amount` কখনো নয় — দুটো একসাথে সার্ভার ফেরায় (`gross_or_net_not_both`)
        amount: _gross ? null : Money.typedText(_amount.text),
        partyType: _party?.type,
        partyId: _party?.id,
        instrument: _movesMoney ? _method : null,
        instrumentNo: _needsReference ? _reference.text.trim() : null,
        transferModeId: _isTransfer ? _transferModeId : null,
        valueDate: _isTransfer ? (_valueDate ?? _today) : null,
        chargeAmount: _showsCharge && _paisa(_charge.text) > 0 ? paisaText(_paisa(_charge.text)) : null,
        instrumentDate: _isCheque ? _chequeDate : null,
        fromBank: _isCheque ? _chequeBank.text : null,
        payeeName: _payment ? null : _payee.text,
        billNo: _payment ? null : _billNo.text,
        grossAmount: _gross
            ? Money.typedText(_amount.text)
            : (!_payment && _paisa(_grossText.text) > 0 ? paisaText(_paisa(_grossText.text)) : null),
        aitAmount: (!_payment || _gross) && _paisa(_ait.text) > 0 ? paisaText(_paisa(_ait.text)) : null,
        vdsAmount: (!_payment || _gross) && _paisa(_vds.text) > 0 ? paisaText(_paisa(_vds.text)) : null,
        bills: _gross
            ? [
                for (final b in _bills)
                  if (_billAmount(b) > 0) {'id': b.id, 'amount': paisaText(_billAmount(b))},
              ]
            : null,
        keepAsDraft: keep,
      );

  String? _problem({required bool post}) {
    final w = context.l10n;
    final s = _setup;
    if (s == null) return w.vouchersFormSetupMissing;
    if (_payment && _party == null) return w.payPickPartyError;
    if (!_payment && _head == null) return w.payPickHeadError;
    if (!_amountValid) return w.vouchersFormAmountInvalid;
    if (_account == null) return w.payPickAccountError;
    // ⛔ শেষ পাহারা — পর্দা না-মেলা জোড়া বানাতেই দেয় না, তবু পাঠানোর আগে আবার দেখা
    if (!MoneyVoucherScreen.fits(_method, _account!.kind)) {
      return w.payMismatch(_account!.label, moneyMethodLabel(context, _method));
    }
    if (_onCredit && _party == null) return w.payCreditNeedsParty;
    if (_payment && !_toSupplier && _toAccount == null) return w.payPickToAccountError;
    if (s.narrationRequired && _narration.text.trim().isEmpty) return w.vouchersFormNarrationRequired;
    if (post && _needsReference && _reference.text.trim().isEmpty) {
      return _method == 'cheque' ? w.payChequeNoMissing : w.collectReferenceMissing;
    }
    if (post && _bankBlocked) return w.payUnsignedBlocks;
    if (_gross && _billsOverGross) return w.payBillsOverGross;
    return null;
  }

  /// ⭐ সার্ভারের ৪২২ ঘরে ঘরে: চেনা চাবি তার ঘরের নিচে, `bills.{i}.amount` আর `bills.{i}.id` সেই বিলের সারিতে (cb 1de6b0897,
  /// VoucherAllocations.php:59 `bills.{i}.id` against_unknown, :69 `bills.{i}.amount` bill_over_due), অচেনা চাবি (সিঙ্কের ঘরহীন `_`-ও) উপরে পুরোটা
  void _showFieldErrors(Map<String, String> errors, {String? fallback}) {
    final unknown = <String>[];
    final placed = <String, String>{};
    for (final e in errors.entries) {
      final bill = RegExp(r'^bills\.(\d+)\.(amount|id)$').firstMatch(e.key);
      if (bill != null) {
        final i = int.parse(bill.group(1)!);
        if (i < _sentBills.length) {
          _billError[_sentBills[i]] = e.value;
          continue;
        }
      } else if (_fieldOf(e.key) != null) {
        placed[_fieldOf(e.key)!] = e.value;
        continue;
      }
      unknown.add(e.value);
    }
    _fieldErrors = placed;
    _error = unknown.isNotEmpty ? unknown.join('\n') : (placed.isEmpty && _billError.isEmpty ? fallback : null);
  }

  /// সার্ভারের চাবি → পর্দার ঘর (চুক্তি ২, অংশ ৪; আর ভাউচারের চেনা ঘর)
  static String? _fieldOf(String key) => switch (key) {
        'gross_amount' || 'amount' => 'pay-amount',
        'ait_amount' => 'pay-ait',
        'vds_amount' => 'pay-vds',
        'charge_amount' => 'pay-charge',
        'instrument_no' => 'pay-reference',
        'bills' => 'pay-bills-total',
        _ => null,
      };

  String _payloadOf(VoucherDraft d) => jsonEncode({...d.toPayload()..remove('save_as_draft')});

  /// ⭐ "হিসাব দেখুন" — সার্ভারের নিট আর খাতা, কিছু না লিখে; দরজা না থাকলে চুপচাপ নেই হয়ে যায়
  Future<void> _askPreview() async {
    final Object api = widget.api;
    if (api is! VoucherPreviewer) return;
    final problem = _problem(post: false);
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    final draft = _draft(keep: false);
    setState(() {
      _busy = true;
      _error = null;
      _fieldErrors = const {};
      _billError.clear();
      _sentBills = [for (final b in draft.bills ?? const <Map<String, String>>[]) b['id']!];
    });
    try {
      final answer = await api.preview(draft);
      if (!mounted) return;
      setState(() {
        if (answer == null) {
          _previewAbsent = true;
        } else {
          _preview = answer;
          _previewFor = _payloadOf(draft);
        }
      });
    } catch (e) {
      if (!mounted) return;
      final fields = voucherFieldErrors(e);
      setState(() => fields.isEmpty
          ? _error = voucherRefusal(e) ?? errorMessageFor(e, fallback: context.l10n.vouchersFormSendFailed)
          : _showFieldErrors(fields, fallback: voucherRefusal(e)));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  /// ⭐ সই লাগবে — কেবল সার্ভারের প্রিভিউ বললে, আর এখনকার লেখারই হলে ("সইয়ের অপেক্ষায় হবে", বোতামে "সইয়ে পাঠান")
  bool get _willWait => _currentPreview?.needsSignature == true;

  /// সার্ভারের হিসাব, কেবল যদি এখনকার লেখারই হয়
  VoucherPreview? get _currentPreview => _preview != null && _previewFor == _payloadOf(_draft(keep: false)) ? _preview : null;

  Future<void> _send({required bool keep}) async {
    final problem = _problem(post: !keep);
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    final draft = _draft(keep: keep);
    setState(() {
      _busy = true;
      _error = null;
      _fieldErrors = const {};
      _billError.clear();
      _sentBills = [for (final b in draft.bills ?? const <Map<String, String>>[]) b['id']!];
    });
    final changeId = _changeId ??= widget.newChangeId();
    try {
      final outcome = await widget.api.send(draft, changeId);
      if (!mounted) return;
      if (outcome.landedId != null) {
        Navigator.of(context).pop(outcome.landedId);
        return;
      }
      setState(() {
        // ⓘ সিঙ্কের ফেরানো সারিতে সার্ভার `errors` দিলে ঘরে ঘরে; নইলে তার কথা উপরে, পুরোটা
        final fields = voucherFieldErrors(outcome.details);
        if (fields.isEmpty) {
          _error = outcome.refusal ?? context.l10n.vouchersFormNotSaved;
        } else {
          _showFieldErrors(fields, fallback: outcome.refusal);
        }
        _changeId = null;
      });
    } on NoNetworkForThis catch (e) {
      if (mounted) {
        setState(() => _error = e.mayHaveLanded ? context.l10n.vouchersFormMayHaveLanded : context.l10n.vouchersFormNeedsNet);
      }
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: context.l10n.vouchersFormSendFailed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickDate({required DateTime initial, required DateTime last, required ValueChanged<DateTime> set}) async {
    final picked =
        await showDatePicker(context: context, initialDate: initial, firstDate: _today.subtract(const Duration(days: 365)), lastDate: last);
    if (picked != null && mounted) setState(() => set(picked));
  }

  Future<T?> _pick<T>(String key, String title, List<T> options, String Function(T) label) => showModalBottomSheet<T>(
        context: context,
        isScrollControlled: true,
        builder: (_) => VoucherPickSheet<T>(title: title, options: options, label: label, keyPrefix: key),
      );

  // ── আঁকা ──

  bool get _dirty =>
      !_busy &&
      (_party != null ||
          _head != null ||
          _toAccount != null ||
          [_amount, _reference, _charge, _chequeBank, _payee, _billNo, _grossText, _ait, _vds, _narration].any((c) => c.text.trim().isNotEmpty));

  @override
  Widget build(BuildContext context) => UnsavedGuard(dirty: _dirty, child: _screen(context));

  Widget _screen(BuildContext context) {
    final w = context.l10n;
    final s = _setup;
    return Scaffold(
      appBar: AbosScreenBar(title: VoucherTypes.label(widget.type)),
      bottomNavigationBar: _bottomBar(context),
      // ⓘ সব ঘর একসাথে আঁকা (অলস তালিকা নয়) — কীবোর্ডের Tab আর TalkBack পাতার নিচের ঘরেও পৌঁছায়
      body: SingleChildScrollView(
        key: const ValueKey('pay-list'),
        padding: const EdgeInsets.fromLTRB(AppSpacing.md, AppSpacing.sm, AppSpacing.md, AppSpacing.xl),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          // ⭐ সংরক্ষণের আগে: সই লাগবে (cb-র will_wait, ভাঙা ধার) — কেবল সার্ভারের কথায়
          if (_willWait)
            const Padding(
              padding: EdgeInsets.only(bottom: AppSpacing.sm),
              child: Align(
                alignment: AlignmentDirectional.centerStart,
                child: AppStatusChip(key: ValueKey('pay-state-will-wait'), state: PaperState.willWait),
              ),
            ),
          if (_payment)
            MoneyFigures(
              keyPrefix: 'pay',
              due: _due,
              received: _amountValid ? _amountPaisa : 0,
              settled: 0,
              dueLabel: w.payFigureOwed,
              thisLabel: w.payFigureThis,
              leftLabel: w.collectFigureLeft,
              creditLabel: w.payFigureStays,
            ),
          if (_busy || (s == null && _error == null)) const Padding(padding: EdgeInsets.only(top: AppSpacing.sm), child: LinearProgressIndicator()),
          if (_error != null) MoneyErrorCard(_error!, textKey: const ValueKey('pay-error')),
          if (s?.cashHiddenReason != null) MoneyNote(key: const ValueKey('pay-cash-hidden'), text: s!.cashHiddenReason!, tone: MoneyTone.warn),
          if (s != null) ...(_payment ? _paymentForm(context, s) : _expenseForm(context, s)),
        ]),
      ),
    );
  }

  // ── পরিশোধ ──

  List<Widget> _paymentForm(BuildContext context, VoucherSetup s) {
    final w = context.l10n;
    final parties = s.parties.where((p) => p.type == _partyType).toList();
    final due = _due;
    return [
      MoneySection(title: w.collectGeneralTitle, children: [
        if (s.partyTypes.isNotEmpty) ...[
          MoneyLabel(w.payPartyType),
          Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
            for (final (type, label) in s.partyTypes)
              MoneySegment(
                key: ValueKey('pay-party-type-$type'),
                label: Text(label),
                selected: _partyType == type,
                onSelected: _busy
                    ? null
                    : (_) => setState(() {
                          if (_partyType != type) {
                            _party = null;
                            _partyBills = const [];
                            _ticked.clear();
                          }
                          _partyType = type;
                        }),
              ),
          ]),
          const SizedBox(height: AppSpacing.sm),
        ],
        MoneyTappable(
          key: const ValueKey('pay-party'),
          label: w.payParty,
          value: _party == null ? null : Text(_party!.shownName),
          placeholder: w.payPickParty,
          icon: Symbols.person_search,
          onTap: _busy
              ? null
              : () async {
                  final p = await _pick<VoucherParty>('pay-party', w.payParty, parties, (x) => x.shownName);
                  if (p != null) await _chooseParty(p);
                },
        ),
        // ⭐ পাওনা নামের পাশে (নকশার "নাম · পয়েন্ট · শাখা", পাশে পাওনা) — সার্ভার পাঠালে তবেই
        if (_party != null && due != null)
          Padding(
            padding: const EdgeInsets.only(top: AppSpacing.xs, left: AppSpacing.xs),
            child: Text(w.payDueBeside(Money.taka(due / 100)),
                key: const ValueKey('pay-party-due'), style: TextStyle(color: context.palette.danger, fontWeight: FontWeight.w600)),
          ),
        const SizedBox(height: AppSpacing.sm),
        _dateField(),
        const SizedBox(height: AppSpacing.sm),
        _amountField(_gross ? w.payGross : w.collectAmount),
        const SizedBox(height: AppSpacing.xs),
        Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
          MoneyTonalChip(
            key: const ValueKey('pay-full-due'),
            label: w.collectFullDue,
            onPressed: due == null || due <= 0 || _busy ? null : () => _amount.text = Money.amount(due / 100),
          ),
        ]),
        // ⭐ চুক্তি ২: উৎসে কাটা আয়কর আর ভ্যাট — মোটের ভিতর থেকে কাটে
        if (_gross) ...[
          const SizedBox(height: AppSpacing.sm),
          _withheldField('pay-ait', _ait, w.payAit),
          const SizedBox(height: AppSpacing.sm),
          _withheldField('pay-vds', _vds, w.payVds),
        ],
        const SizedBox(height: AppSpacing.sm),
        ..._moneyBox(context, s),
      ]),
      if (_gross && (_bills.isNotEmpty || _opening != null)) _billsSection(context),
      if (_gross) _summary(context),
      MoneySection(title: null, children: [
        // ⓘ সরবরাহকারী নয় এমন পক্ষ (কর্মী, ব্যক্তি …) — সার্ভার এখনো খাত চায়; সরবরাহকারীতে ঘরটাই নেই
        if (!_toSupplier) ...[
          _accountPicker(
            key: 'pay-to-account',
            label: w.payToAccount,
            value: _toAccount,
            options: s.to?.accounts ?? const [],
            set: (a) => _toAccount = a,
          ),
          const SizedBox(height: AppSpacing.sm),
        ],
        _narrationField(s),
      ]),
    ];
  }

  Widget _withheldField(String key, TextEditingController c, String label) => TextField(
        key: ValueKey(key),
        controller: c,
        enabled: !_busy,
        textAlign: TextAlign.end,
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        inputFormatters: [_money(key)],
        decoration: InputDecoration(
          labelText: label,
          helperText: _signNote(key),
          helperMaxLines: moneyNoteLines,
          errorText: _fieldErrors[key],
          errorMaxLines: moneyNoteLines,
        ),
      );

  /// ⭐ খোলা বিল — পুরনো আগে, টিক আর অঙ্ক (প্রতিটা ≤ তার বকেয়া), নিচে মোট বসানো আর বাকিটা অগ্রিম (চুক্তি ২, অংশ ৩)
  Widget _billsSection(BuildContext context) {
    final w = context.l10n;
    final bills = [..._bills]..sort((a, b) => a.date.compareTo(b.date));
    final sum = _billsSum;
    final left = _amountPaisa - sum;
    final opening = _opening;
    final dueAll = bills.fold(opening?.outstanding ?? 0, (s, b) => s + b.outstanding);
    return MoneySection(key: const ValueKey('pay-bills'), title: w.payBillsTitle, children: [
      // ⭐ আগের জের (ওপেনিং) কমলা, টিক দিয়ে টাকা লেখা যায়; অগ্রিম সবুজ, বিয়োগে, টিক নেই — সবার উপরে (মালিকের নমুনা v2, ২৬ নভেম্বর ২০২৬)
      if (opening != null)
        Container(
          key: const ValueKey('pay-bill-opening'),
          color: opening.advance ? context.palette.successSurface : context.palette.warningSurface,
          padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              if (opening.tickable)
                Checkbox(
                  key: const ValueKey('pay-bill-opening-tick'),
                  value: _ticked.contains(opening.id),
                  onChanged: _busy ? null : (on) => _tickBill(opening, on ?? false),
                )
              else
                const SizedBox(width: 48),
              Expanded(
                child: Wrap(spacing: AppSpacing.sm, alignment: WrapAlignment.spaceBetween, crossAxisAlignment: WrapCrossAlignment.center, children: [
                  Text(opening.no, style: const TextStyle(fontWeight: FontWeight.w600)),
                  Text(opening.advance ? w.payAdvanceTag : w.payOpeningTag,
                      key: const ValueKey('pay-bill-opening-tag'),
                      style: TextStyle(color: opening.advance ? context.palette.success : context.palette.warning, fontSize: 13)),
                  if (opening.info.isNotEmpty)
                    Tooltip(
                      key: const ValueKey('pay-bill-opening-info'),
                      message: opening.info,
                      triggerMode: TooltipTriggerMode.tap,
                      child: const Icon(Icons.info_outline, size: 18),
                    ),
                  Text(w.payBillDue(Money.taka(opening.outstanding / 100)),
                      key: const ValueKey('pay-bill-opening-due'),
                      style: opening.advance ? TextStyle(color: context.palette.success, fontWeight: FontWeight.w700) : null),
                ]),
              ),
            ]),
            if (opening.tickable && _ticked.contains(opening.id))
              Padding(
                padding: const EdgeInsetsDirectional.only(start: 48),
                child: TextField(
                  key: const ValueKey('pay-bill-opening-amount'),
                  controller: _billText.putIfAbsent(opening.id, TextEditingController.new),
                  enabled: !_busy,
                  textAlign: TextAlign.end,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: [_money('bill:${opening.id}')],
                  onChanged: (t) => _typeBill(opening, t),
                  decoration: InputDecoration(
                    labelText: w.payBillApply,
                    helperText: w.payOpeningKept,
                    helperMaxLines: moneyNoteLines,
                    errorText: _billError[opening.id],
                    errorMaxLines: moneyNoteLines,
                  ),
                ),
              ),
          ]),
        ),
      for (final (i, b) in bills.indexed) ...[
        if (i > 0) const Divider(height: 1),
        Padding(
          key: ValueKey('pay-bill-${b.no}'),
          padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Row(children: [
              Checkbox(
                key: ValueKey('pay-bill-tick-${b.no}'),
                value: _ticked.contains(b.id),
                onChanged: _busy ? null : (on) => _tickBill(b, on ?? false),
              ),
              Expanded(
                child: Wrap(spacing: AppSpacing.sm, alignment: WrapAlignment.spaceBetween, children: [
                  Text(b.no, style: const TextStyle(fontWeight: FontWeight.w600)),
                  // ⓘ দেরি — আদায়ের পর্দার একই লেখা আর রং
                  if ((b.daysLate ?? 0) > 0)
                    Text(w.collectItemLate(b.daysLate!),
                        key: ValueKey('pay-bill-late-${b.no}'), style: TextStyle(color: context.palette.danger, fontSize: 13)),
                  Text(w.payBillDue(Money.taka(b.outstanding / 100)), key: ValueKey('pay-bill-due-${b.no}')),
                ]),
              ),
            ]),
            if (_ticked.contains(b.id))
              Padding(
                padding: const EdgeInsetsDirectional.only(start: 48),
                child: TextField(
                  key: ValueKey('pay-bill-amount-${b.no}'),
                  controller: _billText.putIfAbsent(b.id, TextEditingController.new),
                  enabled: !_busy,
                  textAlign: TextAlign.end,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  inputFormatters: [_money('bill:${b.id}')],
                  onChanged: (t) => _typeBill(b, t),
                  decoration: InputDecoration(
                    labelText: w.payBillApply,
                    helperText: _signNote('bill:${b.id}'),
                    helperMaxLines: moneyNoteLines,
                    errorText: _billError[b.id],
                    errorMaxLines: moneyNoteLines,
                  ),
                ),
              ),
          ]),
        ),
      ],
      const Divider(height: AppSpacing.md),
      // ⭐ মোট বাকি — আগের জের থাকলে খাতার জেরের সমান (নমুনার নিচের সারি)
      MoneyAmountRow(key: const ValueKey('pay-bills-due-total'), label: opening != null ? w.payBillsDueLedger : w.payBillsDueTotal, paisa: dueAll),
      MoneyAmountRow(key: const ValueKey('pay-bills-total'), label: w.payBillsApplied, paisa: sum, strong: true),
      if (_fieldErrors['pay-bills-total'] case final e?) MoneyNote(key: const ValueKey('pay-bills-error'), text: e, tone: MoneyTone.stop),
      if (_billsOverGross)
        MoneyNote(key: const ValueKey('pay-bills-over'), text: w.payBillsOverGross, tone: MoneyTone.stop)
      else if (sum > 0 && left > 0)
        // ⭐ বিলের বাইরে যা থাকে তা পক্ষের অগ্রিম — নিজের লাইনে (চুক্তির "অগ্রিম ৳…")
        // ⛔ "অগ্রিম" নয় — সরবরাহকারীর আলাদা অগ্রিম খাত নেই, বাড়তিটা তাঁর নিজের খাতায় (২১১১) ডেবিট হয়ে থাকে (চুক্তির §৭.৬)
        MoneyNote(
            key: const ValueKey('pay-bills-unapplied'),
            text: _toSupplier ? w.payBillsUnappliedSupplier(Money.taka(left / 100)) : w.payBillsUnappliedParty(Money.taka(left / 100)),
            tone: MoneyTone.info),
    ]);
  }

  /// ⭐ হিসাব — লেখা মোট, AIT, VDS আর চার্জ যেমন লেখা; নিট আর খাতা কেবল সার্ভারের উত্তর থেকে (চুক্তি ২, অংশ ৫: ফোন নিট গোনে না)
  Widget _summary(BuildContext context) {
    final w = context.l10n;
    final preview = _currentPreview;
    final canAsk = widget.api is VoucherPreviewer && !_previewAbsent;
    return MoneySection(key: const ValueKey('pay-summary'), title: w.paySummaryTitle, children: [
      MoneyAmountRow(key: const ValueKey('pay-sum-gross'), label: w.payGrossShort, paisa: _amountValid ? _amountPaisa : 0, strong: true),
      if (_paisa(_ait.text) > 0) MoneyAmountRow(key: const ValueKey('pay-sum-ait'), label: w.payAit, paisa: _paisa(_ait.text)),
      if (_paisa(_vds.text) > 0) MoneyAmountRow(key: const ValueKey('pay-sum-vds'), label: w.payVds, paisa: _paisa(_vds.text)),
      if (_showsCharge && _paisa(_charge.text) > 0)
        MoneyAmountRow(key: const ValueKey('pay-sum-charge'), label: w.payChargeOutside, paisa: _paisa(_charge.text)),
      const Divider(height: AppSpacing.md),
      if (preview != null) ...[
        MoneyAmountRow(key: const ValueKey('pay-sum-net'), label: w.payNetFromServer, paisa: (preview.netAmount * 100).round(), strong: true),
        for (final (i, l) in preview.ledger.indexed)
          Padding(
            key: ValueKey('pay-ledger-$i'),
            padding: const EdgeInsets.symmetric(vertical: 2),
            child: Wrap(alignment: WrapAlignment.spaceBetween, spacing: AppSpacing.sm, children: [
              Text(l.account, style: TextStyle(color: context.palette.onSurfaceMuted)),
              Text(l.debit > 0 ? w.payLedgerDr(Money.taka(l.debit)) : w.payLedgerCr(Money.taka(l.credit))),
            ]),
          ),
      ] else
        Text(canAsk ? w.payNetAsk : w.payNetLater,
            key: const ValueKey('pay-net-from-server'), style: TextStyle(color: context.palette.onSurfaceMuted)),
      if (canAsk)
        Align(
          alignment: AlignmentDirectional.centerStart,
          child: TextButton.icon(
            key: const ValueKey('pay-preview'),
            style: TextButton.styleFrom(minimumSize: const Size(48, 48)),
            onPressed: _busy ? null : _askPreview,
            icon: const Icon(Symbols.receipt_long),
            label: Text(w.payPreview),
          ),
        ),
    ]);
  }

  // ── খরচ ──

  List<Widget> _expenseForm(BuildContext context, VoucherSetup s) {
    final w = context.l10n;
    return [
      MoneySection(title: w.collectGeneralTitle, children: [
        _dateField(),
        const SizedBox(height: AppSpacing.sm),
        _accountPicker(key: 'pay-head', label: w.payExpenseHead, value: _head, options: s.to?.accounts ?? const [], set: (a) => _head = a),
        const SizedBox(height: AppSpacing.sm),
        TextField(
          key: const ValueKey('pay-payee'),
          controller: _payee,
          enabled: !_busy,
          maxLength: 120,
          decoration: InputDecoration(labelText: w.payPayee, counterText: ''),
        ),
        const SizedBox(height: AppSpacing.sm),
        TextField(
          key: const ValueKey('pay-bill-no'),
          controller: _billNo,
          enabled: !_busy,
          maxLength: 60,
          decoration: InputDecoration(labelText: w.payBillNo, counterText: ''),
        ),
      ]),
      _deduction(context),
      MoneySection(title: w.payHowPaid, children: _moneyBox(context, s)),
      MoneySection(title: null, children: [
        _amountField(w.collectAmount),
        const SizedBox(height: AppSpacing.sm),
        _narrationField(s),
      ]),
    ];
  }

  /// ⭐ উৎসে কর্তন — গুটানো (ওয়েবের `<details>`), বিলের মোট · AIT · VDS, নিচে হাতে যাবে
  Widget _deduction(BuildContext context) {
    final w = context.l10n;
    final net = _paisa(_grossText.text) - _paisa(_ait.text) - _paisa(_vds.text);
    return Card(
      margin: const EdgeInsets.only(top: AppSpacing.sm),
      child: ExpansionTile(
        key: const ValueKey('pay-deduction'),
        shape: const Border(),
        collapsedShape: const Border(),
        tilePadding: const EdgeInsets.symmetric(horizontal: AppSpacing.md),
        childrenPadding: const EdgeInsets.fromLTRB(AppSpacing.md, 0, AppSpacing.md, AppSpacing.md),
        title: Text(w.payDeductionTitle, style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.w700)),
        expandedCrossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          for (final (key, c, label) in [('pay-gross', _grossText, w.payBillGross), ('pay-ait', _ait, w.payAit), ('pay-vds', _vds, w.payVds)]) ...[
            TextField(
              key: ValueKey(key),
              controller: c,
              enabled: !_busy,
              textAlign: TextAlign.end,
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              inputFormatters: [_money(key)],
              decoration: InputDecoration(labelText: label, helperText: _signNote(key), helperMaxLines: moneyNoteLines),
            ),
            const SizedBox(height: AppSpacing.sm),
          ],
          if (_paisa(_grossText.text) > 0)
            MoneyAmountRow(key: const ValueKey('pay-net'), label: w.payNetPayable, paisa: net < 0 ? 0 : net, strong: true),
        ],
      ),
    );
  }

  // ── টাকার বাক্স: পদ্ধতি → খাত → (প্রাপকের ব্যাংক) → পদ্ধতির ঘর → চার্জের লাইন ──

  List<Widget> _moneyBox(BuildContext context, VoucherSetup s) {
    final w = context.l10n;
    final listed = [..._accountsFor(_method), ..._creditAccounts];
    String accountWords(VoucherAccount a) => (a.kind ?? '').isEmpty ? w.payOnCredit(a.label) : a.label;
    final noAccount = _accountsFor(_method).isEmpty;
    return [
      MoneyLabel(w.collectMethod),
      Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
        for (final m in MoneyVoucherScreen.methods)
          MoneySegment(
            key: ValueKey('pay-method-$m'),
            label: Text(moneyMethodLabel(context, m)),
            selected: _movesMoney && _method == m,
            onSelected: _busy || !_hasAccounts(m) ? null : (_) => _chooseMethod(m),
          ),
      ]),
      const SizedBox(height: AppSpacing.sm),
      if (noAccount && listed.isEmpty)
        MoneyNote(
            key: const ValueKey('pay-account-none'),
            text: w.payAccountNone(moneyMethodLabel(context, _method)),
            tone: MoneyTone.warn)
      else
        // ⓘ বাছাইয়ের শিট, ড্রপডাউন নয় — খাতের লম্বা নাম ২০০% লেখায় "…"-এ কাটা পড়ত; এখানে নাম পুরোটা, দরকারে দুই লাইনে
        MoneyTappable(
          key: const ValueKey('pay-account'),
          label: w.payFromAccount,
          value: _account == null ? null : Text(accountWords(_account!), key: const ValueKey('pay-account-chosen')),
          placeholder: w.payFromAccount,
          icon: Symbols.account_balance_wallet,
          onTap: _busy
              ? null
              : () async {
                  final a = await _pick<VoucherAccount>('pay-account', w.payFromAccount, listed, accountWords);
                  if (a != null) _chooseAccount(a);
                },
        ),
      if (_onCredit) ...[
        MoneyNote(key: const ValueKey('pay-credit-note'), text: w.payOnCreditNote, tone: MoneyTone.warn),
        const SizedBox(height: AppSpacing.sm),
        MoneyTappable(
          key: const ValueKey('pay-credit-party'),
          label: w.payCreditParty,
          value: _party == null ? null : Text(_party!.label),
          placeholder: w.payPickParty,
          icon: Symbols.person_search,
          onTap: _busy
              ? null
              : () async {
                  final p = await _pick<VoucherParty>('pay-credit-party', w.payCreditParty, s.parties, (x) => x.label);
                  if (p != null) setState(() => _party = p);
                },
        ),
      ],
      if (_toSupplier && _party?.bank != null) _payeeBank(context, _party!.bank!),
      ..._methodFields(context, s),
    ];
  }

  /// ⭐ প্রাপকের ব্যাংক — সরবরাহকারীর রেকর্ড থেকে, কেবল পড়ার (ঘর নয়, বদলানো যায় না); সই না হলে ব্যাংকে পরিশোধ আটকায়
  Widget _payeeBank(BuildContext context, PayeeBank bank) {
    final w = context.l10n;
    final p = context.palette;
    return Padding(
      padding: const EdgeInsets.only(top: AppSpacing.sm),
      child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
        MoneyLabel(w.payPayeeBank),
        Container(
          key: const ValueKey('pay-payee-bank'),
          padding: const EdgeInsets.all(AppSpacing.sm),
          decoration: BoxDecoration(color: p.surfaceMuted, borderRadius: BorderRadius.circular(6), border: Border.all(color: p.border)),
          child: bank.signed
              ? Wrap(spacing: AppSpacing.xs, children: [
                  if (bank.line.isNotEmpty) Text(bank.line),
                  // ⓘ টিক আইকনে, লেখার '✓' নয় — ফোনের ফন্টে চিহ্নটা না থাকলে ফাঁকা বাক্স (আদায়ের সবুজ পট্টির একই নিয়ম)
                  Row(key: const ValueKey('pay-payee-bank-verified'), mainAxisSize: MainAxisSize.min, children: [
                    ExcludeSemantics(child: Icon(Symbols.check_circle, size: 16, color: p.success)),
                    const SizedBox(width: 2),
                    Flexible(child: Text(w.payPayeeBankVerified, style: TextStyle(color: p.success, fontWeight: FontWeight.w600))),
                  ]),
                ])
              : Text(bank.state == 'pending' ? '${w.payPayeeBankNone} · ${w.payPayeeBankPending}' : w.payPayeeBankNone,
                  style: TextStyle(color: p.onSurfaceMuted)),
        ),
        if (!bank.signed && _isTransfer) MoneyNote(key: const ValueKey('pay-payee-bank-blocks'), text: w.payUnsignedBlocks, tone: MoneyTone.stop),
      ]),
    );
  }

  List<Widget> _methodFields(BuildContext context, VoucherSetup s) {
    final w = context.l10n;
    if (!_movesMoney || _method == 'cash') return const [];
    final modes = s.transferModes;
    final charge = _paisa(_charge.text);
    return [
      if (_isTransfer && modes != null && modes.isNotEmpty) ...[
        const SizedBox(height: AppSpacing.sm),
        DropdownButtonFormField<int>(
          key: const ValueKey('pay-transfer-mode'),
          initialValue: modes.any((m) => m.id == _transferModeId) ? _transferModeId : null,
          isExpanded: true,
          decoration: InputDecoration(labelText: w.collectTransferMode),
          items: [for (final m in modes) DropdownMenuItem(value: m.id, child: Text(m.name))],
          onChanged: _busy ? null : (m) => setState(() => _transferModeId = m),
        ),
      ],
      const SizedBox(height: AppSpacing.sm),
      TextField(
        key: const ValueKey('pay-reference'),
        controller: _reference,
        enabled: !_busy,
        maxLength: 64,
        decoration: InputDecoration(
          labelText: switch (_method) { 'mfs' => w.payTrxId, 'cheque' => w.payChequeNo, _ => w.collectReference },
          counterText: '',
          errorText: _fieldErrors['pay-reference'],
          errorMaxLines: moneyNoteLines,
        ),
      ),
      if (_isTransfer) ...[
        const SizedBox(height: AppSpacing.sm),
        MoneyTappable(
          key: const ValueKey('pay-value-date'),
          label: w.collectValueDate,
          value: Text(moneyDayText(_valueDate ?? _today)),
          icon: Symbols.event,
          onTap: _busy
              ? null
              : () => _pickDate(initial: _valueDate ?? _today, last: _today.add(const Duration(days: 365)), set: (d) => _valueDate = d),
        ),
      ],
      if (_isCheque) ...[
        const SizedBox(height: AppSpacing.sm),
        MoneyTappable(
          key: const ValueKey('pay-cheque-date'),
          label: w.collectChequeDate,
          value: _chequeDate == null ? null : Text(moneyDayText(_chequeDate!)),
          icon: Symbols.event,
          onTap: _busy
              ? null
              : () => _pickDate(initial: _chequeDate ?? _date, last: _today.add(const Duration(days: 365)), set: (d) => _chequeDate = d),
        ),
        const SizedBox(height: AppSpacing.sm),
        TextField(
          key: const ValueKey('pay-cheque-bank'),
          controller: _chequeBank,
          enabled: !_busy,
          maxLength: 120,
          decoration: InputDecoration(labelText: w.payChequeBank, counterText: ''),
        ),
      ],
      if (_showsCharge) ...[
        const SizedBox(height: AppSpacing.sm),
        TextField(
          key: const ValueKey('pay-charge'),
          controller: _charge,
          enabled: !_busy,
          textAlign: TextAlign.end,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          inputFormatters: [_money('charge')],
          decoration: InputDecoration(
            labelText: _method == 'mfs' ? w.payMfsCharge : w.collectBankCharge,
            helperText: _signNote('charge'),
            helperMaxLines: moneyNoteLines,
            errorText: _fieldErrors['pay-charge'],
            errorMaxLines: moneyNoteLines,
          ),
        ),
        // ⭐ চার্জ কোথায় যাবে — এক লাইনে (নকশা #৯); চার্জ আমাদের খরচ, "কে দিল" প্রশ্ন নেই
        if (charge > 0) MoneyNote(key: const ValueKey('pay-charge-line'), text: w.payChargeOurs(Money.taka(charge / 100)), tone: MoneyTone.info),
      ],
    ];
  }

  Widget _dateField() => MoneyTappable(
        key: const ValueKey('pay-date'),
        label: context.l10n.collectDate,
        value: Text(moneyDayText(_date)),
        icon: Symbols.calendar_today,
        onTap: _busy ? null : () => _pickDate(initial: _date, last: _today, set: (d) => _date = d),
      );

  Widget _amountField(String label) => TextField(
        key: const ValueKey('pay-amount'),
        controller: _amount,
        focusNode: _amountFocus,
        enabled: !_busy,
        textAlign: TextAlign.end,
        style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w600),
        keyboardType: const TextInputType.numberWithOptions(decimal: true),
        inputFormatters: [_money('amount')],
        onEditingComplete: () {
          _formatAmount();
          _amountFocus.unfocus();
        },
        decoration: InputDecoration(
          labelText: label,
          prefixText: '৳ ', // l10n-ok: the taka sign, the same in every language
          helperText: _signNote('amount'),
          helperMaxLines: moneyNoteLines,
          errorText: _fieldErrors['pay-amount'],
          errorMaxLines: moneyNoteLines,
        ),
      );

  Widget _narrationField(VoucherSetup s) => TextField(
        key: const ValueKey('pay-narration'),
        controller: _narration,
        enabled: !_busy,
        maxLength: 500,
        decoration: InputDecoration(
          labelText: s.narrationRequired ? context.l10n.vouchersFormNarrationMust : context.l10n.vouchersNarration,
          counterText: '',
        ),
      );

  Widget _accountPicker({
    required String key,
    required String label,
    required VoucherAccount? value,
    required List<VoucherAccount> options,
    required void Function(VoucherAccount) set,
  }) =>
      MoneyTappable(
        key: ValueKey(key),
        label: label,
        value: value == null ? null : Text(value.label),
        placeholder: label,
        icon: Symbols.search,
        onTap: _busy
            ? null
            : () async {
                final a = await _pick<VoucherAccount>(key, label, options, (x) => x.label);
                if (a != null) setState(() => set(a));
              },
      );

  /// ⭐ নিচের স্থির সারি — বাতিল · খসড়া রাখুন · পোস্ট (নকশার "SAP Fiori footer toolbar"); পোস্টই একমাত্র মূল বোতাম
  Widget _bottomBar(BuildContext context) {
    final w = context.l10n;
    final ready = !_busy && _setup != null;
    return MoneyBottomBar(children: [
      Expanded(
        child: TextButton(
          key: const ValueKey('pay-cancel'),
          style: TextButton.styleFrom(minimumSize: const Size(48, 48), padding: moneyButtonPadding, textStyle: moneyButtonText(context)),
          onPressed: _busy ? null : () => Navigator.of(context).maybePop(),
          child: Text(w.payCancel, textAlign: TextAlign.center),
        ),
      ),
      const SizedBox(width: AppSpacing.sm),
      Expanded(
        child: OutlinedButton(
          key: const ValueKey('pay-draft'),
          style: OutlinedButton.styleFrom(minimumSize: const Size(48, 48), padding: moneyButtonPadding, textStyle: moneyButtonText(context)),
          onPressed: ready ? () => _send(keep: true) : null,
          child: Text(w.payKeepDraft, textAlign: TextAlign.center),
        ),
      ),
      const SizedBox(width: AppSpacing.sm),
      Expanded(
        child: FilledButton(
          key: const ValueKey('pay-post'),
          style: FilledButton.styleFrom(minimumSize: const Size(48, 48), padding: moneyButtonPadding, textStyle: moneyButtonText(context)),
          onPressed: ready && !_bankBlocked ? () => _send(keep: false) : null,
          child: Text(_willWait ? w.collectSendForSign : w.payPost, textAlign: TextAlign.center),
        ),
      ),
    ]);
  }
}
