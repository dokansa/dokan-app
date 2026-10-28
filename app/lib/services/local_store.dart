import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Small on-device lists: products the customer opened and what they searched for.
class _IdList extends Notifier<List<int>> {
  _IdList(this.key, this.max);
  final String key;
  final int max;

  @override
  List<int> build() {
    _load();
    return const [];
  }

  Future<void> _load() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getStringList(key) ?? const [];
    state = [for (final s in raw) int.tryParse(s) ?? 0].where((i) => i > 0).toList();
  }

  Future<void> add(int id) async {
    if (state.isNotEmpty && state.first == id) return;
    state = [id, ...state.where((x) => x != id)].take(max).toList();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setStringList(key, [for (final i in state) '$i']);
  }

  Future<void> clear() async {
    state = const [];
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(key);
  }
}

class _TextList extends Notifier<List<String>> {
  _TextList(this.key, this.max);
  final String key;
  final int max;

  @override
  List<String> build() {
    _load();
    return const [];
  }

  Future<void> _load() async {
    final prefs = await SharedPreferences.getInstance();
    state = prefs.getStringList(key) ?? const [];
  }

  Future<void> add(String text) async {
    final t = text.trim();
    if (t.length < 2) return;
    state = [t, ...state.where((x) => x != t)].take(max).toList();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setStringList(key, state);
  }

  Future<void> remove(String text) async {
    state = state.where((x) => x != text).toList();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setStringList(key, state);
  }

  Future<void> clear() async {
    state = const [];
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(key);
  }
}

final recentlyViewedProvider = NotifierProvider<_IdList, List<int>>(() => _IdList('recently_viewed', 24));

final recentSearchesProvider = NotifierProvider<_TextList, List<String>>(() => _TextList('recent_searches', 10));
