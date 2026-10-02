-keepattributes Signature,*Annotation*,InnerClasses,EnclosingMethod

# R8 optimization currently miscompiles the Kotlin suspend enrollment state
# machine (the generated release method loses the second HTTP call). Keep
# shrinking and obfuscation, but disable optimizer rewrites until the Android
# toolchain is upgraded and the release bytecode is re-audited.
-dontoptimize

# ---------------------------------------------------------------------------
# Gson (2.10.1 ships no consumer rules). Field names are the wire format to the
# panel and the persisted format in SharedPreferences / Room payload columns, so
# every reflected model keeps its field names and constructors. Constructors are
# kept so R8 never treats a Gson-only (Unsafe-allocated) class as uninstantiated.
# ---------------------------------------------------------------------------
-keepclasseswithmembers,allowshrinking,allowoptimization,includedescriptorclasses class * {
    @com.google.gson.annotations.SerializedName <fields>;
}
-if class * { @com.google.gson.annotations.SerializedName <fields>; }
-keepclassmembers class <1> { <fields>; <init>(...); }

# Models serialized by Gson without any @SerializedName field.
-keepclassmembers class com.leikwan.bridge.api.** { <fields>; <init>(...); }
-keepclassmembers class com.leikwan.bridge.session.** { <fields>; <init>(...); }
-keepclassmembers class com.leikwan.bridge.clientrules.** { <fields>; <init>(...); }
-keepclassmembers class com.leikwan.bridge.data.routing.RulePackManifest** { <fields>; <init>(...); }
-keepclassmembers class com.leikwan.bridge.runtime.**Dto { <fields>; <init>(...); }
-keepclassmembers class com.leikwan.bridge.security.AttestationProof { <fields>; }
# Gson's enum adapter reads enum constants reflectively.
-keepclassmembers enum com.leikwan.bridge.** { <fields>; public static **[] values(); public static ** valueOf(java.lang.String); }
-keep,allowobfuscation,allowshrinking class com.google.gson.reflect.TypeToken
-keep,allowobfuscation,allowshrinking class * extends com.google.gson.reflect.TypeToken

# ---------------------------------------------------------------------------
# Retrofit: the service interface is proxied reflectively (retrofit 2.11 ships
# its own rules for Response / Continuation; this keeps our annotations intact).
# ---------------------------------------------------------------------------
-keep,allowoptimization interface com.leikwan.bridge.api.PanelApi { *; }

# R8 8.5 can rewrite Kotlin suspend state machines that cross a private
# helper into a void method. Keep the enrollment repository and its generated
# continuations intact so the challenge -> identity-enroll sequence remains
# executable in release builds.
-keep class com.leikwan.bridge.api.PanelRepository { *; }
-keep class com.leikwan.bridge.api.PanelRepository$* { *; }
-keep class com.leikwan.bridge.session.DeviceIdentityEnrollRequest { *; }
-keep class com.leikwan.bridge.session.EnrollmentCodeIdentityEnrollRequest { *; }
-keep class com.leikwan.bridge.session.DeviceRenewRequest { *; }

# ---------------------------------------------------------------------------
# sing-box libbox (gomobile). Go calls back into Java over JNI by class and
# method name, so the generated bindings (go.*, io.nekohasekai.*) stay intact.
# App classes implementing libbox interfaces keep those method names because
# the interface methods are kept.
# ---------------------------------------------------------------------------
-keep class go.** { *; }
-keep class io.nekohasekai.** { *; }
-keep class * implements io.nekohasekai.libbox.** { *; }

# ---------------------------------------------------------------------------
# Android Keystore / JCA: providers and key classes are looked up by name.
# (Platform classes are not part of the program; nothing to keep for them.)
# ---------------------------------------------------------------------------
-keepclassmembers class * extends java.security.Provider { <init>(...); }

# ---------------------------------------------------------------------------
# Release contract (scripts/verify-android-build-version.ps1 and
# verify-android-enrollment-bytecode.ps1) reads these classes by name.
# ---------------------------------------------------------------------------
-keep class com.leikwan.bridge.BuildConfig { *; }
-keepnames class com.leikwan.bridge.runtime.NativeOutboundCapability
-keepnames class com.leikwan.bridge.runtime.NativeOutboundLease
-keepnames class com.leikwan.bridge.service.TunnelOrchestrator
-keepnames class com.leikwan.bridge.ui.connections.ConnectionsScreenKt
# The enrollment check names suspend methods by their JVM descriptor.
-keepnames interface kotlin.coroutines.Continuation
