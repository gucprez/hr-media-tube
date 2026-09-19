<#
    Lista todos los monitores que Windows detecta actualmente, con su posición
    y tamaño en píxeles. Úsalo DESPUÉS de instalar un monitor virtual (dummy)
    para saber qué -OffsetX/-OffsetY/-Width/-Height pasarle a start-windows.ps1,
    de forma que se capture SOLO ese monitor virtual y no la pantalla real de
    tu laptop.

    Uso:
      .\list-monitors.ps1
#>

Add-Type -AssemblyName System.Windows.Forms

$i = 0
foreach ($screen in [System.Windows.Forms.Screen]::AllScreens) {
    $i++
    $b = $screen.Bounds
    $primary = if ($screen.Primary) { " (principal / pantalla del laptop, normalmente)" } else { "" }
    Write-Host "Monitor $i$primary"
    Write-Host "  DeviceName : $($screen.DeviceName)"
    Write-Host "  OffsetX    : $($b.X)"
    Write-Host "  OffsetY    : $($b.Y)"
    Write-Host "  Width      : $($b.Width)"
    Write-Host "  Height     : $($b.Height)"
    Write-Host ""
}

Write-Host "El monitor virtual que agregaste (dummy plug) suele ser el que NO es 'principal'."
Write-Host "Usa sus valores OffsetX/OffsetY/Width/Height con start-windows.ps1, por ejemplo:"
Write-Host "  .\start-windows.ps1 -OffsetX 1920 -OffsetY 0 -Width 1920 -Height 1080"
