-keepattributes Signature,*Annotation*

# R8 optimization currently miscompiles the Kotlin suspend enrollment state
# machine (the generated release method loses the second HTTP call). Keep
# shrinking and obfuscation, but disable optimizer rewrites until the Android
# toolchain is upgraded and the release bytecode is re-audited.
-dontoptimize

-keepclasseswithmembers,allowshrinking,allowoptimization,includedescriptorclasses class * {
    @com.google.gson.annotations.SerializedName <fields>;
}

-keepclassmembers,allowoptimization class com.leikwan.bridge.api.** { <fields>; }
-keepclassmembers,allowoptimization class com.leikwan.bridge.security.AttestationProof { <fields>; }
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
-keep class io.nekohasekai.libbox.** { *; }
