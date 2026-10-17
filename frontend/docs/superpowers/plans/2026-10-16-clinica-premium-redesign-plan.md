# Clínica Premium Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rediseñar integralmente la aplicación móvil odontológica sin modificar contratos Laravel ni funcionalidad clínica.

**Architecture:** Un sistema de tokens y componentes React Native concentra todas las decisiones visuales y de layout. Las rutas consumen esos componentes y conservan sus servicios actuales; un selector nativo encapsula la única dependencia nueva de producto.

**Tech Stack:** Expo SDK 57, React Native 0.86, Expo Router, Safe Area Context, Material Community Icons, DateTimePicker, Jest y React Native Testing Library.

**Spec:** `frontend/docs/superpowers/specs/2026-10-16-clinica-premium-redesign.md`

## Global Constraints

- Escribir únicamente bajo `frontend/` y permanecer en rama `frontend`.
- No cambiar endpoints, payloads, parsers ni permisos de Laravel.
- No datos simulados en runtime ni recursos externos configurables.
- No migraciones mayores, `npm audit fix --force`, git add, commit, push o merge.
- No afirmar prueba visual en Android real si no se ejecutó.

## Review Focus

- Un Android con navegación de tres botones mantiene visible el CTA final del registro.
- Teclado abierto permite alcanzar el campo y CTA activos en pantallas pequeñas.
- Pantallas tab no duplican inset inferior entre contenido y tab bar.
- Cancelar el selector de fecha conserva el valor y elegir una fecha emite `YYYY-MM-DD`.
- Estados loading/disabled impiden doble envío sin perder contraste ni etiqueta accesible.

---

### Task 1: Concepto, tokens y primitivas adaptativas

**Files:**
- Modify: `src/constants/theme.ts`
- Refactor: `src/components/ui/patient-ui.tsx`
- Create: `src/components/ui/date-field.tsx`
- Test: `src/components/ui/__tests__/patient-ui.test.tsx`

**Interfaces:** Produces `AppScreen`, `AppHeader`, `AuthHeader`, fields, buttons, cards, rows, badges, chips and states consumed by Tasks 2–4.

- [ ] Generar conceptos coordinados de pantallas públicas y privadas y extraer inventario visual.
- [ ] Instalar DateTimePicker con `npx expo install`.
- [ ] Escribir pruebas fallidas para CTA bloqueado/accesible, fecha ISO/cancelación e inset ownership.
- [ ] Implementar tokens y primitivas mínimas; ejecutar pruebas hasta verde.
- [ ] Ejecutar TypeScript y lint.

### Task 2: Pantallas públicas

**Files:**
- Modify: `src/app/(public)/*.tsx`, `src/app/(public)/_layout.tsx`
- Test: `src/app/(public)/__tests__/public-screens.test.tsx`

**Interfaces:** Consumes Task 1 components; preserves `useAuth`, auth services and registration payload.

- [ ] Escribir pruebas fallidas de navegación atrás, organización de acciones y fecha seleccionada.
- [ ] Rediseñar login, registro, recuperación y restablecimiento.
- [ ] Verificar pantalla pequeña y teclado mediante pruebas de estructura y props.
- [ ] Ejecutar pruebas, TypeScript y lint.

### Task 3: Shell privado, tabs e inicio/citas

**Files:**
- Modify: `src/app/(private)/_layout.tsx`, `src/app/(private)/(tabs)/_layout.tsx`, `index.tsx`, `appointments.tsx`

**Interfaces:** Consumes Task 1 components; preserves appointment GET/POST behavior.

- [ ] Escribir pruebas fallidas para estado activo y acción de cita bloqueada.
- [ ] Implementar tab bar con inset dinámico e inicio profesional.
- [ ] Rediseñar citas sin añadir campos ni endpoints.
- [ ] Ejecutar pruebas, TypeScript y lint.

### Task 4: Historial, perfil y catálogo

**Files:**
- Modify: `src/app/(private)/(tabs)/history.tsx`, `profile.tsx`, `services.tsx`

**Interfaces:** Consumes Task 1 components; preserves history/profile/photo/catalog services.

- [ ] Escribir pruebas fallidas para secciones clínicas, chips seleccionados y acciones accesibles de foto.
- [ ] Rediseñar historial agrupado, perfil y catálogo.
- [ ] Ejecutar pruebas, TypeScript y lint.

### Task 5: QA integral y documentación

**Files:**
- Create: `docs/2026-10-16-clinica-premium-qa.md`
- Modify tests only where visual structure intentionally changed.

- [ ] Ejecutar suite completa y revisar regresiones funcionales.
- [ ] Ejecutar Expo Doctor, TypeScript, ESLint y export Android.
- [ ] Ejecutar QA visual disponible; comparar conceptos y capturas si existe renderer compatible.
- [ ] Revisar `git diff --check`, rama, alcance y ausencia de commits.
- [ ] Documentar verificaciones Android pendientes y resultados.
