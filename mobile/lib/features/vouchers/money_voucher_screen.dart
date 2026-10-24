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
  final _gross = TextEditingController();
  final _ait = TextEditingController();
  final _vds = TextEditingController();
  final _narration = TextEditingController();
  final _amountFocus = FocusNode(debugLabel: 'amount');

  /// ⭐ যে টাকার ঘরে পেস্টের চিহ্ন এইমাত্র ঝরেছে — আদায়ের পর্দার একই নিয়ম
  final Set<String> _signDropped = {};

  bool get _payment => widget.type == VoucherTypes.payment;

  DateTime get _today {
    final now = widget.today ?? _setup?.today ?? DateTime.now();
    return DateTime(now.year, now.month, now.day);
  }

  @override
  void initState() {
    super.initState();
    _date = _today;
    for (final c in [_amount, _reference, _charge, _chequeBank, _payee, _billNo, _gross, _ait, _vds, _narration]) {
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
    for (final c in [_amount, _reference, _charge, _chequeBank, _payee, _billNo, _gross, _ait, _vds, _narration]) {
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

  VoucherDraft _draft({required bool keep}) => VoucherDraft(
        type: widget.type,
        date: _date,
        narration: _narration.text,
        fromAccountId: _account?.id,
        // ⓘ পরিশোধে সরবরাহকারী হলে ফাঁকা — সার্ভার দেনার খাত (২১১১) নিজে বসায় ([[VoucherRequest::fillAccountFromParty()]])
        toAccountId: _payment ? (_toSupplier ? null : _toAccount?.id) : _head?.id,
        expenseAccountId: _payment ? null : _head?.id,
        amount: Money.typedText(_amount.text),
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
        grossAmount: !_payment && _paisa(_gross.text) > 0 ? paisaText(_paisa(_gross.text)) : null,
        aitAmount: !_payment && _paisa(_ait.text) > 0 ? paisaText(_paisa(_ait.text)) : null,
        vdsAmount: !_payment && _paisa(_vds.text) > 0 ? paisaText(_paisa(_vds.text)) : null,
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
    return null;
  }

  Future<void> _send({required bool keep}) async {
    final problem = _problem(post: !keep);
    if (problem != null) {
      setState(() => _error = problem);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    final changeId = _changeId ??= widget.newChangeId();
    try {
      final outcome = await widget.api.send(_draft(keep: keep), changeId);
      if (!mounted) return;
      if (outcome.landedId != null) {
        Navigator.of(context).pop(outcome.landedId);
        return;
      }
      setState(() {
        _error = outcome.refusal ?? context.l10n.vouchersFormNotSaved;
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
          [_amount, _reference, _charge, _chequeBank, _payee, _billNo, _gross, _ait, _vds, _narration].any((c) => c.text.trim().isNotEmpty));

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
          if (_payment)
            MoneyFigures(
              keyPrefix: 'pay',
              due: _due,
              received: _amountValid ? _amountPaisa : 0,
              settled: 0,
              dueLabel: w.payFigureOwed,
              thisLabel: w.payFigureThis,
              leftLabel: w.collectFigureLeft,
              creditLabel: w.payFigureAdvance,
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
                          if (_partyType != type) _party = null;
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
                  if (p != null) setState(() => _party = p);
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
        _amountField(w.collectAmount),
        const SizedBox(height: AppSpacing.xs),
        Wrap(spacing: AppSpacing.xs, runSpacing: AppSpacing.xs, children: [
          MoneyTonalChip(
            key: const ValueKey('pay-full-due'),
            label: w.collectFullDue,
            onPressed: due == null || due <= 0 || _busy ? null : () => _amount.text = Money.amount(due / 100),
          ),
        ]),
        const SizedBox(height: AppSpacing.sm),
        ..._moneyBox(context, s),
      ]),
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
    final net = _paisa(_gross.text) - _paisa(_ait.text) - _paisa(_vds.text);
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
          for (final (key, c, label) in [('pay-gross', _gross, w.payBillGross), ('pay-ait', _ait, w.payAit), ('pay-vds', _vds, w.payVds)]) ...[
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
          if (_paisa(_gross.text) > 0)
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
          child: Text(w.payPost, textAlign: TextAlign.center),
        ),
      ),
    ]);
  }
}
