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
    try {
      // Arabic names on purpose: the store's shipping rules match Arabic city names (e.g. الرياض).
      await setLocaleIdentifier('ar_SA');
      final marks = await placemarkFromCoordinates(pos.latitude, pos.longitude);
      if (marks.isNotEmpty) {
        final m = marks.first;
        city = (m.locality ?? '').isNotEmpty ? m.locality! : (m.administrativeArea ?? '');
        district = (m.subLocality ?? '').isNotEmpty ? m.subLocality! : (m.subAdministrativeArea ?? '');
        street = m.thoroughfare ?? m.street ?? '';
        region = m.administrativeArea ?? '';
        postcode = (m.postalCode ?? '').replaceAll(RegExp(r'[^0-9]'), '');
      }
    } catch (_) {
      // Reverse geocoding is best effort; the pin itself is what matters.
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
