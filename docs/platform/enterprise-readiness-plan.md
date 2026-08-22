# Plan de enterprise readiness

Status: en ejecución
Scope: `nubit-symfony`, `nubit-react`, `nubit-skeleton`
Origen: auditoría de los tres repos (2026-08-21)

Complementa —no sustituye— a [`saas-platform-roadmap.md`](saas-platform-roadmap.md).
Aquel RFC cubre capacidades de plataforma SaaS (privacidad, observabilidad,
outbox, flags). Este plan cubre lo que hoy separa al stack de ser vendible como
producto ERP profesional: **corrección de dominio y cobertura de verificación**.

## Principio de secuenciación

Los ítems 1–3 cambian la categoría del producto. Los ítems 4–6 son acabado
necesario para cerrar auditorías de compra. Se ejecutan en orden porque cada uno
apoya al siguiente: sin arnés de integración (1) no se puede verificar dinero ni
permisos; sin dinero correcto (2) los documentos impresos (3) mienten.

**No se construye un generador de código (`make:`).** El scaffolding lo hacen
agentes de IA sobre el contrato existente; toda decisión de diseño de este plan
debe optimizar para que un agente pueda descubrir la capacidad desde atributos,
configuración y documentación de la API — no para ergonomía de CLI interactiva.

---

## 1. Arnés de verificación con Postgres real

**Problema.** Los 99 tests del monorepo son unitarios. No existe un solo
`KernelTestCase`/`WebTestCase`. Lo que no puede cubrir un unit test es
exactamente la superficie de mayor riesgo: aislamiento del `TenantFilter`, el SQL
que emite `DataGridFilter`, el ciclo completo JWT cookie/Bearer + refresh, y el
cableado del contenedor de cada módulo opt-in del `admin-bundle`.

Un fallo de aislamiento multi-tenant es un evento de fin de empresa. Hoy no hay
prueba que lo descarte.

**Entregable.**

- `packages/*/tests/Integration/` con un kernel de test mínimo por bundle,
  arrancado sobre Postgres real (contenedor efímero, no SQLite: el bundle usa
  filtros de Doctrine, `pg_dump` y schemas de PostgreSQL).
- Suite `integration` separada en `phpunit.xml.dist`, para poder correr la suite
  unitaria sin Docker.
- Casos mínimos obligatorios:
  - **Aislamiento de tenant en las 4 estrategias** (column, database, schema PG,
    híbrida): dos tenants con datos, cada uno consulta y no ve al otro; incluida
    la fuga por relación (`JOIN` hacia una entidad tenant-owned) y por
    `find()` directo por ID ajeno.
  - **Auth**: login → cookie + Bearer, refresh rotativo, reuso de refresh token
    revocado, logout, expiración.
  - **`DataGridFilter`**: `sort`/`filter`/`searchValue` contra tablas reales,
    incluyendo tipos date, enum, relación y `searchValue` sobre varias columnas.
  - **DI de módulos**: cada módulo opt-in (media, audit, export, notification,
    backup, analytics, oidc) arranca en on y en off sin romper el contenedor ni
    mapear entidades que la aplicación no usa.
  - **Migraciones**: `doctrine:schema:validate` sobre el schema generado.
- CI: job `integration` con servicio Postgres, en la misma matriz PHP.

**Criterio de aceptación.** El job de integración falla si se introduce una
regresión de aislamiento. Se verifica inyectando deliberadamente el fallo una vez.

### Estado

**Completo.** 63 tests de integración sobre PostgreSQL real, más el runner local
(`composer test-integration`) y el job de CI en la matriz PHP 8.3/8.5:

| Suite | Cubre |
| --- | --- |
| `Tenant/ColumnIsolationTest` | filtro Doctrine por `tenant_id`: listado, DQL, `find()` por clave ajena, `JOIN`, fail-closed, allowlist, raíz de tenant |
| `Tenant/SchemaIsolationTest` | `search_path` por tenant, reset tras la respuesta, fallback a `base_schemas`, rechazo de id no positivo |
| `Tenant/DatabaseIsolationTest` | base por tenant, retorno a la base de control, ausencia de contaminación entre peticiones, tenant sin URL |
| `Tenant/HybridIsolationTest` | enrutado por fila de tenant, alternancia column↔database, rechazo de placement schema |
| `Auth/JwtAuthenticationTest` | login, flags de cookie, Bearer y cookie, rotación de refresh, rechazo de replay, logout, token forjado |
| `ApiPlatform/DataGridFilterTest` | 12 operadores de grid, orden numérico sobre decimal, filtro compuesto, búsqueda global sobre tipos mixtos, parámetros malformados |
| `AdminBundle/ModuleWiringTest` | cada módulo opt-in encendido y apagado, todos a la vez, canal de email con y sin mailer |
| `AdminBundle/SchemaValidityTest` | `validateMapping()` y esquema en sync tras crearlo |

