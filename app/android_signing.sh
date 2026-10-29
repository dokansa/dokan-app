#!/usr/bin/env bash
# Signs test builds with a fixed key (android-test.jks), so each new build installs over the
# previous one and Shorebird patches match the release. Test package only — the live Play app
# (com.dokansa.shop) is signed with the store's own key, not this one.
set -e
cd "$(dirname "$0")/android/app"
PW='o6N4ArCK8dYNdWzz9nc_hbN3'
if [ -f build.gradle.kts ]; then
  grep -q dokanTest build.gradle.kts || perl -0pi -e 's/android \{/android {\n    signingConfigs {\n        create("dokanTest") {\n            storeFile = file("..\/..\/android-test.jks")\n            storePassword = "'"$PW"'"\n            keyAlias = "dokan"\n            keyPassword = "'"$PW"'"\n        }\n    }/' build.gradle.kts
  perl -pi -e 's/signingConfig\s*=\s*signingConfigs\.getByName\("debug"\)/signingConfig = signingConfigs.getByName("dokanTest")/' build.gradle.kts
else
  grep -q dokanTest build.gradle || perl -0pi -e 's/android \{/android {\n    signingConfigs {\n        dokanTest {\n            storeFile file("..\/..\/android-test.jks")\n            storePassword "'"$PW"'"\n            keyAlias "dokan"\n            keyPassword "'"$PW"'"\n        }\n    }/' build.gradle
  perl -pi -e 's/signingConfig\s+signingConfigs\.debug/signingConfig signingConfigs.dokanTest/' build.gradle
fi
grep -n "dokanTest" build.gradle* | head
