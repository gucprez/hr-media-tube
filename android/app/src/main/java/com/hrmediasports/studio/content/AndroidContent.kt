package com.hrmediasports.studio.content

import android.content.Context
import android.content.Intent
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.media.MediaCodecList
import android.media.MediaExtractor
import android.media.MediaFormat
import android.media.MediaMetadataRetriever
import android.net.Uri
import android.os.Build
import android.provider.OpenableColumns
import android.util.LruCache
import android.util.Size
import java.io.File
import java.util.UUID

/** Guarda biblioteca y listas en un archivo privado de la app (solo referencias y ajustes). */
class FileContentStore(context: Context) : ContentStore {
    private val file = File(context.applicationContext.filesDir, "contenido-descansos.json")

    override fun load(): ContentState = ContentJson.decode(runCatching { file.readText() }.getOrNull())

    override fun save(state: ContentState) {
        // Escritura atómica: si la app se cierra a mitad, no queda un archivo a medias.
        runCatching {
            val tmp = File(file.parentFile, file.name + ".tmp")
            tmp.writeText(ContentJson.encode(state))
            if (!tmp.renameTo(file)) { file.writeText(tmp.readText()); tmp.delete() }
        }
    }
}

/**
 * Lee los datos de un archivo elegido con el selector del sistema y valida si este dispositivo puede
 * mostrarlo. Solo lee metadatos (nunca carga el video en memoria). NO PROBADO en un dispositivo real:
 * la disponibilidad de decodificadores depende de cada teléfono.
 */
class MediaInspector(context: Context) {
    private val ctx = context.applicationContext

    /** Conserva el permiso de lectura para que el archivo siga disponible tras reiniciar la app. */
    fun keepPermission(uri: Uri) {
        runCatching { ctx.contentResolver.takePersistableUriPermission(uri, Intent.FLAG_GRANT_READ_URI_PERMISSION) }
    }

    /** Libera el permiso de lectura de un archivo que ya no se usa (Android limita cuántos se conservan). */
    fun releasePermission(uriText: String) {
        runCatching { ctx.contentResolver.releasePersistableUriPermission(Uri.parse(uriText), Intent.FLAG_GRANT_READ_URI_PERMISSION) }
    }

    /**
     * Vuelve a validar un archivo ya importado: puede haberse borrado, movido o perdido el permiso.
     * Conserva su identidad (id y nombre) para no romper las listas que lo usan.
     */
    fun recheck(item: MediaItem): MediaItem {
        val fresh = inspect(Uri.parse(item.uri))
        return fresh.copy(id = item.id, name = item.name)
    }

    fun inspect(uri: Uri): MediaItem {
        val mime = runCatching { ctx.contentResolver.getType(uri) }.getOrNull()
        val name = displayName(uri) ?: uri.lastPathSegment ?: "archivo"
        val base = MediaItem(id = UUID.randomUUID().toString(), uri = uri.toString(), name = name, type = MediaType.Image, mime = mime)
        return when {
            mime?.startsWith("image/") == true -> inspectImage(uri, base)
            mime?.startsWith("video/") == true -> inspectVideo(uri, base.copy(type = MediaType.Video))
            else -> base.copy(compat = Compat.Unsupported, compatNote = "tipo de archivo no admitido (${mime ?: "desconocido"})")
        }
    }

    private fun displayName(uri: Uri): String? = runCatching {
        ctx.contentResolver.query(uri, arrayOf(OpenableColumns.DISPLAY_NAME), null, null, null)?.use { c ->
            if (c.moveToFirst()) c.getString(0) else null
        }
    }.getOrNull()

    private fun inspectImage(uri: Uri, base: MediaItem): MediaItem = try {
        val opts = BitmapFactory.Options().apply { inJustDecodeBounds = true }   // solo dimensiones, sin decodificar
        ctx.contentResolver.openInputStream(uri).use { BitmapFactory.decodeStream(it, null, opts) }
        if (opts.outWidth > 0 && opts.outHeight > 0) base.copy(width = opts.outWidth, height = opts.outHeight, compat = Compat.Ok)
        else base.copy(compat = Compat.Unsupported, compatNote = "no se pudo leer la imagen")
    } catch (e: java.io.FileNotFoundException) {
        base.copy(compat = Compat.Missing)
    } catch (e: SecurityException) {
        base.copy(compat = Compat.Missing, compatNote = "se perdió el permiso de lectura")
    } catch (e: Exception) {
        base.copy(compat = Compat.Unsupported, compatNote = "no se pudo leer la imagen")
    }