La suite unitaria queda excluida del job de integración y viceversa
(`composer test` sigue corriendo sin Docker). `IntegrationTestCase` **falla** en
vez de saltar cuando `NUBIT_TEST_DATABASE_URL` no está y `CI` sí: una suite de
aislamiento saltada en silencio es indistinguible de una que pasa.

Verificación de la verificación: se inyectaron dos regresiones en `TenantFilter`
—fail-open para entidades no marcadas, y predicado de tenant vacío— y la suite
las detectó, incluidas las fugas por `find()` con clave ajena y por `JOIN`.

### Defectos encontrados al arrancar contenedores reales

Ninguno era observable con tests unitarios; todos se corrigieron.

1. **`isolation: schema` no compilaba.** `TenantRoutingConnectionSwitcher` recibe
   `$columnSwitcher` tipado como `TenantConnectionSwitcherInterface`, que es
   precisamente el alias de sí mismo: referencia circular. Solo `hybrid` pasaba
   el argumento explícitamente. Ahora se enlaza siempre.
2. **`schema` y `database` tampoco autowireaban.** Ambos switchers piden
   `Doctrine\Persistence\ConnectionRegistry`, que DoctrineBundle nunca aliasea.
   Se enlaza `service('doctrine')` explícitamente.
3. **`notification` rompía el contenedor con mailer instalado pero sin
   configurar.** El guard era `interface_exists(MailerInterface::class)` —
   instalado no es lo mismo que configurado — y el fallo salía como un error de
   autowiring que no mencionaba notificaciones. Se añadió
   `RemoveEmailChannelWithoutMailerPass`, que decide con el contenedor ya
   compilado.
4. **`admin-bundle` no arranca sin `ApiPlatformBundle`** (decora
   `api_platform.hydra.normalizer.documentation` incondicionalmente). Es el modo
   de instalación previsto, así que queda fijado como dependencia deliberada en
   lugar de descubrirse en producción.
5. **Un booleano en un filtro de grid devolvía HTTP 500.**
   `GridFilterHelper::valueForOperator()` declaraba `string|int|float|null`, y
   `["paid","=",true]` —lo que envía cualquier columna de checkbox— provocaba un
   TypeError. El tipo de retorno ahora admite `bool`.
6. **Un nombre de campo desconocido devolvía HTTP 500.** `filter`, `sort` y
   `searchExpr` llegan de la query string, así que cualquier cliente podía
   provocar un error semántico de Doctrine a voluntad. Los campos que el recurso
   no tiene se descartan, en línea con la política ya documentada para
   parámetros malformados y con lo que hacen los filtros propios de API
   Platform. Un test unitario fijaba el comportamiento anterior —envolver el
   campo en `CONCAT` y pasarlo igualmente— que solo parecía funcionar contra
   metadata falsa: `o.desconocido` es un error de DQL con o sin el cast.

Además queda pinchado, sin cambiar el comportamiento, que en aislamiento por
schema un tenant sin schema propio resuelve contra `base_schemas` en vez de
fallar: correcto según PostgreSQL, y la razón por la que los schemas base nunca
deben contener tablas de tenant.

---

## 2. Dinero decimal y política de tiempo

**Problema.** No existe tipo `Money`. El backend expone `numeric` como string, el
frontend formatea moneda pero no hay camino decimal seguro extremo a extremo: en
cuanto un total pasa por un `number` de JavaScript, el ERP empieza a desangrarse
por céntimos. Tampoco hay política de zona horaria para un ERP multi-país.

**Entregable.**

- `Nubit\Platform\Money\Money` — value object entero (`amount` en unidades
  mínimas + `currency` ISO-4217 + escala), aritmética exacta, redondeo explícito
  por política (`HALF_UP`, `HALF_EVEN`, truncado), prohibición de operar entre
  monedas distintas.
