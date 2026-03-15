# 04 — Migration Wizard UI

The Migration Tool ships with a self-contained, single-page **Vue 3** wizard built directly into a Blade template. It requires no build step — Vue and Axios are loaded from CDN.

---

## Technology Stack

| Library | Version | Purpose |
|:--------|:--------|:--------|
| Vue 3 | CDN | Reactive UI, Composition API |
| Axios | CDN | HTTP requests to the backend API |
| Tailwind CSS (CDN) | v3 | Utility-first styling |
| Font Awesome | CDN | Icons |

---

## Wizard Steps

### Step 1 — Connection (`step === 'connect'`)

Collect source database credentials.

**Fields:**

| Field | Default | Required |
|:------|:--------|:---------|
| Host | `127.0.0.1` | ✅ |
| Port | `3306` | ✅ |
| Database Name | — | ✅ |
| Username | `root` | ✅ |
| Password | — | ❌ |
| Table Prefix | — | ❌ |
| Database Driver | `mysql` | ❌ |

**Behaviour:**

- Submits to `POST /cms-migration/test-connection`.
- Credentials are stored server-side in the **Laravel Session** for persistence across subsequent requests.
- On success, transitions to **Step 2 (Discovery)**.
- On failure, shows an inline error message without resetting the form.

---

### Step 2 — Discovery (`step === 'discovery'`)

Displays a high-level overview of what was found in the source database.

**Summary Cards:**

| Card | Source |
|:-----|:-------|
| Sites | `COUNT(sites)` |
| Modules | `COUNT(modules)` |
| Categories | `COUNT(categories)` |
| Themes | `COUNT(themes)` |
| Static Modules | `COUNT(static_module_contents)` |
| Pages | `COUNT(pages)` |

**Site Selection:** A clickable list of all sites found. Selecting a site triggers `POST /cms-migration/site-details` to fetch detailed counts for the review step.

---

### Step 3 — Review (`step === 'review'`)

Shows a breakdown of what will be migrated for the selected site, and exposes all migration settings.

**Detail Counters:**

| Metric | Source |
|:-------|:-------|
| Categories | `COUNT WHERE site_id = ?` |
| Modules | `COUNT WHERE site_id = ?` |
| Pages | `COUNT WHERE site_id = ?` |
| Themes | `COUNT WHERE site_id = ?` |
| Module Properties | `COUNT WHERE site_id = ?` |

**Settings Panel:**

| Setting | Type | Default | Description |
|:--------|:-----|:--------|:------------|
| Migrate Media Files | Toggle | `true` | Enables `SyncMediaStep` |
| Source Root Path | Text Input | — | Absolute path to source `/public` |
| Conflict Strategy | Dropdown | `terminate` | How to handle domain conflicts |

---

### Step 4 — Migrating (`step === 'migrating'`)

A real-time progress monitor displayed while the background job runs.

**Features:**

- Animated circular spinner with percentage counter.
- Linear progress bar fills as the job advances through steps.
- Current step label (e.g., `RUNNING: GLUE SYNC`) shown via polling.
- Polling interval: **every 2 seconds** using `setInterval`.

**Polling Endpoint:** `GET /cms-migration/check-progress/{job_id}`

**Transitions:**
- On `status === 'completed'` → goes to **Step 5 (Success)**.
- On `status === 'failed'` → shows alert with error message and returns to **Step 3 (Review)**.

---

### Step 5 — Success (`step === 'success'`)

Displays a detailed execution report after the migration completes.

**Features:**

- Purple gradient celebration banner.
- JSON results from each pipeline step rendered as readable key-value pairs.
- Direct link to start a new migration.

---

## Reactive State Reference

| Variable | Type | Description |
|:---------|:-----|:------------|
| `step` | `ref<string>` | Current wizard step name |
| `form` | `reactive<object>` | Connection + settings form data |
| `loading` | `ref<boolean>` | Button loading state |
| `status` | `ref<string>` | Connection status message |
| `summary` | `ref<object>` | Global source DB summary |
| `sites` | `ref<array>` | Sites list from source |
| `selectedSite` | `ref<number>` | Currently selected site ID |
| `siteDetails` | `ref<object>` | Detailed counts for selected site |
| `migrationResults` | `ref<object>` | Final per-step results from the job |
| `progress` | `ref<number>` | 0–100 job progress percentage |
| `migrationMessage` | `ref<string>` | Current step label from polling |

---

## CSRF Protection

All Axios requests automatically include the Laravel CSRF token:

```js
axios.defaults.headers.common['X-CSRF-TOKEN'] = '{{ csrf_token() }}';
```

This is set once at page load before the Vue app mounts, ensuring all POST requests pass Laravel's `VerifyCsrfToken` middleware.
