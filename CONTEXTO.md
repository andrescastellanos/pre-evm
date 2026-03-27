# CONTEXTO — Plugin Viva Pre-EVM

## Qué es este proyecto

Plugin WordPress de archivo único (`viva-pre-evm.php`) que implementa un **Test de Pre-Evaluación Migratoria** para [Viva Australia Internacional](https://vivaaustralia.com.co). El usuario completa un formulario de 5 pasos, sube su CV en PDF, y la IA analiza su perfil migratorio para Australia (General Skilled Migration). El resultado se guarda en WordPress y se sincroniza con GoHighLevel CRM.

---

## Stack tecnológico

| Capa | Tecnología |
|---|---|
| Backend | PHP 8.0+, WordPress (plugin) |
| Frontend | HTML + CSS + JS vanilla (todo embebido en el plugin, sin bundler) |
| IA | Anthropic Claude (nativo), OpenAI GPT-4o, Google Gemini — switcher en admin |
| CRM | GoHighLevel API v2 (`services.leadconnectorhq.com`) |
| PDF cliente | jsPDF 2.5.1 (CDN) |
| Deploy | git-ftp → Hostinger FTP (local). GitHub Actions configurado pero bloqueado por Cloudflare |
| Fuente de datos | `osl_shortage_2025.json` — Occupation Shortage List 2025 del gobierno australiano (916 ocupaciones) |

---

## Archivos del plugin

```
/wp-content/plugins/viva-pre-evm/
├── viva-pre-evm.php          ← TODO el plugin (~2972 líneas)
├── osl_shortage_2025.json    ← Datos OSL 2025 (916 registros, ~223KB)
└── .github/workflows/
    └── deploy.yml            ← GitHub Actions FTP deploy (ver nota abajo)
```

**Archivo único:** el plugin no usa carpetas `includes/`, `assets/`, ni autoload. Todo — PHP, CSS, HTML, JS — está en `viva-pre-evm.php`. Esta es una decisión deliberada para simplificar el deploy.

---

## Estructura interna del PHP (por bloques)

| Líneas | Sección |
|---|---|
| 1–52 | Constantes, hooks, enqueue (jsPDF + Google Fonts) |
| 54–360 | Settings page WP Admin (switcher IA, API keys) |
| 361–690 | CPT `preevm_result` y `preevm_draft` + `viva_render_result_page()` |
| 691–705 | Registro REST routes (`viva/v1`) |
| 706–744 | Endpoints GHL: `ghl-lookup`, `ghl-upsert` |
| 745–764 | Endpoints GHL: `ghl-tag`, `ghl-note` |
| 766–965 | Endpoint `/analyze` — dispatcher multi-proveedor + OSL enrichment |
| 967–1034 | Endpoints `save-result`, `save-draft`, `get-draft` |
| 1035–1297 | Helpers PHP: `viva_ghl_request()`, OSL helpers, `viva_decode_unicode_in_array()`, rate limit, system prompt, user message |
| 1298–1630 | Shortcode `[viva_pre_evm]` — CSS completo del frontend |
| 1631–1980 | HTML del frontend (5 pasos + loading + resultado + no-apto + continuar) |
| 1981–2972 | JavaScript completo (flujo, quiz, análisis, GHL sync, PDF, shortage map) |

---

## Constantes hardcodeadas (líneas 17–20)

```php
define( 'VIVA_PREEVM_VER',  '4.0.0' );
define( 'GHL_API_KEY',      'pit-52bd3a9b-0327-451b-b56b-77cd3546083e' );
define( 'GHL_LOCATION_ID',  'eAmbGQl2QpcHDwBWM7s6' );
define( 'GHL_BASE_URL',     'https://services.leadconnectorhq.com' );
```

La API Key de GHL también es sobreescribible desde WP Admin → Settings → Viva Pre-EVM.

---

## REST API — Endpoints `viva/v1`

Todos son públicos (`permission_callback => __return_true`). Las llamadas son desde el frontend JS via `fetch()`.

| Endpoint | Método | Descripción |
|---|---|---|
| `/ghl-lookup` | POST | Busca contacto en GHL por email. Retorna `{found, contactId, firstName, lastName, phone, tags}` |
| `/ghl-upsert` | POST | Crea o actualiza contacto en GHL. Acepta `customFields[]`. Retorna `{contactId}` |
| `/ghl-tag` | POST | Aplica tags a un contacto. Requiere `contactId` |
| `/ghl-note` | POST | Agrega nota a un contacto. Requiere `contactId` |
| `/analyze` | POST | Dispatcher IA. Recibe perfil + CV base64. Retorna JSON del análisis |
| `/save-result` | POST | Guarda CPT `preevm_result` + post meta. Retorna `{resultUrl, resultId}` |
| `/save-draft` | POST | Guarda CPT `preevm_draft` con token 32 chars. Retorna `{continueUrl, token}` |
| `/get-draft` | GET | Recupera borrador por token. Retorna datos del formulario |

---

## Custom Post Types (WordPress)

### `preevm_result`
Resultado completo guardado después del análisis. URL pública: permalink del CPT.

**Post meta:**
```
_preevm_nombre, _preevm_apellido, _preevm_email, _preevm_whatsapp
_preevm_pais, _preevm_profesion, _preevm_edad, _preevm_ingles, _preevm_experiencia
_preevm_viability          → "apto" | "parcial" | "no-apto"
_preevm_puntaje            → int (pts SkillSelect)
_preevm_viabilidad_pct     → int 0-100
_preevm_competitividad_pct → int 0-100
_preevm_resultado_json     → JSON completo del análisis (todo el objeto result)
_preevm_contacto_ghl       → contactId de GHL
_preevm_timestamp          → datetime MySQL
```

### `preevm_draft`
Borrador para flujo "continuar después". Expira en 30 días.

**Post meta:**
```
_preevm_draft_token   → string 32 chars (random)
_preevm_draft_data    → JSON con todos los campos del formulario
_preevm_draft_expiry  → Unix timestamp
```

---

## GoHighLevel CRM — Custom Fields

Namespace GHL: `PRE-EVM`. Variables GHL para usar en automatizaciones/emails:

| Campo GHL | Key API | Tipo | Descripción |
|---|---|---|---|
| `{{ contact.preevm_continue_link }}` | `preevm_continue_link` | Text | URL para continuar el formulario |
| `{{ contact.preevm_result_link }}` | `preevm_result_link` | Text | URL del informe completo |
| `{{ contact.preevm_score }}` | `preevm_score` | Number | Puntaje SkillSelect estimado |
| `{{ contact.preevm_viability }}` | `preevm_viability` | Text | "apto" / "parcial" / "no-apto" |
| `{{ contact.preevm_decision }}` | `preevm_decision` | Number | Nivel de decisión 1-5 |

**Tags automáticos aplicados:**
- Siempre: `test-preevm`
- Resultado: `preevm-califica` / `preevm-parcial` / `preevm-no-califica`
- Por decisión: `lead-caliente` (≥4) / `lead-tibio` (3) / `lead-frio` (≤2)
- Inversión: `capacidad-inversion` (si declara "sí")
- Sin CV: `cv-pendiente`

---

## Flujo del usuario (5 pasos)

```
s1 (Email)
  ↓ ghl-lookup por email → precarga datos si existe en CRM
s2 (Perfil)
  Nombre, apellido, WhatsApp, país, profesión, edad, inglés, experiencia
  ↓
s3 (Quiz 🦘)
  ~10 preguntas contextuales: pareja, certificación inglés, conexión AU,
  estado civil, decisión (1-5), inversión, plazo, expAU, estudio regional
  ↓
s4 (CV)
  Upload PDF (máx 15MB) — o modo manual (campos de texto)
  Botón "Continuar después" → genera link de retorno + GHL upsert
  ↓
s-loading (Análisis IA)
  Animación 6 pasos → vpDoAnalysis() → POST /analyze
  ↓
s-result (Apto / Parcial) ← vpShowResult()
s-noapto (No apto)        ← vpShowNoApto()
s-continuar (confirmación de "continuar después")
```

---

## Análisis IA — Multi-proveedor

**Dispatcher en** `viva_rest_analyze()`:

```php
$provider = get_option('viva_ai_provider', 'anthropic');
switch ($provider) {
    case 'openai': $text = viva_call_openai(...); break;
    case 'gemini': $text = viva_call_gemini(...); break;
    default:       $text = viva_call_anthropic(...); break;
}
```

**Diferencias por proveedor:**
- **Anthropic:** PDF enviado como `source.type=base64` nativo. Modelo configurable (default: `claude-opus-4-5`).
- **OpenAI:** PDF decodificado a texto plano antes de enviar (no acepta PDF nativo). Modelo configurable (default: `gpt-4o`).
- **Gemini:** PDF como `inline_data` con `mime_type`. Modelo configurable (default: `gemini-2.0-flash`).

**Enrichment OSL:** después del análisis, cada código ANZSCO identificado se cruza con `osl_shortage_2025.json` y se agrega `$result['shortageMap']` al resultado.

**Post-procesamiento:** `viva_decode_unicode_in_array()` convierte secuencias `\uXXXX` literales que la IA puede insertar en los strings.

---

## JSON de respuesta de la IA

La IA debe retornar **JSON puro** con estos campos exactos:

```json
{
  "viability": "apto|parcial|no-apto",
  "pts": 85,
  "viaPct": 78,
  "compPct": 65,
  "nom": "María",
  "ape": "López",
  "prof": "Ingeniera Civil",
  "alcance": "...",
  "academico": "...",
  "laboral": "...",
  "anzsco": [{"code": "233211", "name": "Civil Engineer", "note": "..."}],
  "visas": ["189", "190", "491"],
  "variables": [{"icon": "briefcase", "title": "...", "desc": "..."}],
  "recomendaciones": [{"icon": "target", "texto": "..."}],
  "bloqueantes": [{"icon": "X", "titulo": "...", "desc": "..."}],
  "proximoPaso": "...",
  "desglosePuntos": {
    "edad": {"puntos": 30, "detalle": "25-32 años"},
    "ingles": {"puntos": 10, "detalle": "Proficient"},
    "experienciaOffshore": {"puntos": 10, "detalle": "5-7 años"},
    "experienciaOnshore": {"puntos": 0, "detalle": "Sin exp. en AU"},
    "educacion": {"puntos": 15, "detalle": "Bachelor degree"},
    "estudioAustralia": {"puntos": 0, "detalle": "No"},
    "estudioRegional": {"puntos": 0, "detalle": "No"},
    "educacionEspecializada": {"puntos": 0, "detalle": "No"},
    "partnerSkills": {"puntos": 10, "detalle": "Soltero"},
    "professionalYear": {"puntos": 0, "detalle": "No"},
    "naati": {"puntos": 0, "detalle": "No"},
    "subtotal": 75,
    "notaNominacion": "Con nominación estatal (190): +5 pts | Con regional (491): +15 pts."
  }
}
```

**Reglas de viability:**
- `apto`: pts ≥ 80 + inglés Proficient+ certificado + experiencia ≥ 5 años
- `parcial`: pts 65-79 O algún factor mejorable
- `no-apto`: SOLO si hay bloqueante absoluto (edad 45+, inglés básico, profesión fuera de listas, pts < 50)

**Icons válidos para `variables`:** `briefcase`, `cake`, `speech`, `clipboard`, `grad`, `chart`, `book`, `key`, `target`, `star`, `check`, `warning`, `pin`, `rocket`, `time`, `X`
— NUNCA emojis directos ni `\uXXXX` en el campo `icon`.

---

## OSL 2025 — Datos de escasez laboral

**Archivo:** `osl_shortage_2025.json`
**Fuente:** Jobs and Skills Australia — Occupation Shortage List 2025
**Registros:** 916 ocupaciones

**Estructura de cada registro:**
```json
{
  "code": "233211",
  "title": "Civil Engineers",
  "national": "S",
  "nsw": "S", "vic": "S", "qld": "S", "sa": "S",
  "wa": "S",  "tas": "S", "nt": "S",  "act": "S",
  "skill_level": 1
}
```

**Ratings:** `S` = Shortage | `NS` = No Shortage | `R` = Regional | `M` = Metro

**Helper PHP:** `viva_get_shortage_data(string $anzsco_code)` — lee el JSON, lo cachea en transient WP por 12h.

**Helper PHP:** `viva_build_shortage_summary(array $osl)` — calcula `demandLevel` (very_high / high / moderate / some / none) y retorna el objeto `shortageMap` que se agrega al resultado.

---

## Sistema de puntos SkillSelect 2025-26

| Factor | Puntos |
|---|---|
| Edad 18-24 | 25 |
| Edad 25-32 | 30 (máximo) |
| Edad 33-39 | 25 |
| Edad 40-44 | 15 |
| Edad 45+ | 0 |
| Inglés Competent (IELTS 6 / PTE 50) | 0 (mínimo requerido) |
| Inglés Proficient (IELTS 7 / PTE 65) | 10 |
| Inglés Superior (IELTS 8 / PTE 79) | 20 |
| Exp. offshore 3-4 años | 5 |
| Exp. offshore 5-7 años | 10 |
| Exp. offshore 8+ años | 15 |
| Exp. onshore 1-2 años | 5 |
| Exp. onshore 3-4 años | 10 |
| Exp. onshore 5-7 años | 15 |
| Exp. onshore 8+ años | 20 |
| Bachelor degree | 15 |
| Doctorado | 20 |
| Diploma / trade AU | 10 |
| Estudio en AU (mín. 2 años) | 5 |
| Zona regional AU | 5 |
| Maestría/Doctorado STEM en AU | 10 |
| Partner skills (Competent + Assessment) | 10 |
| Partner skills (solo Competent) | 5 |
| Soltero / partner ciudadano AU | 10 |
| Professional Year AU | 5 |
| NAATI | 5 |
| Nominación estatal (190) | +5 (no incluir en base) |
| Nominación regional (491) | +15 (no incluir en base) |

**Mínimo EOI:** 65 pts | **Rango competitivo:** 80-95+ pts

---

## Deploy

### Local (funciona)
```bash
cd /Users/macbookpro/Desktop/PreEVM/viva-pre-evm
git add -p
git commit -m "mensaje"
git ftp push
```

**Configuración git-ftp:**
```
url:  ftp://82.29.154.253/wp-content/plugins/viva-pre-evm
user: u905238757.vivaaustralia.com.au
pass: (en git config local, no en repo)
```

**Directorio FTP destino:** `/wp-content/plugins/viva-pre-evm/`
(el server FTP de Hostinger ya apunta a `public_html`, por eso no se incluye)

### GitHub Actions (configurado pero con problema)
Archivo: `.github/workflows/deploy.yml`
- **Problema:** `vivaaustralia.com.au` está detrás de Cloudflare que no proxea FTP (puerto 21).
- **Solución alternativa:** usar la IP directa `82.29.154.253` pero Hostinger bloquea FTP externo intermitentemente.
- **Workaround actual:** `git ftp push` localmente desde Mac.

**Secreto requerido en GitHub:** `FTP_PASSWORD`

---

## Settings WP Admin

Ruta: WP Admin → Settings → Viva Pre-EVM

| Opción | `get_option()` key | Default |
|---|---|---|
| Proveedor IA | `viva_ai_provider` | `anthropic` |
| Anthropic API Key | `viva_anthropic_key` | — |
| Anthropic Model | `viva_anthropic_model` | — |
| OpenAI API Key | `viva_openai_key` | — |
| OpenAI Model | `viva_openai_model` | — |
| Gemini API Key | `viva_gemini_key` | — |
| Gemini Model | `viva_gemini_model` | — |
| GHL API Key | `viva_ghl_key` | `GHL_API_KEY` constante |

---

## Rate limiting

- **5 análisis por IP por hora** via WP transients (`viva_rl_{md5_ip}`)
- **Los admins WP (`manage_options`) no tienen límite** — para testing

---

## Flujo GHL (JavaScript)

### Al completar el formulario (análisis):
```
vpDoAnalysis()
  → POST /analyze → resultado JSON
  → vpPostAnalysis(result)
      → Promise.all([
          POST /ghl-upsert (score, viability, decision),
          POST /save-result (guarda CPT)
        ])
      → .then: POST /ghl-upsert (preevm_result_link)
      → POST /ghl-tag (tags según resultado)
      → POST /ghl-note (resumen textual)
```

### Al hacer "Continuar después":
```
vpContinueLater()
  → Promise.all([
      POST /ghl-upsert (score=0, viability=pendiente, decision),
      POST /save-draft (guarda borrador)
    ])
  → .then: POST /ghl-upsert (preevm_continue_link)
  → POST /ghl-tag (test-preevm, cv-pendiente)
  → POST /ghl-note (estado del quiz)
```

**Nota importante:** el `contactId` puede volver vacío del primer upsert si la respuesta GHL tiene estructura inesperada. Los upserts de links usan `email` para identificar el contacto y no dependen de `contactId`.

---

## JavaScript — Funciones principales

| Función | Descripción |
|---|---|
| `vpInit()` | Inicializa la app, carga draft si hay token `?continuar=` |
| `vpHandleStep1()` | Procesa email, hace ghl-lookup, avanza a s2 |
| `vpHandleStep2()` | Valida y guarda datos del perfil |
| `vpQuizNext() / vpQuizPrev()` | Navega el quiz dinámico |
| `vpHandleAnalyze()` | Valida CV/modo manual, decide si ir a s4 o análisis |
| `vpLaunchAnalysis()` | Inicia animación de loading + análisis |
| `vpDoAnalysis()` | Construye payload y llama POST /analyze |
| `vpPostAnalysis(result)` | Sincroniza con GHL, guarda resultado, muestra pantalla |
| `vpShowResult(d)` | Renderiza pantalla de resultado (apto/parcial) |
| `vpShowNoApto(d)` | Renderiza pantalla no-apto |
| `vpRenderDesglose(d, ...)` | Renderiza tabla de desglose SkillSelect |
| `vpRenderShortageMap(d, containerId)` | Renderiza mapa de escasez por estado australiano |
| `vpContinueLater()` | Guarda draft, genera link, muestra confirmación |
| `vpGeneratePDF()` | Genera PDF del resultado con jsPDF |
| `vpScrollToCalendar()` | Scroll al iframe del calendario GHL |
| `vpApi(endpoint, data)` | Wrapper fetch() para todos los endpoints REST |

---

## Página de resultado (CPT permalink)

La función `viva_render_result_page($r)` renderiza el resultado completo en el permalink público del CPT `preevm_result`. Incluye:

1. Encabezado con datos del perfil y veredicto visual
2. Scorecard (pts, viabilidad %, competitividad %)
3. Desglose SkillSelect (tabla)
4. Análisis textual (alcance, académico, laboral)
5. Marco ocupacional ANZSCO + shortage map por estado
6. Visas potenciales
7. Variables de competitividad
8. Recomendaciones
9. Bloqueantes
10. Botón WhatsApp + botón agendar asesoría

**Fuente de datos:** `_preevm_resultado_json` post meta → `json_decode` → `$r`

---

## Calendario / Agendar asesoría

iframe de GHL Booking embebido al final del resultado:
```
URL: https://crm.vivaaustralia.com.au/widget/booking/jIZFnPViS594ZfTzVfHS
```
- Botón "Agendar asesoría gratuita" hace `vpScrollToCalendar()` → scroll suave
- Auto-resize via `window.addEventListener('message', ...)` que escucha `{height}` del iframe
- Altura mínima: 750px

---

## Tareas pendientes / trabajo futuro

- [ ] **`occupations.json`** — construir el JSON completo de ocupaciones cruzando OSL 2025 con listas MLTSSL/STSOL/ROL, marcar exclusiones de salud (código 25xxxx), calcular `migrationProbability`, agregar keywords en español/inglés. Ver `INSTRUCCION_OCCUPATIONS_JSON_v2.md` en `/Users/macbookpro/Desktop/PreEVM/`.
- [ ] **Arreglar GitHub Actions deploy** — actualmente bloqueado por Cloudflare. Opciones: whitelist IP de GitHub en Hostinger, o usar SSH/rsync en lugar de FTP.
- [ ] **Página de resultado no-apto** — actualmente solo muestra desglose básico. Podría enriquecerse con plan de mejora timeline.
- [ ] **Panel admin de resultados** — listar CPTs `preevm_result` con filtros por viabilidad, puntaje, fecha.
- [ ] **Expiración de drafts** — el cleanup de `preevm_draft` expirados no está automatizado (no hay cron job).

---

## Repositorio

```
GitHub: https://github.com/andrescastellanos/pre-evm
Branch principal: main
```

Commits recientes relevantes:
- `708088e` — fix preevm_continue_link y preevm_result_link
- `e5c00b3` — español neutro + directorio FTP sin /public_html
- `a85ef13` — shortage map por estado OSL 2025
- `cbc2339` — backup inicial antes de migración

---

## Credenciales de referencia (no commitear)

| Servicio | Dónde está |
|---|---|
| GHL API Key | Hardcodeada en `GHL_API_KEY` + sobreescribible en WP Admin |
| GHL Location ID | `GHL_LOCATION_ID = eAmbGQl2QpcHDwBWM7s6` |
| FTP Password | `git config git-ftp.password` (local, nunca en repo) |
| Anthropic / OpenAI / Gemini keys | WP Admin → Settings → Viva Pre-EVM |
