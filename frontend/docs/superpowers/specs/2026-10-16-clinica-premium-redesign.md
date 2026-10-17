# Rediseño móvil «Clínica Premium»

## Propósito

Elevar toda la aplicación del paciente a calidad visual comercial odontológica, con especial atención a Android real, teléfonos pequeños, teclado e insets. El rediseño cambia presentación y navegación visual, no contratos HTTP, permisos clínicos ni fuentes de datos.

## Dirección visual

- Fondo blanco y gris azulado frío; títulos azul marino; acción principal azul clínico; turquesa reservado para estados positivos.
- Geometría sobria: radios de 12, 16 y 20; bordes finos; elevación Android discreta; sin decoraciones o gradientes superfluos.
- Tipografía del sistema con jerarquías explícitas para display, título, sección, cuerpo, etiqueta y caption.
- Iconografía consistente de Material Community Icons, usando contenedores solo cuando aclaren una acción.
- Densidad compacta y respirable: evitar tanto formularios interminables como superficies vacías.

## Sistema de diseño

`src/constants/theme.ts` será la única fuente de colores, espaciado, radios, alturas, tipografía, elevación y opacidades. `src/components/ui/` expondrá:

- `AppScreen`: área segura dinámica; variantes tab, stack y auth; scroll; teclado; padding inferior que usa exactamente una fuente de inset.
- `AppHeader` y `AuthHeader`: títulos, texto de apoyo, icono odontológico y navegación atrás.
- `Field`, `PasswordField` y `DateField`: etiqueta, icono, error, foco, autofill y área táctil accesible.
- `AppButton`, `IconButton`, `TextLink`, `SurfaceCard`, `InfoRow`, `StatusBadge`, `CategoryChip`, `Notice`, `LoadingState` y `EmptyState`.

Los controles tendrán altura mínima de 48 dp y etiquetas accesibles. Disabled usará colores propios, no únicamente opacidad.

## Adaptación Android

- El root incluye `SafeAreaProvider` con métricas iniciales.
- Las pantallas stack consumen top/bottom; las pantallas tab dejan el bottom al tab bar para evitar doble padding y añaden solo separación interna de contenido.
- La barra inferior calcula su altura desde `useSafeAreaInsets()`; no usa altura fija para ocultar contenido.
- Formularios usan `KeyboardAvoidingView`, `keyboardShouldPersistTaps`, `keyboardDismissMode` y padding inferior dinámico.
- Registro conserva el botón dentro del contenido desplazable y siempre alcanzable.
- El selector de fecha usa `@react-native-community/datetimepicker` 9.1.0 instalado por Expo, emite `YYYY-MM-DD`, impide fechas futuras y no cambia el contrato Laravel.

## Pantallas públicas

- Login: marca compacta, formulario principal dentro de una superficie limpia y enlaces agrupados; sin hero sobredimensionado.
- Registro: encabezado con volver; secciones “Datos personales”, “Contacto” y “Seguridad”; fecha nativa; densidad adecuada para pantallas pequeñas.
- Recuperación/restablecimiento: encabezado con volver, pasos claros, mensajes integrados y un único CTA primario.

## Pantallas privadas

- Inicio: saludo compacto y accesos reales a citas, historial y catálogo; no métricas inventadas.
- Citas: CTA destacado, tarjetas con jerarquía fecha/estado/hora, vacío y error profesionales. La solicitud sigue siendo el POST sin body existente.
- Historial: secciones clínicas agrupadas en lugar de una tarjeta por campo; solo lectura.
- Perfil: cabecera de identidad, foto con acción clara, datos en filas y acciones de sesión/foto separadas por intención.
- Servicios: chips con estado seleccionado y tarjetas con categoría, duración y precio reales.
- Tabs: iconos y etiquetas uniformes, estado activo claro e inset Android dinámico.

## Estados, accesibilidad y calidad

Todos los flujos preservan loading, error, éxito y vacío. Los botones anuncian rol/estado, campos exponen label y error, iconos decorativos no duplican lectura y el contraste se evalúa con objetivos WCAG AA. ISO/IEC 25010 se usa como referencia para usabilidad, accesibilidad, mantenibilidad y compatibilidad, sin afirmar certificación.

## Verificación

Pruebas de componentes cubrirán botón bloqueado, selector de fecha, navegación atrás y layout/insets en los límites propios. Se ejecutarán TypeScript, ESLint, Jest, Expo Doctor, export Android y revisión Git. La verificación visual en teléfono se declarará pendiente si no existe dispositivo/ADB accesible.