- Tipo Doctrine + tipo API Platform que serializa a `{ amount, currency, scale }`
  y publica `x-crud: { format: 'money' }` en la doc, para que el frontend y los
  agentes lo descubran.
- Campo `money` en `@nubitio/crud` que opera sobre enteros y string decimal —
  nunca `number` — con formateo por locale y entrada con separadores locales.
- Sumas y totales de grid (`SummaryUtils`) y de XLSX en aritmética exacta.
- Política de tiempo: almacenamiento UTC obligatorio, render en zona de tenant o
  de usuario, resuelta en un único punto (`TimeZoneResolver`), expuesta al
  frontend en `/api/me` y aplicada por defecto en campos date/datetime.

**Criterio de aceptación.** Test de propiedad: para cualquier secuencia de
operaciones sobre líneas de un documento, total del backend == total mostrado en
grid == total en el XLSX == total en el PDF, sin desviación.

### Estado

**Entregado, salvo la parte PDF** (depende del pipeline de documentos, ítem 3).

Backend:

- `Nubit\Platform\Money\{Money, Currency, RoundingMode}` — aritmética entera
  exacta, sin bcmath ni gmp: el overflow se detecta y se reporta en vez de
  degradar a float. Toda operación que puede perder precisión exige un
  `RoundingMode`; `Unnecessary` lanza en lugar de redondear a espaldas de quien
  llama. `allocate()` reparte sin perder ni inventar céntimos.
- Escala por moneda desde ISO-4217 (JPY 0, KWD 3, …), con override explícito
  para monedas fuera del estándar. Mismo código con distinta escala **no** es la
  misma moneda: combinarlas se rechaza.
- `MoneyColumns` (embeddable): `bigint` de unidades menores + código + escala.
  Tres columnas y no una cadena, porque un ERP tiene que hacer `SUM` y comparar
  en SQL. La fila es autodescriptiva: releerla no depende de la tabla de monedas
  que la aplicación tuviera el día que se escribió.
- `MoneyNormalizer`: el importe viaja como **string**. Un número JSON es un
  double en cualquier runtime de JavaScript, y publicarlo como número desharía
  la exactitud justo en el último paso.
- `MoneyPropertyMetadataFactory`: publica `x-crud.format: money` derivándolo del
  tipo declarado, así que el frontend y los agentes lo descubren del contrato
  sin nada que recordar ni mantener sincronizado.
- `XlsCellWriter` escribe importes como celda numérica con el formato de la
  escala. Antes caían en el aplanado genérico y salían como JSON: técnicamente
  todos los datos, e inútil — lo primero que hace quien abre un export es
  seleccionar la columna y leer el total.

Tiempo:

- `TimeZoneResolver` con cadena usuario → tenant → configuración → UTC, y
  fallback registrado en el log ante identificadores inválidos.
- `UtcDateTimeImmutableType` (activo por defecto, `time.enforce_utc`) escribe y
  **lee** en UTC. La lectura es la mitad que se olvida: el tipo estándar de
  Doctrine parsea en la zona local de PHP, así que un servidor fuera de UTC
  desplaza en silencio todo lo que carga.
- `/api/me` reporta la zona resuelta, que es como el frontend aprende en qué
  zona formatear.

Frontend:

- `@nubitio/core` money: aritmética en `BigInt` a partir del string decimal,
  nunca desde `minorAmount` — más allá de 2^53 unidades menores ese número ya es
  aproximado. Formateo con Intl a partir del literal, y parseo de entrada con
  los separadores del locale activo.
- `FieldType.MONEY` + `moneyField()`: el control edita texto y devuelve
  `{amount, currency}`. Nunca hay un `Number(value)` en el camino.
- Regla de mapeo Hydra `format: 'money'` → `moneyField`, antes que la regla
  `currency` heredada.
- Totales de grid exactos en unidades menores, y negativa a sumar monedas
  distintas: un pie de tabla que suma euros con dólares es peor que uno vacío,
  porque parece correcto.

Verificación: la invariancia del reparto se comprueba sobre 501 importes × 5
repartos; el enforcement de UTC se validó desactivándolo y confirmando que los
tests caen; el redondeo cubre los ocho modos con empates positivos y negativos.

