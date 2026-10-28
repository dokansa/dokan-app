import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api/client.dart';
import 'l10n/lang.dart';
import 'router.dart';
import 'services/push_service.dart';
import 'state/providers.dart';
import 'theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await AppLang.load(await SharedPreferences.getInstance());
  await initializeDateFormatting('ar');
  await initializeDateFormatting('en');
  final client = await ApiClient.create();
  final container = ProviderContainer(overrides: [apiClientProvider.overrideWithValue(client)]);
  // Push is optional: the app works without Firebase configured.
  await PushService.init(container);
  runApp(UncontrolledProviderScope(container: container, child: const DokanApp()));
}

class DokanApp extends ConsumerWidget {
  const DokanApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final lang = ref.watch(langProvider);
    return MaterialApp.router(
      title: lang == 'ar' ? 'دكان' : 'Dokan',
      debugShowCheckedModeBanner: false,
      theme: buildTheme(),
      locale: lang == 'ar' ? const Locale('ar', 'SA') : const Locale('en'),
      supportedLocales: const [Locale('ar', 'SA'), Locale('en')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      routerConfig: ref.watch(routerProvider),
    );
  }
}
