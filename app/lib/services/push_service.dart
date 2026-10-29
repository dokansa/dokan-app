import 'dart:async';
import 'dart:io' show Platform;

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../firebase_options.dart';
import '../router.dart';
import '../state/providers.dart';

/// Firebase push: registers the device with the store and opens the right screen on tap.
class PushService {
  static bool _ready = false;
  static String? _token;

  static Future<void> init(ProviderContainer container) async {
    try {
      if (!Platform.isAndroid) {
        // iOS needs its own Firebase app registration (GoogleService-Info.plist) first.
        await Firebase.initializeApp();
      } else {
        await Firebase.initializeApp(options: FirebaseConfig.android);
      }
    } catch (e) {
      debugPrint('Push disabled (Firebase not configured): $e');
      return;
    }
    _ready = true;
    final fm = FirebaseMessaging.instance;

    // Register whenever the customer is logged in AND has already allowed notifications.
    // Asking for permission happens through our own explanation first (askNotifications).
    container.listen(sessionProvider, (prev, user) {
      if (user != null) unawaited(_register(container, ask: false));
    }, fireImmediately: true);
    fm.onTokenRefresh.listen((t) {
      _token = t;
      if (container.read(sessionProvider) != null) unawaited(_register(container, ask: false));
    });

    FirebaseMessaging.onMessageOpenedApp.listen((m) => _open(container, m.data));
    final initial = await fm.getInitialMessage();
    if (initial != null) {
      // Wait for the first frame so the router exists.
      Future.delayed(const Duration(milliseconds: 600), () => _open(container, initial.data));
    }
    FirebaseMessaging.onMessage.listen((m) {
      if (m.data['type'] == 'order') container.invalidate(ordersProvider);
    });
  }

  static bool get available => _ready;

  /// Whether the customer has allowed notifications (null = can't tell / push not set up).
  static Future<bool?> isAllowed() async {
    if (!_ready) return null;
    try {
      final s = await FirebaseMessaging.instance.getNotificationSettings();
      return s.authorizationStatus == AuthorizationStatus.authorized ||
          s.authorizationStatus == AuthorizationStatus.provisional;
    } catch (_) {
      return null;
    }
  }

  /// True when the system dialog can still be shown (not decided yet).
  static Future<bool> canAsk() async {
    if (!_ready) return false;
    try {
      final s = await FirebaseMessaging.instance.getNotificationSettings();
      return s.authorizationStatus == AuthorizationStatus.notDetermined;
    } catch (_) {
      return false;
    }
  }

  /// Shows the system permission dialog, then registers the device. Returns true if allowed.
  static Future<bool> requestAndRegister(ProviderContainer container) async {
    if (!_ready) return false;
    await _register(container, ask: true);
    return await isAllowed() ?? false;
  }

  /// Sends the token to the store. With [ask] the system permission dialog is shown first.
  static Future<void> _register(ProviderContainer container, {required bool ask}) async {
    if (!_ready) return;
    try {
      final fm = FirebaseMessaging.instance;
      final settings = ask
          ? await fm.requestPermission(alert: true, badge: true, sound: true)
          : await fm.getNotificationSettings();
      if (settings.authorizationStatus != AuthorizationStatus.authorized &&
          settings.authorizationStatus != AuthorizationStatus.provisional) {
        return;
      }
      if (Platform.isIOS) await fm.getAPNSToken();
      _token ??= await fm.getToken();
      if (_token == null) return;
      await container.read(appApiProvider).registerDevice(_token!, Platform.isIOS ? 'ios' : 'android');
    } catch (e) {
      debugPrint('Push register failed: $e');
    }
  }

  static Future<void> unregister(ProviderContainer container) async {
    if (!_ready || _token == null) return;
    try {
      await container.read(appApiProvider).unregisterDevice(_token!);
    } catch (_) {}
  }

  static void _open(ProviderContainer container, Map<String, dynamic> data) {
    final router = container.read(routerProvider);
    switch (data['type']) {
      case 'order':
        final id = data['order_id'];
        if (id != null) router.push('/order/$id');
        break;
      case 'reminder':
        final pid = data['product_id'];
        if (pid != null) router.push('/product/$pid');
        break;
      case 'promo':
      case 'link':
        final link = data['link']?.toString() ?? '';
        if (link.startsWith('/')) router.push(link);
        break;
    }
  }
}
