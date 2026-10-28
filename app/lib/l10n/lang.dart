import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// App language (Arabic default, English optional).
///
/// UI text is written inline as `context.tr('عربي', 'English')` so both versions live
/// next to each other. Code without a BuildContext uses [AppLang.isAr].
class AppLang {
  static String code = 'ar';
  static bool get isAr => code == 'ar';
  static String t(String ar, String en) => isAr ? ar : en;

  static const _key = 'app_lang';

  static Future<void> load(SharedPreferences prefs) async {
    final saved = prefs.getString(_key);
    if (saved == 'ar' || saved == 'en') {
      code = saved!;
    } else {
      final device = WidgetsBinding.instance.platformDispatcher.locale.languageCode;
      code = device == 'en' ? 'en' : 'ar';
    }
  }

  static Future<void> save(String c) async {
    code = c;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key, c);
  }
}

extension TrX on BuildContext {
  bool get isAr => Localizations.localeOf(this).languageCode == 'ar';
  String tr(String ar, String en) => isAr ? ar : en;
}

/// Current language code. Providers that fetch store content watch this, so switching
/// language reloads products, categories and config in the new language.
class LangNotifier extends Notifier<String> {
  @override
  String build() => AppLang.code;

  Future<void> set(String code) async {
    if (code == state) return;
    await AppLang.save(code);
    state = code;
  }
}

final langProvider = NotifierProvider<LangNotifier, String>(LangNotifier.new);

// ------------------------------------------------------------------ shared labels

String petKindLabel(String kind, bool ar) {
  switch (kind) {
    case 'cat':
      return ar ? 'قطة' : 'Cat';
    case 'dog':
      return ar ? 'كلب' : 'Dog';
    case 'bird':
      return ar ? 'طير' : 'Bird';
    case 'fish':
      return ar ? 'سمك' : 'Fish';
    case 'small':
      return ar ? 'حيوان صغير' : 'Small pet';
  }
  return kind;
}

/// Plural, for headings like "Best sellers for cats".
String petKindPlural(String kind, bool ar) {
  switch (kind) {
    case 'cat':
      return ar ? 'للقطط' : 'for cats';
    case 'dog':
      return ar ? 'للكلاب' : 'for dogs';
    case 'bird':
      return ar ? 'للطيور' : 'for birds';
    case 'fish':
      return ar ? 'للأسماك' : 'for fish';
    case 'small':
      return ar ? 'للحيوانات الصغيرة' : 'for small pets';
  }
  return '';
}

String foodTypeLabel(String type, bool ar) {
  switch (type) {
    case 'dry':
      return ar ? 'جاف' : 'Dry';
    case 'wet':
      return ar ? 'رطب' : 'Wet';
    case 'mixed':
      return ar ? 'مختلط' : 'Mixed';
  }
  return type;
}

String currencyLabel(bool ar) => ar ? 'ر.س' : 'SAR';
