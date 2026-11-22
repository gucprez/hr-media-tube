pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
        // Solo para RootEncoder (Apache-2.0), que se publica en JitPack.
        maven("https://jitpack.io") { content { includeGroupByRegex("com\\.github\\.pedroSG94.*") } }
    }
}

rootProject.name = "HRMediaSports"
include(":app")
