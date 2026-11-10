package com.hrmediasports.studio

import com.hrmediasports.studio.domain.InMemoryZoneTemplateStore
import com.hrmediasports.studio.domain.NormRect
import com.hrmediasports.studio.domain.ZoneId
import com.hrmediasports.studio.domain.ZoneTemplate
import com.hrmediasports.studio.ui.reader.ReaderViewModel
import com.hrmediasports.studio.ui.reader.TemplateStatus
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Test

class ReaderTest {
    @Test fun moveStaysInsideFrameAndKeepsSize() {
        val r = NormRect(0.1f, 0.1f, 0.3f, 0.3f).moved(5f, -5f)
        assertEquals(0.2f, r.width, 1e-4f)
        assertEquals(0.2f, r.height, 1e-4f)
        assertEquals(1f, r.right, 1e-4f)
        assertEquals(0f, r.top, 1e-4f)
    }

    @Test fun scaleClampsToMinAndFrame() {
        val small = NormRect(0.4f, 0.4f, 0.5f, 0.5f)
        val tiny = (1..30).fold(small) { acc, _ -> acc.scaled(0.5f) }
        assertTrue(tiny.width >= NormRect.MIN_SIZE - 1e-4f)
        val huge = (1..30).fold(small) { acc, _ -> acc.scaled(2f) }
        assertTrue(huge.left >= 0f && huge.right <= 1f && huge.top >= 0f && huge.bottom <= 1f)
    }

    @Test fun rejectsInvalidRect() {
        assertThrows(IllegalArgumentException::class.java) { NormRect(0.5f, 0.5f, 1.2f, 0.9f) }
        assertThrows(IllegalArgumentException::class.java) { NormRect(0.5f, 0.5f, 0.51f, 0.9f) }
    }

    @Test fun encodeDecodeRoundTrip() {
        val t = ZoneTemplate.Default.with(ZoneId.Home, NormRect(0.123f, 0.2f, 0.456f, 0.5f))
        assertEquals(t, ZoneTemplate.decode(t.encode()))
    }

    @Test fun decodeRejectsGarbageWithoutThrowing() {
        assertNull(ZoneTemplate.decode(null))
        assertNull(ZoneTemplate.decode(""))
        assertNull(ZoneTemplate.decode("basura"))
        assertNull(ZoneTemplate.decode("home=0.1,0.1,0.2,0.2"))                      // faltan zonas
        assertNull(ZoneTemplate.decode(ZoneTemplate.Default.encode().replace("0.15", "9")))  // fuera de rango
    }

    @Test fun viewModelEditsAndSavesTemplate() {
        val store = InMemoryZoneTemplateStore()
        val vm = ReaderViewModel(store)
        assertEquals(TemplateStatus.Default, vm.state.value.templateStatus)

        vm.moveSelected(0.1f, 0.1f)                      // sin modo ajuste: se ignora
        assertEquals(ZoneTemplate.Default, vm.state.value.zones)

        vm.toggleEditing()
        assertEquals(ZoneId.Home, vm.state.value.selectedZone)
        vm.selectZone(ZoneId.Clock)
        vm.moveSelected(0.05f, 0f)
        assertEquals(TemplateStatus.Unsaved, vm.state.value.templateStatus)
        assertNull(store.load())

        vm.saveTemplate()
        assertEquals(TemplateStatus.Saved, vm.state.value.templateStatus)
        assertEquals(vm.state.value.zones, store.load())
        assertNotNull(vm.state.value.message)

        // Un ViewModel nuevo recupera la plantilla guardada.
        val again = ReaderViewModel(store)
        assertEquals(TemplateStatus.Saved, again.state.value.templateStatus)
        assertEquals(vm.state.value.zones, again.state.value.zones)

        vm.resetZones()
        assertEquals(TemplateStatus.Unsaved, vm.state.value.templateStatus) // difiere de lo guardado
    }

    @Test fun failedSaveIsReportedAndStaysUnsaved() {
        val vm = ReaderViewModel(InMemoryZoneTemplateStore(failSaves = true))
        vm.toggleEditing()
        vm.resizeSelected(1.1f)
        vm.saveTemplate()
        assertEquals(TemplateStatus.Unsaved, vm.state.value.templateStatus)
        assertEquals("No se pudo guardar la plantilla.", vm.state.value.message)
    }

    @Test fun sendIsNotAvailableInDemo() {
        val vm = ReaderViewModel(InMemoryZoneTemplateStore())
        val s = vm.state.value
        assertTrue(s.isDemo)
        assertFalse(s.canSend)
        assertFalse(s.canRecognize)
        vm.onStartSendClick()
        assertNotNull(vm.state.value.message)
    }
}
