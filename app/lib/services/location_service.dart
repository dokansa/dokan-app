import 'package:dio/dio.dart';
import 'package:geocoding/geocoding.dart';
import 'package:geolocator/geolocator.dart';

import '../l10n/lang.dart';

class PickedLocation {
  PickedLocation({
    required this.lat,
    required this.lng,
    required this.accuracy,
    this.city = '',
    this.district = '',
    this.street = '',
    this.region = '',
    this.postcode = '',
  });

  final double lat;
  final double lng;
  final double accuracy;
  final String city;
  final String district;
  final String street;
  final String region;
  final String postcode;

  String get mapsUrl => 'https://maps.google.com/?q=$lat,$lng';

  /// "Street, District" for the address line.
  String get line => [street, district].where((s) => s.isNotEmpty).join('، ');
}

class LocationError implements Exception {
  LocationError(this.message, {this.openSettings = false});
  final String message;

  /// True when the customer has to enable location in the phone settings.
  final bool openSettings;
  @override
  String toString() => message;
}

/// One-off location for the delivery address (no tracking).
class LocationService {
  static Future<PickedLocation> current() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw LocationError(AppLang.t('شغّل خدمة الموقع (GPS) في جوالك', 'Turn on location (GPS) on your phone'),
          openSettings: true);
    }
    var perm = await Geolocator.checkPermission();
    if (perm == LocationPermission.denied) perm = await Geolocator.requestPermission();
    if (perm == LocationPermission.denied) {
      throw LocationError(AppLang.t('ما سمحت بالوصول للموقع', 'Location permission was not allowed'));
    }
    if (perm == LocationPermission.deniedForever) {
      throw LocationError(
        AppLang.t('الموقع مقفل للتطبيق، فعّله من الإعدادات', 'Location is blocked for the app — enable it in Settings'),
        openSettings: true,
      );
    }
    final pos = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 15)),
    );
    var city = '', district = '', street = '', region = '', postcode = '';
    // 1) OpenStreetMap (free, no key): usually better Saudi district and street names.
    try {
      final r = await Dio(BaseOptions(
        connectTimeout: const Duration(seconds: 6),
        receiveTimeout: const Duration(seconds: 6),
        headers: {'User-Agent': 'DokanSA-App/1.0 (+https://dokansa.com)'},
      )).get('https://nominatim.openstreetmap.org/reverse', queryParameters: {
        'format': 'jsonv2',
        'lat': pos.latitude,
        'lon': pos.longitude,
        'zoom': 18,
        'addressdetails': 1,
        'accept-language': 'ar',
      });
      final a = (r.data is Map ? r.data['address'] : null) as Map?;
      if (a != null) {
        String f(List<String> keys) {
          for (final k in keys) {
            final v = (a[k] ?? '').toString().trim();
            if (v.isNotEmpty) return v;
          }
          return '';
        }

        city = f(['city', 'town', 'village', 'municipality', 'county']);
        district = f(['suburb', 'neighbourhood', 'quarter', 'city_district', 'residential']);
        street = f(['road', 'pedestrian', 'street']);
        region = f(['state', 'region']);
        postcode = f(['postcode']).replaceAll(RegExp(r'[^0-9]'), '');
      }
    } catch (_) {}
    // 2) The phone's own (also free) geocoder fills whatever is still missing.
    if (city.isEmpty || district.isEmpty) {
    try {
      // Arabic names on purpose: the store's shipping rules match Arabic city names (e.g. الرياض).
      await setLocaleIdentifier('ar_SA');
      final marks = await placemarkFromCoordinates(pos.latitude, pos.longitude);
      if (marks.isNotEmpty) {
        final m = marks.first;
        if (city.isEmpty) city = (m.locality ?? '').isNotEmpty ? m.locality! : (m.administrativeArea ?? '');
        if (district.isEmpty) {
          district = (m.subLocality ?? '').isNotEmpty ? m.subLocality! : (m.subAdministrativeArea ?? '');
        }
        if (street.isEmpty) street = m.thoroughfare ?? m.street ?? '';
        if (region.isEmpty) region = m.administrativeArea ?? '';
        if (postcode.isEmpty) postcode = (m.postalCode ?? '').replaceAll(RegExp(r'[^0-9]'), '');
      }
    } catch (_) {
      // Reverse geocoding is best effort; the pin itself is what matters.
    }
    }
    return PickedLocation(
      lat: pos.latitude,
      lng: pos.longitude,
      accuracy: pos.accuracy,
      city: city,
      district: district,
      street: street,
      region: region,
      postcode: postcode,
    );
  }

  static Future<void> openSettings() => Geolocator.openAppSettings();
}
