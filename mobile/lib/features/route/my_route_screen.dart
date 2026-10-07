import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:intl/intl.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/orders/my_route_api.dart';
import '../../core/records/money.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/empty_state.dart';

/// ⭐ আজকের রুট — ছকে আজ যে রুট, তার দোকান এক লাইনে এক তথ্য (মালিকের নিয়ম); দোকানে চাপলে দোকানের পাতা,
/// সেখান থেকে অর্ডার, জমা, খতিয়ান। অন্য দিন দেখতে তারিখ বদলান (সমন্বয়কের ক্রম "ঘ", ৫ অক্টোবর ২০২৬)।
class MyRouteScreen extends StatefulWidget {
  const MyRouteScreen({super.key, this.load, this.today, this.openShop});

  /// পরীক্ষায় বসানো — আসলটা [MyRouteApi.day]।
  final Future<RouteDay> Function(String date, int page)? load;
  final DateTime Function()? today;

  /// দোকানের পাতা — আসলটা `/home/customers/{id}`।
  final void Function(BuildContext context, RouteShop shop)? openShop;

  @override
  State<MyRouteScreen> createState() => _MyRouteScreenState();
}

class _MyRouteScreenState extends State<MyRouteScreen> {
  static final DateFormat _wire = DateFormat('yyyy-MM-dd');
  static final DateFormat _shown = DateFormat('dd/MM/yyyy');
  static const _weekdays = ['সোমবার', 'মঙ্গলবার', 'বুধবার', 'বৃহস্পতিবার', 'শুক্রবার', 'শনিবার', 'রবিবার'];

  late DateTime _day;
  Map<String, String> _routes = const {};
  final List<RouteShop> _shops = [];
  int? _next;
  bool _busy = false;
  bool _loaded = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final now = (widget.today ?? DateTime.now)();
    _day = DateTime(now.year, now.month, now.day);
    _load();
  }

  Future<void> _load({bool more = false}) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final page = more ? (_next ?? 1) : 1;
      final day = await (widget.load ?? (d, p) => MyRouteApi.day(date: d, page: p))(_wire.format(_day), page);
      if (!mounted) return;
      setState(() {
        _routes = day.routes;
        if (!more) _shops.clear();
        _shops.addAll(day.shops);
        _next = day.nextPage;
        _loaded = true;
      });
    } catch (e) {
      if (mounted) {
        setState(() => _error = errorMessageFor(e,
            fallback: 'রুট আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
            whenAbsent: 'সার্ভারে রুটের দরজা এখনো আসেনি — অফিসে জানান।'));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _pickDay() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _day,
      firstDate: _day.subtract(const Duration(days: 60)),
      lastDate: _day.add(const Duration(days: 60)),
      helpText: 'কোন দিনের রুট',
    );
    if (picked == null || !mounted) return;
    setState(() => _day = picked);
    _load();
  }

  void _open(RouteShop shop) =>
      (widget.openShop ?? (c, s) => GoRouter.of(c).push('/home/customers/${s.id}'))(context, shop);

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('আজকের রুট')),
      body: RefreshIndicator(
        onRefresh: _load,
        child: ListView(
          padding: const EdgeInsets.all(AppSpacing.md),
          children: [
            OutlinedButton.icon(
              key: const Key('route-day'),
              onPressed: _busy ? null : _pickDay,
              icon: const Icon(Icons.event_outlined),
              label: Text('${_weekdays[_day.weekday - 1]}, ${_shown.format(_day)}'),
            ),
            const SizedBox(height: AppSpacing.sm),
            if (_busy) const LinearProgressIndicator(),
            if (_error != null)
              EmptyState(icon: Icons.cloud_off_outlined, title: 'আনা গেল না', message: _error),
            if (_loaded && _routes.isEmpty && _error == null)
              const EmptyState(
                icon: Icons.route_outlined,
                title: 'এই দিনে কোনো রুট নেই',
                message: 'সাপ্তাহিক ছকে এই দিনে আপনার নামে রুট বসানো নেই — অফিসে জানান।',
              ),
            for (final entry in _routes.entries) ...[
              Padding(
                padding: const EdgeInsets.only(top: AppSpacing.sm, bottom: AppSpacing.xs),
                child: Text(entry.value, style: Theme.of(context).textTheme.titleMedium),
              ),
              for (final shop in _shops.where((s) => s.route == entry.key))
                Card(
                  child: ListTile(
                    key: Key('route-shop-${shop.id}'),
                    onTap: () => _open(shop),
                    title: Text(shop.name),
                    subtitle: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(shop.code),
                        if (shop.phone != null && shop.phone!.isNotEmpty) Text(shop.phone!),
                        if (shop.address != null && shop.address!.isNotEmpty) Text(shop.address!),
                        Text('এ মাসে বিক্রি: ${Money.taka(shop.sales)}'),
                        Text('এ মাসে আদায়: ${Money.taka(shop.collections)}'),
                        Text('বকেয়া: ${Money.taka(shop.outstanding)}',
                            style: TextStyle(
                              color: shop.outstanding > 0 ? AppColors.danger : AppColors.onSurfaceMuted,
                              fontWeight: FontWeight.w600,
                            )),
                      ],
                    ),
                    trailing: const Icon(Icons.chevron_right),
                  ),
                ),
            ],
            if (_next != null)
              OutlinedButton(
                key: const Key('route-more'),
                onPressed: _busy ? null : () => _load(more: true),
                child: const Text('আরও দোকান'),
              ),
          ],
        ),
      ),
    );
  }
}
