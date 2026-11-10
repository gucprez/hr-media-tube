plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
    alias(libs.plugins.kotlin.compose)
}

android {
    namespace = "com.hrmediasports.studio"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.hrmediasports.studio"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "0.2.0-demo"
        // WebRTC pesa ~11 MB por ABI: solo 64 bits (teléfonos actuales y emuladores x86_64).
        ndk { abiFilters += listOf("arm64-v8a", "x86_64") }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }
    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions {
        jvmTarget = "17"
    }
    testOptions {
        unitTests.isIncludeAndroidResources = true
        unitTests.all { test ->
            // Las capturas (Robolectric) son opcionales: ./gradlew testDebugUnitTest -Pscreenshots
            if (!project.hasProperty("screenshots")) test.exclude("**/shots/**")
            // Opcional: carpeta local con los jars de Robolectric para trabajar sin red.
            System.getenv("ROBOLECTRIC_DEPS")?.let {
                test.systemProperty("robolectric.dependency.dir", it)
                test.systemProperty("robolectric.offline", "true")
            }
        }
    }
    buildFeatures {
        compose = true
    }
}

dependencies {
    implementation(project(":core-link"))
    implementation(libs.okhttp)
    implementation(libs.webrtc.android)
    implementation(libs.camerax.core)
    implementation(libs.camerax.camera2)
    implementation(libs.camerax.lifecycle)
    implementation(libs.camerax.view)
    implementation(libs.zxing.core)
    implementation(platform(libs.compose.bom))
    implementation(libs.compose.ui)
    implementation(libs.compose.ui.tooling.preview)
    implementation(libs.compose.material3)
    implementation(libs.compose.material.icons.extended)
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.lifecycle.runtime.compose)
    debugImplementation(libs.compose.ui.tooling)

    testImplementation(libs.junit)
    // Prueba de integración: el cliente Android real contra el servidor real del estudio, en la JVM.
    testImplementation(project(":studio-server"))
    testImplementation(libs.ktor.server.core)
    testImplementation(libs.ktor.server.netty)
    testImplementation(libs.ktor.server.websockets)
    testImplementation(libs.robolectric)
    testImplementation(platform(libs.compose.bom))
    testImplementation(libs.compose.ui.test.junit4)
    debugImplementation(libs.compose.ui.test.manifest)
    testImplementation(libs.kotlinx.coroutines.test)
}
