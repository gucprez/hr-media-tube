# Resultados de la prueba Cámara remota → Laptop

Los resultados se guardan **separados por origen** para que nunca se confundan:

| Carpeta | Qué contiene | Vale como evidencia del producto |
|---------|--------------|----------------------------------|
| [`reales/`](reales/) | Corridas con un **teléfono Android real** y una **laptop real** | Sí |
| [`sinteticos/`](sinteticos/) | Cámara simulada en el entorno de desarrollo y datos generados para probar las herramientas | **No**: solo demuestran que el sistema y las herramientas funcionan |

El analizador obliga a declarar el origen (`--origen real` o `--origen sintetico`) y lo imprime en la primera línea del informe.
Las pruebas automáticas del CI (unitarias y de integración) no son resultados de la prueba: comprueban el código, no un dispositivo.
