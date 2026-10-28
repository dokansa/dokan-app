import 'dart:io' show Platform;

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../config.dart';

/// Error shown to the customer. [message] is already Arabic.
class ApiError implements Exception {
  ApiError(this.message, {this.code, this.status});
  final String message;
  final String? code;
  final int? status;
  @override
  String toString() => message;
}

/// Holds the customer token, Store API cart token and nonce, and the shared Dio instance.
class ApiClient {
  ApiClient._(this.dio, this._secure, this._prefs);

  final Dio dio;
  final FlutterSecureStorage _secure;
  final SharedPreferences _prefs;

  String? authToken;
  String? cartToken;
  String? nonce;

  static const _kAuth = 'auth_token';
  static const _kCart = 'cart_token';
  static const _kNonce = 'store_nonce';

  static Future<ApiClient> create() async {
    const secure = FlutterSecureStorage(
      aOptions: AndroidOptions(encryptedSharedPreferences: true),
    );
    final prefs = await SharedPreferences.getInstance();
    final dio = Dio(BaseOptions(
      connectTimeout: const Duration(seconds: 15),
      receiveTimeout: const Duration(seconds: 30),
      headers: {'Accept': 'application/json'},
      validateStatus: (s) => s != null && s < 500,
    ));
    final client = ApiClient._(dio, secure, prefs);
    client.authToken = await secure.read(key: _kAuth);
    client.cartToken = prefs.getString(_kCart);
    client.nonce = prefs.getString(_kNonce);
    dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) {
        options.headers['X-Dokan-App'] = '1';
        options.headers['X-Dokan-App-Platform'] = Platform.isIOS ? 'ios' : 'android';
        options.headers['Accept-Language'] = 'ar';
        if (AppConfig.stagingCookie.isNotEmpty) {
          options.headers['Cookie'] = 'dk_stg=${AppConfig.stagingCookie}';
        }
        final t = client.authToken;
        if (t != null) {
          options.headers['Authorization'] = 'Bearer $t';
          options.headers['X-Dokan-App-Token'] = t;
        }
        if (options.path.startsWith(AppConfig.storeApi) ||
            options.path.contains('/dokan-app/v1/orders/')) {
          if (client.cartToken != null) options.headers['Cart-Token'] = client.cartToken;
          if (client.nonce != null) options.headers['Nonce'] = client.nonce;
        }
        handler.next(options);
      },
      onResponse: (response, handler) {
        final ct = response.headers.value('cart-token');
        if (ct != null && ct.isNotEmpty && ct != client.cartToken) {
          client.cartToken = ct;
          prefs.setString(_kCart, ct);
        }
        final n = response.headers.value('nonce');
        if (n != null && n.isNotEmpty && n != client.nonce) {
          client.nonce = n;
          prefs.setString(_kNonce, n);
        }
        handler.next(response);
      },
    ));
    return client;
  }

  Future<void> setAuthToken(String? token) async {
    authToken = token;
    if (token == null) {
      await _secure.delete(key: _kAuth);
    } else {
      await _secure.write(key: _kAuth, value: token);
    }
  }

  /// A new cart session (used after logout so the next customer starts clean).
  Future<void> resetCart() async {
    cartToken = null;
    nonce = null;
    await _prefs.remove(_kCart);
    await _prefs.remove(_kNonce);
  }

  Future<dynamic> get(String url, {Map<String, dynamic>? query}) =>
      _send(() => dio.get(url, queryParameters: query));

  Future<dynamic> post(String url, {Object? body}) =>
      _send(() => dio.post(url, data: body ?? const {}));

  Future<dynamic> patch(String url, {Object? body}) =>
      _send(() => dio.patch(url, data: body ?? const {}));

  Future<dynamic> delete(String url, {Object? body}) =>
      _send(() => dio.delete(url, data: body));

  /// GET that also returns the X-WP-TotalPages header (for paging product lists).
  Future<(dynamic, int)> getPaged(String url, {Map<String, dynamic>? query}) async {
    late Response res;
    final data = await _send(() async {
      res = await dio.get(url, queryParameters: query);
      return res;
    });
    final pages = int.tryParse(res.headers.value('x-wp-totalpages') ?? '') ?? 1;
    return (data, pages);
  }

  Future<dynamic> _send(Future<Response> Function() call) async {
    Response res;
    try {
      res = await call();
    } on DioException catch (e) {
      if (e.response != null) {
        res = e.response!;
      } else {
        throw ApiError('تأكد من اتصالك بالإنترنت وحاول مرة ثانية');
      }
    }
    final status = res.statusCode ?? 0;
    if (status >= 200 && status < 300) return res.data;
    final data = res.data;
    String msg = 'صار خطأ، حاول مرة ثانية';
    String? code;
    if (data is Map) {
      code = data['code']?.toString();
      final m = data['message']?.toString();
      if (m != null && m.isNotEmpty) msg = _stripTags(m);
    }
    if (status == 401 || code == 'dokan_app_invalid_token') {
      await setAuthToken(null);
    }
    throw ApiError(msg, code: code, status: status);
  }

  static String _stripTags(String s) => s.replaceAll(RegExp(r'<[^>]*>'), '').trim();
}
