import 'dart:async';

/// ⭐ তালিকার খোঁজার একটাই নিয়ম — ওয়েবের মতো (মালিক, ২৩ নভেম্বর ২০২৬)।
///
/// - টাইপ করলে খোঁজা চলে কেবল যখন লেখা (ফাঁক বাদে) অন্তত [minChars] অক্ষর **আর** শেষ চাপের পর [delay] কেটে গেছে;
///   আবার টাইপ করলে ঘড়ি নতুন করে শুরু।
/// - Enter চাপলে সাথে সাথে, অক্ষর কম হলেও।
/// - বাক্স খালি করলে সাথে সাথে — পুরো তালিকা ফেরে।
/// - ১-২ অক্ষরে কিছুই চলে না; আগের অপেক্ষমাণ খোঁজাও বাতিল।
///
/// ⓘ একই লেখা দুবার পাঠানো হয় না (টাইপ করে মুছে আবার একই লেখা) — Enter ছাড়া, যেটা ইচ্ছে করে আবার খোঁজা।
/// প্রতিটা পর্দা একটা রাখে, `TextField`-এর `onChanged`/`onSubmitted`-এ বসায়, আর `dispose()`-এ ছাড়ে।
class SearchDebouncer {
  SearchDebouncer({
    required this.onSearch,
    this.delay = defaultDelay,
    this.minChars = defaultMinChars,
  });

  /// সার্ভারের `PartySearch::MIN_TYPED`-এর সমান।
  static const int defaultMinChars = 3;

  static const Duration defaultDelay = Duration(milliseconds: 600);

  /// ছাঁটা লেখা নিয়ে ডাকে — খালি মানে "সব দেখাও"।
  final void Function(String query) onSearch;
  final Duration delay;
  final int minChars;

  Timer? _timer;
  String _last = '';

  /// এখন যে লেখায় তালিকা দাঁড়িয়ে আছে।
  String get current => _last;

  /// একটা খোঁজা অপেক্ষায় আছে কি না।
  bool get pending => _timer?.isActive ?? false;

  /// আগে থেকে বসানো লেখা (যেমন খোলার সময় আনা) — খোঁজা চালায় না।
  void seed(String query) => _last = query.trim();

  void onChanged(String text) {
    _timer?.cancel();
    final query = text.trim();
    if (query.isEmpty) {
      _fire(query);
      return;
    }
    // সার্ভারের `mb_strlen`-এর মতো কোড-পয়েন্ট গোনা
    if (query.runes.length < minChars) return;
    _timer = Timer(delay, () => _fire(query));
  }

  void onSubmitted(String text) {
    _timer?.cancel();
    _last = text.trim();
    onSearch(_last);
  }

  void cancel() => _timer?.cancel();

  void dispose() => _timer?.cancel();

  void _fire(String query) {
    if (query == _last) return;
    _last = query;
    onSearch(query);
  }
}