    private fun inspectVideo(uri: Uri, base: MediaItem): MediaItem {
        val mmr = MediaMetadataRetriever()
        val extractor = MediaExtractor()
        return try {
            mmr.setDataSource(ctx, uri)
            val duration = mmr.extractMetadata(MediaMetadataRetriever.METADATA_KEY_DURATION)?.toLongOrNull()
            val w = mmr.extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_WIDTH)?.toIntOrNull()
            val h = mmr.extractMetadata(MediaMetadataRetriever.METADATA_KEY_VIDEO_HEIGHT)?.toIntOrNull()
            extractor.setDataSource(ctx, uri, null)
            val format = (0 until extractor.trackCount).map { extractor.getTrackFormat(it) }
                .firstOrNull { it.getString(MediaFormat.KEY_MIME)?.startsWith("video/") == true }
            val item = base.copy(durationMs = duration, width = w, height = h)
            when {
                format == null -> item.copy(compat = Compat.Unsupported, compatNote = "el archivo no tiene pista de video")
                duration == null || duration <= 0 -> item.copy(compat = Compat.Unsupported, compatNote = "no informa su duración")
                else -> {
                    val codecs = MediaCodecList(MediaCodecList.REGULAR_CODECS)
                    val decoder = codecs.findDecoderForFormat(format)
                    if (decoder == null) {
                        item.copy(compat = Compat.Unsupported, compatNote = "este dispositivo no tiene decodificador para ${format.getString(MediaFormat.KEY_MIME)}")
                    } else {
                        val hw = if (Build.VERSION.SDK_INT >= 29) codecs.codecInfos.firstOrNull { it.name == decoder }?.isHardwareAccelerated else null
                        item.copy(compat = Compat.Ok, hardwareDecoder = hw)
                    }
                }
            }
        } catch (e: java.io.FileNotFoundException) {
            base.copy(compat = Compat.Missing)
        } catch (e: SecurityException) {
            base.copy(compat = Compat.Missing, compatNote = "se perdió el permiso de lectura")
        } catch (e: Exception) {
            base.copy(compat = Compat.Unsupported, compatNote = "no se pudo leer el video")
        } finally {
            runCatching { mmr.release() }
            runCatching { extractor.release() }
        }
    }
}

/**
 * Miniaturas pequeñas con memoria acotada: como mucho [MAX_ENTRIES] imágenes de hasta [MAX_PX] px.
 * Nunca se decodifica el archivo completo ni se cargan videos en memoria.
 */
object Thumbnails {
    const val MAX_PX = 256
    private const val MAX_ENTRIES = 24
    private val cache = LruCache<String, Bitmap>(MAX_ENTRIES)

    fun load(context: Context, uriText: String, isVideo: Boolean): Bitmap? {
        cache.get(uriText)?.let { return it }
        val uri = Uri.parse(uriText)
        val bmp = runCatching {
            if (Build.VERSION.SDK_INT >= 29) context.contentResolver.loadThumbnail(uri, Size(MAX_PX, MAX_PX), null)
            else if (isVideo) videoFrame(context, uri) else sampledImage(context, uri)
        }.getOrNull() ?: runCatching { if (isVideo) videoFrame(context, uri) else sampledImage(context, uri) }.getOrNull()
        if (bmp != null) cache.put(uriText, bmp)
        return bmp
    }

    private fun sampledImage(context: Context, uri: Uri): Bitmap? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        context.contentResolver.openInputStream(uri).use { BitmapFactory.decodeStream(it, null, bounds) }
        var sample = 1
        while (bounds.outWidth / (sample * 2) >= MAX_PX || bounds.outHeight / (sample * 2) >= MAX_PX) sample *= 2
        val opts = BitmapFactory.Options().apply { inSampleSize = sample }
        return context.contentResolver.openInputStream(uri).use { BitmapFactory.decodeStream(it, null, opts) }
    }

    private fun videoFrame(context: Context, uri: Uri): Bitmap? {
        val mmr = MediaMetadataRetriever()
        return try {
            mmr.setDataSource(context, uri)
            if (Build.VERSION.SDK_INT >= 27) mmr.getScaledFrameAtTime(0, MediaMetadataRetriever.OPTION_CLOSEST_SYNC, MAX_PX, MAX_PX)
            else mmr.frameAtTime
        } finally {
            runCatching { mmr.release() }
        }
    }
}
