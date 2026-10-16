# Instrucciones del proyecto

## Flujo de ramas (regla permanente, desde el 2026-10-16)

- **`develop` es la rama de integración.** Todo PR de feature o fix sale de `origin/develop` y
  apunta a `develop` (`gh pr create --base develop`). Nunca se abren PR de trabajo contra `main`.
- **`main` es producción**: cada push despliega en App Platform y aplica las migraciones
  (`PRE_DEPLOY migrate --force`). A `main` solo llega un **PR de release** `develop → main`,
  mergeado con *merge commit* (no squash ni rebase, para que `develop` y `main` no diverjan).
- **Hotfix urgente de producción:** rama desde `origin/main` y PR a `main`. Después del merge,
  se abre un PR `main → develop` para que `develop` no lo pierda.
- Antes de hacer push, `git branch -vv`: la rama de trabajo no debe seguir a `origin/main`.
- Detalle y comandos: `docs/MANUAL_DESARROLLADOR.md` § Git.

## Mantenimiento de documentación (regla permanente)

Al finalizar cualquier feature, fix o cambio estructural (nuevo módulo, cambio de arquitectura, nueva tabla, endpoint nuevo, decisión de diseño relevante), **antes de considerar el trabajo terminado**, revisa y actualiza los archivos correspondientes en `/docs`:

- **ARQUITECTURA.md** → si cambia la estructura del sistema, servicios nuevos, o el diseño de algún módulo (ej. el sistema de plantillas de documentos, el pipeline de sanitización).
- **BASE_DATOS.md** → si hay migraciones nuevas, columnas agregadas, relaciones nuevas o cambios de esquema.
- **API_REFERENCE.md** → si hay endpoints nuevos, cambios en request/response, o nuevos headers de contrato (ej. `X-Template-Warnings`).
- **MANUAL_DESARROLLADOR.md** → si cambia algo que afecta cómo un desarrollador nuevo entendería o extendería el código (ej. cómo funciona el sanitizer, cómo se agregan placeholders nuevos).
- **MANUAL_USUARIO.md** → si cambia algo visible para el usuario final (ej. nueva opción en Configuración, nuevo checkbox de "Modo avanzado").
- **BITACORA_TECNICA.md** → registro cronológico de qué se hizo, cuándo, y por qué (decisiones de diseño, bugs encontrados y su causa raíz, deuda técnica aceptada conscientemente).
- **MEJORAS_RECOMENDADAS.md** → cualquier deuda técnica identificada pero no resuelta (ej. la nota sobre placeholders cross-type que se blanquean sin warning).
- **BLOQUEO_MOROSOS_MANUAL.md** → solo si el cambio afecta directamente esa lógica de negocio específica.

No actualices un archivo solo por actualizarlo — si un cambio no afecta esa categoría, no la toques. Pero si terminas cualquier feature sin haber revisado esta lista, considera el trabajo incompleto, no solo el código.

Al final de cada resumen de implementación, incluye una sección corta **"Documentación actualizada"** listando qué archivos de `/docs` se tocaron y por qué, o **"Ninguno aplica"** si el cambio no lo requería.
