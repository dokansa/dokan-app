/// Store connection settings. Change [storeUrl] to the staging site while testing.
class AppConfig {
  static const String storeUrl = String.fromEnvironment(
    'STORE_URL',
    defaultValue: 'https://dokansa.com',
  );

  static String get storeApi => '$storeUrl/wp-json/wc/store/v1';
  static String get appApi => '$storeUrl/wp-json/dokan-app/v1';

  /// The store runs Polylang with Arabic and English copies of every product; the app uses Arabic.
  static const String language = 'ar';

  /// Riyadh same-day cut-off shown in the UI (hour, 24h).
  static const int riyadhCutoffHour = 15;
}