---

## 3. Pipeline de documentos e importación

**Problema.** `PdfExporter` es un wrapper fino de WeasyPrint: síncrono, streaming
directo, timeout 30s, sin plantillas ni versionado del documento emitido. Un ERP
que no imprime factura ni orden de compra no se vende. Y toda migración de
cliente empieza por importar datos, cosa que hoy no existe.

**Entregable — documentos.**

- Atributo `#[Printable]` sobre el recurso: plantilla, formato de papel,
  numeración del documento (se apoya en `sequence-bundle`), y publicación de la
  operación de impresión en la doc de la API para que el frontend renderice el
  botón sin configuración manual.
- Renderizado encolado (Messenger) con almacenamiento del PDF resultante en el
  Filesystem de plataforma y descarga por enlace firmado; el modo síncrono queda
  para previsualización.
- Documento emitido inmutable y auditado: reimprimir devuelve el mismo binario;
  una corrección emite un documento nuevo que referencia al anterior.
- Botón e historial de impresión en `@nubitio/crud`.

**Entregable — importación.**

- `POST /api/imports` con archivo CSV/XLSX → detección de cabeceras, mapeo de
  columnas a campos del recurso descubierto desde el mismo contrato `x-crud`,
  **dry-run obligatorio** con reporte de errores fila a fila, y confirmación
  transaccional por lotes.
- Reglas de idempotencia por clave natural (upsert declarado en el atributo).
- Pantalla de importación en el frontend, generada desde el contrato.

**Criterio de aceptación.** Importar 50k filas con 5% de errores produce un
reporte completo sin aplicar nada; confirmarla aplica exactamente las filas
válidas. Emitir e imprimir una factura con líneas, secuencia y totales cuadra con
el ítem 2.

### Estado

**Entregado.** 35 tests de integración nuevos y 14 en el frontend.

Documentos (`nubit_admin.documents`, opt-in):

- `#[Printable]` sobre el recurso: plantilla, papel, orientación, propiedad de
  numeración y si se permite corregir.
- `IssuedDocument`: registro append-only con checksum SHA-256, tamaño, emisor y
  cadena de reemplazo. **Reimprimir devuelve los bytes almacenados**, nunca
  vuelve a renderizar: un cambio de plantilla dentro de seis meses no puede
  reescribir facturas que ya están en manos de otra persona.
- Una corrección emite un documento **nuevo** que referencia al anterior; el
  anterior sigue siendo legible byte a byte. Eso es lo que pide un auditor y lo
  que es imposible reconstruir si la primera copia se sobrescribió.
- `DocumentRendererInterface` como puerto: WeasyPrint es la implementación
  incluida, pero Gotenberg o un navegador headless sustituyen un solo servicio
  sin tocar ninguna regla de emisión.
- Renderizado encolado opcional (`async: true`): emitir devuelve un documento
  `pending`, la descarga responde 202 y el worker completa. Un mensaje
  reentregado no vuelve a renderizar.
- `x-printable` publicado en la doc, así que el botón aparece desde el contrato.

Importación (`nubit_admin.imports`, opt-in):

- `#[Importable]` con campos, clave natural, obligatorios, tamaño de lote y tope
  de filas. La clave natural es lo que hace re-subible un archivo corregido:
  sin ella, arreglar una fila y volver a subir duplica todas las que ya estaban
  bien.
- **Dry-run obligatorio**: subir analiza y reporta —qué insertaría, qué
  actualizaría, y qué está mal en cada fila con su número de línea y columna—
  sin escribir nada. Confirmar aplica en una transacción; con filas inválidas se
  rechaza entero, porque una importación parcial es el peor resultado posible.
- Lectores CSV (con detección de delimitador y BOM) y XLSX en modo read-only y
  streaming.
- Coerción que **rechaza en vez de aproximar**: fechas por formatos explícitos
  con detección de desbordes (`31/02` no pasa), booleanos por vocabulario, y
  números con separadores de locale. El caso genuinamente ambiguo —`1,234`— se
  rechaza con un mensaje accionable en vez de adivinarse: leerlo mal mueve un
  importe por mil.
- `x-importable` publicado, con `requiresReview: true` declarado explícitamente
  para que ningún cliente ofrezca un botón que salte la revisión.

