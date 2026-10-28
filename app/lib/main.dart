import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'api/client.dart';
import 'router.dart';
import 'services/push_service.dart';
import 'state/providers.dart';
import 'theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
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
    return MaterialApp.router(
      title: 'دكان',
      debugShowCheckedModeBanner: false,
      theme: buildTheme(),
      locale: const Locale('ar', 'SA'),
      supportedLocales: const [Locale('ar', 'SA')],
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      routerConfig: ref.watch(routerProvider),
    );
  }
}
