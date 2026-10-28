import 'package:firebase_core/firebase_core.dart';

/// Firebase project "Dokansa" (dokansa-99b5b). These are public client identifiers
/// (they ship inside every app), not secrets.
class FirebaseConfig {
  /// Live app: com.dokansa.shop. Test builds pass the com.dokansa.shop.test app id.
  static const String appId = String.fromEnvironment(
    'FIREBASE_APP_ID',
    defaultValue: '1:917838520906:android:37e425e664dba2a433fcb3',
  );

  static const FirebaseOptions android = FirebaseOptions(
    apiKey: 'AIzaSyBKmtVLfgBJotR2ffg5WH-gwVepuogSyr4',
    appId: appId,
    messagingSenderId: '917838520906',
    projectId: 'dokansa-99b5b',
    storageBucket: 'dokansa-99b5b.firebasestorage.app',
  );
}
