# Ejemplos ficticios de la ronda 5

Generados por el propio código (rutas `/caja/comprobante`, `/caja/exportar?formato=csv|xlsx`) sobre la base de pruebas del QA, con datos inventados (clientes «Clienta …», correos `@example.test`). No contienen datos reales ni secretos.

* `comprobante_ejemplo.html`: comprobante interno CI-000077 (imprimible). Dice «no es factura autorizada por el SRI».
* `caja_ejemplo.csv`: exportación de caja del día en CSV (UTF-8 con BOM).
* `caja_ejemplo.xlsx`: la misma exportación en XLSX real (se abre con openpyxl y LibreOffice; importes numéricos exactos, sin fórmulas).
