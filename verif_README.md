# Verificación de AROME vs estación de Béjar

Sistema que archiva la previsión de **AROME HD** (Open-Meteo) para cada hora objetivo
capturada con **−48 h** y **−24 h** de antelación y, cuando la estación Ecowitt de Béjar
da el dato real de esa hora, cruza previsto-vs-observado y muestra las **desviaciones del
modelo** en la pestaña *Verificación* de la web.

Solo **mide y almacena**. No corrige AROME ni toca `estacion_sesgos.json` (que es la
calibración del sensor, una capa distinta e independiente).

## Piezas

| Fichero | Rol | Dónde corre |
|---|---|---|
| `verif_captura.php` | Cron horario: captura AROME (48h/24h) + observación y la archiva | Hostinger (PHP + cron) |
| `verif.php` | Endpoint de lectura: recalcula sesgo/MAE/RMSE por variable × horizonte | Hostinger (PHP) |
| `index.html` (pestaña Verificación) | Panel: tarjetas de sesgo + tabla de casos recientes | Web |
| `verif_data_YYYYMM.json` | Almacén (lo crea el cron, un fichero por mes) | Fuera de `public_html` |

Punto de muestreo AROME: **40.38520882243841, −5.764519810615559**.
Variables: temperatura, humedad, viento, racha, precipitación. (Presión y nubosidad no
las da AROME HD por Open-Meteo.) Unidades ya coinciden con la estación (°C, %, mm, km/h).

## Modelo de datos

Cada valor se guarda bajo su **hora objetivo** con la etiqueta del horizonte con que se
capturó. Ejemplo: la previsión para el 25 sep 10:00...
- ...capturada el 23 sep ~10:00 → `["2026-09-25T10:00"].f48`
- ...capturada el 24 sep ~10:00 → `["2026-09-25T10:00"].f24`
- la observación real de esa hora → `["2026-09-25T10:00"].obs`

Un solo fichero mensual `verif_data_YYYYMM.json` en el padre de `public_html` (mismo
patrón de almacenamiento que `estacion.php`). El sesgo se define como **previsto −
observado** (positivo = AROME sobreestima).

## Despliegue

1. Subir a Hostinger `verif_captura.php` y `verif.php` (misma vía que `estacion.php`:
   Git deploy del panel o FTP, en `public_html`).
2. Publicar el `index.html` con la pestaña *Verificación* (push al repo → despliegue habitual).
3. hPanel → **Avanzado → Cron Jobs** → añadir un trabajo **cada hora**:

   ```
   php /home/USUARIO/domains/westmeteo.com/public_html/verif_captura.php
   ```

   (Alternativa vía HTTP si no hay php-cli: `wget -q -O /dev/null "https://westmeteo.com/verif_captura.php?run=1"`.)

   Programación sugerida: minuto **5** de cada hora (`5 * * * *`).

## Prueba manual (desde el móvil, fuera del firewall de SAP)

- Disparar la captura: abrir `https://westmeteo.com/verif_captura.php?run=1`
  → devuelve JSON con lo que ha guardado (`obs` de la hora actual y, si AROME ya tiene
  el dato, `f24`/`f48` de las horas objetivo +24h/+48h).
- Repetir un par de veces (en horas distintas) y comprobar con `https://westmeteo.com/verif.php`
  que devuelve casos.
- Ver el panel: westmeteo.com → pestaña **Verificación**.

Los pares completos a −24h aparecen a las ~24 h de arrancar el cron y los de −48h a las
~48 h; hasta entonces el panel muestra los casos parciales que haya.

## Notas

- La precipitación observada se deriva del acumulado diario `Hoy` de la estación:
  `max(0, Hoy(t) − Hoy(t−1h))`, con el reset de medianoche tratado.
- `verif_captura.php?run=1` es un disparo idempotente por hora: reejecutarlo en la misma
  hora solo reescribe los mismos slots.
- Desde la red de SAP no se puede probar contra westmeteo.com (el firewall bloquea el
  dominio nuevo); la validación en vivo se hace desde el móvil.
