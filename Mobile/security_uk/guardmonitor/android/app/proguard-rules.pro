# R8/ProGuard keep rules for release builds (minifyEnabled + shrinkResources).
# Most plugins ship their own consumer rules that R8 picks up automatically; the
# entries below are the ones this app needs explicitly.

# --- flutter_local_notifications ---------------------------------------------
# Uses Gson + generic TypeToken reflection to (de)serialise scheduled notification
# details; without these keeps, scheduled/again-shown notifications can break in a
# minified build. (Documented by the plugin.)
-keep class com.dexterous.** { *; }
-keep class com.google.gson.reflect.TypeToken { *; }
-keep class * extends com.google.gson.reflect.TypeToken
-keepattributes Signature
-keepattributes *Annotation*

# --- Gson (transitive; keep serialized model shapes reflected by name) --------
-keepclassmembers,allowobfuscation class * {
    @com.google.gson.annotations.SerializedName <fields>;
}

# --- Play Core (Flutter deferred components stubs referenced by the engine) ---
# Prevents "missing class com.google.android.play.core.**" R8 warnings from
# failing the build on projects that don't use dynamic feature delivery.
-dontwarn com.google.android.play.core.**

# Note: Firebase (messaging), Drift/SQLCipher (native), Geolocator, camera, and
# connectivity_plus all ship consumer ProGuard rules, so no manual keeps are needed
# for them. If a release build hits a runtime ClassNotFound/reflection failure,
# add a targeted -keep here for that class and document why.
