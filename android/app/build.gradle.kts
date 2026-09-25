plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

val apiBaseUrl: String = listOf(
    System.getenv("SOTERIA_API_BASE_URL").orEmpty(),
    (project.findProperty("soteria.apiBaseUrl") as String?).orEmpty(),
    "https://sleutels.kvt.nl/soteria"
).first { it.isNotBlank() }.trimEnd('/')

android {
    namespace = "nl.kvt.soteria"
    compileSdk = 35

    defaultConfig {
        applicationId = "nl.kvt.soteria"
        minSdk = 26
        targetSdk = 35
        versionCode = System.getenv("GITHUB_RUN_NUMBER")?.toIntOrNull() ?: 1
        versionName = "1.0"
        buildConfigField("String", "API_BASE_URL", "\"${apiBaseUrl.trimEnd('/')}\"")
    }

    signingConfigs {
        val keystoreFile = System.getenv("ANDROID_KEYSTORE_PATH")
        val keystorePassword = System.getenv("ANDROID_KEYSTORE_PASSWORD")
        val keyAlias = System.getenv("ANDROID_KEY_ALIAS")
        val keyPassword = System.getenv("ANDROID_KEY_PASSWORD")
        if (!keystoreFile.isNullOrBlank() && !keystorePassword.isNullOrBlank()) {
            create("release") {
                storeFile = file(keystoreFile)
                storePassword = keystorePassword
                // Alias defaults to "soteria"; key password defaults to keystore password.
                this.keyAlias = keyAlias?.takeIf { it.isNotBlank() } ?: "soteria"
                this.keyPassword = keyPassword?.takeIf { it.isNotBlank() } ?: keystorePassword
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
            // Never fall back to debug signing. If release config is absent, leave
            // unsigned so assembleRelease fails clearly (see task check below).
            signingConfigs.findByName("release")?.let { signingConfig = it }
        }
        debug {
            signingConfig = signingConfigs.getByName("debug")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlin {
        compilerOptions {
            jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
        }
    }

    java {
        toolchain {
            languageVersion.set(JavaLanguageVersion.of(17))
        }
    }

    buildFeatures {
        buildConfig = true
        viewBinding = true
    }
}


// Fail assembleRelease when release signing secrets are missing (local debug builds still work).
tasks.configureEach {
    if (name == "assembleRelease" || name == "packageRelease") {
        doFirst {
            if (android.signingConfigs.findByName("release") == null) {
                throw GradleException(
                    "Release signing is not configured. Set ANDROID_KEYSTORE_PATH and " +
                        "ANDROID_KEYSTORE_PASSWORD (optionally ANDROID_KEY_ALIAS, ANDROID_KEY_PASSWORD)."
                )
            }
        }
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.appcompat:appcompat:1.7.0")
    implementation("androidx.activity:activity-ktx:1.9.3")
    implementation("androidx.constraintlayout:constraintlayout:2.2.0")
    implementation("androidx.recyclerview:recyclerview:1.3.2")
    implementation("com.google.android.material:material:1.12.0")
    implementation("androidx.browser:browser:1.8.0")
    implementation("org.osmdroid:osmdroid-android:6.1.20")
    implementation("com.google.android.gms:play-services-location:21.3.0")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.7")
}