Frontend: `PrintButton` (imprimir y corregir como acciones distintas, la segunda
con confirmación), `DocumentHistoryPanel` (incluidas las copias reemplazadas),
`useIssuedDocument`, `ImportPanel` y `useSpreadsheetImport` — que no puede
confirmar nada que no se haya analizado antes.

Limitación conocida: la importación cubre escalares, dinero, fechas y booleanos.
**Las relaciones quedan fuera** de esta versión; un archivo con una columna de
proveedor todavía necesita código propio.

El renderizador WeasyPrint se sustituye en los tests: las reglas verificadas son
las de emisión (idempotencia, reemplazo, checksum, cola), no las del motor PDF.

---

## 4. Permisos granulares y administración de identidad

**Problema.** La autorización es solo por strings `ROLE_*`. No hay modelo
`recurso.acción`, no hay ámbito por fila más allá del tenant, no hay pantallas de
usuarios/roles. En ERP «este usuario solo ve el almacén 3» y «aprueba hasta $X»
son requisitos base. Además los permisos del frontend son explícitamente
advisory (`useFieldPermissions`: *"UI-only, backend is the real authorization
source"*), y nada garantiza hoy que ambos lados coincidan.

**Entregable.**

- Modelo de permisos `recurso.acción` (`product.create`, `invoice.approve`),
  derivado de las operaciones de API Platform declaradas — no de una lista
  paralela que se desincroniza.
- Roles como conjuntos de permisos, persistidos y administrables; `ROLE_*` sigue
  funcionando como capa de compatibilidad.
- **Ámbito por fila** declarativo: un `ScopeProvider` por recurso que restringe
  la query (almacén, sucursal, centro de coste) reutilizando la maquinaria del
  `TenantFilter`.
- Límites por permiso (aprobar hasta un monto) evaluados contra el `Money` del
  ítem 2.
- Los permisos efectivos del usuario viajan en `/api/me` y en la doc de la API;
  el frontend deja de adivinar y `usePermissions` los consume directamente.
- Pantallas de usuarios, roles y asignación, construidas sobre el propio CRUD.

**Criterio de aceptación.** El `UnguardedOperationScanner` se extiende para
fallar el build si una operación no declara permiso. Test de integración: un
usuario sin el permiso recibe 403 aunque la UI se lo hubiera mostrado.

### Estado

**Entregado.** 19 tests de integración y 8 en el frontend.

- **Catálogo derivado, no mantenido.** Las operaciones que un recurso publica
  *son* el conjunto de permisos: añadir un `Delete()` crea `factura.delete` en
  el momento en que se escribe, y quitarlo lo elimina. Una lista a mano deriva,
  y deriva en la dirección peligrosa — una operación para la que nadie pensó un
  permiso sigue siendo alcanzable por todos. Las acciones de dominio que ningún
  verbo HTTP implica (aprobar, contabilizar, anular) vienen de `#[Authorized]`,
  que es lo único que la derivación no puede ver.
- **Denegar por defecto.** `PermissionSecurityMetadataFactory` inyecta la
  expresión `security:` que cada operación implica cuando no declara ninguna.
  Sin esta pieza el catálogo sería un conjunto de cadenas que nadie evalúa, y la
  pantalla de permisos una mentira que el cliente descubre en una auditoría. Una
  `security:` explícita siempre gana.
- **Roles como datos.** «Supervisor de almacén aprueba hasta 5.000 €» es una
  decisión que el negocio cambia sin desplegar. `Role` es un `ApiResource`, así
  que la pantalla de administración es el propio motor CRUD leyendo el mismo
  contrato que todo lo demás. `ROLE_*` sigue siendo la identidad, de modo que
  una aplicación existente adopta granularidad donde la necesita y no en una
  migración de golpe.
- **Ámbito por fila** aplicado **dentro de la consulta**, en colección y en
  ítem. Restringir solo el listado deja cada fila oculta a un identificador
  adivinado de distancia, y sigue filtrando su existencia por el total. Una
  reclamación vacía no ve nada: una cuenta que nadie terminó de configurar es
  mucho más frecuente que una concesión deliberada de todo.
- **Límites por importe** evaluados en el voter contra el `Money` del ítem 2, no
  en un servicio que la API pueda saltarse. Cruce de monedas rechazado —
  convertir sería inventar un tipo de cambio, y adivinar permitiría esquivar un
  límite denominando el documento en otra moneda. Con varios roles gana el
  **límite más permisivo**: añadir un rol debe sentirse como añadir autoridad.
- `/api/me` publica los permisos efectivos y los límites; el frontend los usa
  para decidir qué ofrece, nunca qué permite. Un cliente que los ignore recibe un
  403.
- `bin/console nubit:permissions:list` (con `--json` para agentes) imprime el
  catálogo, porque leer las cadenas exactas del código es como un typo se
  convierte en un permiso que nunca se concede y nadie nota.

**Defecto encontrado al probarlo:** la expresión derivada se aplicaba también a
los recursos internos de API Platform, incluido el payload RFC-7807. Resultado:
la propia página de error 403 quedaba no autorizada y el 403 se convertía en una
excepción lanzada al renderizar el 403. El espacio de nombres del framework
queda exento.

Nota de dependencias: `enforce_by_default` genera expresiones, así que el módulo
**se niega a compilar** sin `symfony/expression-language` en vez de fallar en la
primera petición. Symfony ignora deliberadamente un paquete que solo está en
`require-dev`, así que en el monorepo va en `require`.

Fuera de alcance de esta versión: pantalla de usuarios (el bundle no es dueño de
la entidad `User`; la de roles sí se genera) y jerarquía de roles.

**Incoherencia conocida, decidida no arreglar por ahora:** Symfony expande
`security.role_hierarchy` dentro del voter, no en el objeto usuario, y
`PermissionResolver` lee `$user->getRoles()`. Una aplicación con jerarquía
configurada la ve respetada en `is_granted('ROLE_X')` pero no en la resolución de
permisos. Arreglarlo es hacer que el resolver consulte `RoleHierarchyInterface`.

---

## 5. Ciclo de vida de identidad y credenciales

**Problema.** OIDC+PKCE está resuelto, que es la parte difícil. Falta todo lo que
lo rodea: sin MFA, sin recuperación de contraseña, sin invitación de usuarios
(solo `ChangePasswordController`), sin API keys. Ningún comité de seguridad
aprueba eso.

**Entregable.**

- **TOTP** (RFC 6238) con códigos de recuperación de un solo uso, y política
  configurable: opcional, obligatorio por rol, obligatorio por tenant.
- **Recuperación de contraseña** con token de un solo uso, expiración corta,
  rate limit por identidad y por IP (reutilizando `TenantRateLimiter`), y
  respuesta ciega que no revela si la cuenta existe.
- **Invitación de usuarios**: alta por email con rol preasignado, aceptación con
  establecimiento de contraseña, expiración y revocación.
- **API keys / service accounts**: credencial no humana con permisos del ítem 4,
  hash en reposo, prefijo visible para identificación, rotación, expiración y
  registro de último uso.
- **Sesiones activas**: listado por usuario con dispositivo y última actividad, y
  revocación individual (hoy solo existe purga masiva de refresh tokens).

**Criterio de aceptación.** Tests de integración sobre cada flujo, incluyendo los
caminos de abuso: reuso de token de reset, fuerza bruta de TOTP, API key
revocada, aceptación de invitación caducada.

### Estado

**Entregado.** 18 tests unitarios de TOTP y 33 de integración, escritos en su
mayoría como casos de abuso: un reset que funciona es fácil; uno que no se puede
repetir, no se puede forzar y no sirve para averiguar quién trabaja en el cliente
es lo que se estaba construyendo.

- **TOTP (RFC 6238)** escrito a mano —treinta líneas de HMAC y un códec base32—
  y verificado contra los vectores de prueba del RFC, que es la única forma de
  saber que es interoperable y no simplemente coherente consigo mismo. Tres
  reglas viven en el gestor: un credential no está en vigor hasta que el usuario
  demuestra que puede producir un código (escanear el QR y cerrar la pestaña no
  puede dejar a nadie fuera), **un código es de un solo uso** (un código vale su
  ventana entera, así que uno observado sería reproducible durante minuto y
  medio), y un código de recuperación se consume al usarse y se guarda hasheado.
- **Política**: opcional, obligatorio por rol, u obligatorio para todos. Quien se
  enroló voluntariamente siempre lo presenta, diga lo que diga la política.
- El chequeo va en un listener con prioridad **por debajo** del de credenciales
  de Symfony, así que cuando se ejecuta la contraseña ya es correcta. Comprobarlo
  antes permitiría averiguar qué cuentas tienen segundo factor con solo un
  usuario.
- **Recuperación de contraseña** con token de un solo uso, hasheado, de vida
  corta, y **respuesta idéntica** exista o no la cuenta —ni siquiera un código de
  estado distinto—. Limitado por identidad **y** por IP: limitar solo por
  identidad deja que un host recorra una lista de direcciones; solo por IP, que
  una botnet martillee una cuenta. Pedir un segundo enlace invalida el primero.
  Completar un reset revoca todas las sesiones, porque quien resetea suele ser
  quien cree que se la robaron.
- **Invitaciones** con roles decididos al invitar y llevados en el token, así que
  la cuenta nace con la autoridad correcta en vez de pasar un día siendo un
  cascarón que nadie terminó. La alternativa —crear la cuenta y decir la
  contraseña por chat— es como empiezan las credenciales compartidas.
- **API keys** que autentican **como un principal**, de modo que permisos, ámbito
  por fila y auditoría siguen funcionando sin caso especial: una integración es
  un usuario que nunca teclea una contraseña. Hasheadas, con prefijo visible para
  identificarlas en un log o un fichero de configuración sin que sea usable, y
  rotación que emite y revoca en un solo paso.
- **Sesiones activas** construidas sobre los refresh tokens existentes —un
  refresh token *es* una sesión— con revocación individual **acotada al dueño**:
  un id de sesión es un entero pequeño, y un endpoint que revocara solo por id
  dejaría cerrar la sesión de cualquiera contando hacia arriba.

La entrega de correos es un **evento**, no una llamada al mailer: qué canal usa
un reset es decisión de la aplicación, y cablear un mailer aquí obligaría a
instalarlo para ofrecer recuperación de contraseña.

Fuera de alcance: WebAuthn/passkeys y SCIM. Ambos siguen siendo huecos reales
frente a compradores enterprise, y ninguno estaba en este ítem.

---

## 6. Escala de lectura y exportación

**Problema.** Paginación por offset con `X-Total-Count`: el `COUNT(*)` y el
`OFFSET` profundo se degradan solos en cuanto una tabla de ERP crece a tres años
de operación. Y el export XLSX corre síncrono con PhpSpreadsheet en proceso — a
200k filas es un OOM.

**Entregable.**

- Paginación **keyset/cursor** en `DataGridFilter` y en `HydraRemoteDataSource`,
  negociada por el contrato (el recurso declara si la soporta), con offset como
  camino de compatibilidad.
- Conteo aproximado o diferido para grids grandes; el conteo exacto pasa a ser
  opt-in.
- **Export encolado**: job Messenger, escritura en streaming a disco, enlace de
  descarga firmado y notificación al terminar (se apoya en el módulo
  `notification` existente). El export síncrono se conserva bajo un umbral de
  filas.

**Criterio de aceptación.** Benchmark reproducible: grid sobre 2M filas paginando
sin degradación al avanzar, y export de 500k filas en memoria acotada.

---

## Pendiente fuera de este plan

Del hallazgo original queda diferido, sin generador de código:

- `SECURITY.md`, proceso de CVE y política de soporte/LTS.
- Matriz de compatibilidad (`nubit-compatibility.json`) verificada en CI.
- Recetas end-to-end en la documentación, orientadas a consumo por agentes.
- Gate de accesibilidad automatizado (axe) y objetivo WCAG 2.1 AA.
- GDPR: exportación y borrado, retención configurable, cadena de hash en
  auditoría (hoy `PurgeAuditLogCommand` borra auditoría sin política).

## Registro de avance

| Ítem | Estado |
| --- | --- |
| 1. Arnés de verificación | completo — 63 tests, 6 defectos corregidos |
| 2. Dinero y tiempo | entregado; el tramo PDF del criterio se cierra con el ítem 3 |
| 3. Documentos e importación | entregado; relaciones en la importación quedan fuera |
| 4. Permisos granulares | entregado; pantalla de usuarios queda en la aplicación |
| 5. Ciclo de vida de identidad | entregado; WebAuthn y SCIM siguen fuera |
| 6. Escala de lectura y exportación | pendiente |
