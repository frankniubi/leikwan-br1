-keepattributes Signature,*Annotation*

-keepclasseswithmembers,allowshrinking,allowoptimization,includedescriptorclasses class * {
    @com.google.gson.annotations.SerializedName <fields>;
}

-keepclassmembers,allowoptimization class com.leikwan.bridge.api.** { <fields>; }
-keepclassmembers,allowoptimization class com.leikwan.bridge.security.AttestationProof { <fields>; }
-keep,allowoptimization interface com.leikwan.bridge.api.PanelApi { *; }
-keep class io.nekohasekai.libbox.** { *; }
