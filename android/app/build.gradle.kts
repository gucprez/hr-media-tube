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
        versionName = "0.1.0-demo"
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"))
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
    testImplementation(libs.robolectric)
    testImplementation(platform(libs.compose.bom))
    testImplementation(libs.compose.ui.test.junit4)
    debugImplementation(libs.compose.ui.test.manifest)
    testImplementation(libs.kotlinx.coroutines.test)
}
