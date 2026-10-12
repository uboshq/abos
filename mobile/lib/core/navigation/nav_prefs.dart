import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hive/hive.dart';

import '../storage/hive_encryption.dart';

/// ⭐ "প্রিয়" আর "সাম্প্রতিক" — এই ফোনে, মানুষ ধরে (মালিক, ১২ অক্টোবর ২০২৬)।
///
/// <p>Kept on the phone only: a favourite is a habit of one hand on one
/// device, and nothing on the server needs to know it. Keyed by the person's
/// id, so a shared phone keeps each person's own.
abstract class NavPrefsStore {
  Future<List<String>> read(String key);
  Future<void> write(String key, List<String> values);
}

/// Encrypted like every other box on the phone — a recent line can carry a
/// shop's name.
class HiveNavPrefsStore implements NavPrefsStore {
  HiveNavPrefsStore();

  static const String _boxName = 'abos_nav_prefs';
  Box<Map>? _box;

  Future<Box<Map>> _open() async =>
      _box ??= await HiveEncryptionKey.openBox<Map>(_boxName);

  @override
  Future<List<String>> read(String key) async {
    final row = (await _open()).get(key);
    final values = row?['v'];
    return values is List ? values.map((e) => e.toString()).toList() : [];
  }

  @override
  Future<void> write(String key, List<String> values) async {
    await (await _open()).put(key, <String, dynamic>{'v': values});
  }
}

/// For tests, and a fallback — lives as long as the object does.
class MemoryNavPrefsStore implements NavPrefsStore {
  final Map<String, List<String>> data = {};

  @override
  Future<List<String>> read(String key) async => [...?data[key]];

  @override
  Future<void> write(String key, List<String> values) async =>
      data[key] = [...values];
}

final navPrefsStoreProvider =
    Provider<NavPrefsStore>((ref) => HiveNavPrefsStore());

/// One line under "সাম্প্রতিক" — a screen, or one customer's page.
class RecentEntry {
  const RecentEntry({required this.path, required this.label});

  /// The `/home/<path>` it reopens, e.g. `orders` or `customers/<publicId>`.
  final String path;
  final String label;

  String encode() => '$path\t$label';

  static RecentEntry? decode(String raw) {
    final tab = raw.indexOf('\t');
    if (tab <= 0) return null;
    return RecentEntry(path: raw.substring(0, tab), label: raw.substring(tab + 1));
  }
}

enum FavouriteChange { added, removed, full }

/// The rules — six favourites at most, eight recents, newest first, no
/// repeats. Every read and write is wrapped: a box that will not open costs
/// the person the list, never the home screen.
class NavPrefs {
  NavPrefs(this.store, this.userId);

  final NavPrefsStore store;
  final String userId;

  static const int maxFavourites = 6;
  static const int maxRecents = 8;

  String get _favKey => 'fav:$userId';
  String get _recentKey => 'recent:$userId';

  Future<List<String>> favourites() async {
    try {
      return await store.read(_favKey);
    } catch (_) {
      return [];
    }
  }

  Future<FavouriteChange> toggleFavourite(String path) async {
    final now = await favourites();
    FavouriteChange change;
    if (now.contains(path)) {
      now.remove(path);
      change = FavouriteChange.removed;
    } else if (now.length >= maxFavourites) {
      return FavouriteChange.full;
    } else {
      now.add(path);
      change = FavouriteChange.added;
    }
    try {
      await store.write(_favKey, now);
    } catch (_) {}
    return change;
  }

  Future<List<RecentEntry>> recents() async {
    try {
      return (await store.read(_recentKey))
          .map(RecentEntry.decode)
          .whereType<RecentEntry>()
          .toList();
    } catch (_) {
      return [];
    }
  }

  Future<void> remember(RecentEntry entry) async {
    final now = (await recents()).where((e) => e.path != entry.path).toList()
      ..insert(0, entry);
    try {
      await store.write(
          _recentKey, now.take(maxRecents).map((e) => e.encode()).toList());
    } catch (_) {}
  }
}
