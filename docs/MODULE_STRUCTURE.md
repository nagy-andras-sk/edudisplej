# Module Package Standard (EduDisplej)

Goal: every module is portable, importable, and assignable to companies/kiosks with one consistent format.

## 1) Canonical module folder structure

Each module package must contain exactly one module root folder with this minimum layout:

```
webserver/control_edudisplej_sk/modules/<module-folder>/
  module.json
  config/
    default_settings.json
  m_<module-key>.html   (or any renderer file declared in module.json)
```

## 2) Mandatory manifest (`module.json`)

Required fields:

- `schema_version` (string, current `1.0`)
- `module_key` (lowercase, pattern: `^[a-z0-9_.-]+$`)
- `name` (human readable module name)
- `renderer` (relative file path inside package)
- `config.defaults` (relative file path inside package)

Optional fields:

- `description`
- `folder` (target install folder name; defaults to `module_key`)

Example:

```json
{
  "schema_version": "1.0",
  "module_key": "my-module",
  "name": "My Module",
  "description": "Short module description",
  "renderer": "m_my-module.html",
  "config": {
    "defaults": "config/default_settings.json"
  }
}
```

## 3) Import flow (admin)

The admin `Modules` page supports ZIP import and enforces standard checks:

1. ZIP is extracted to temp directory
2. `module.json` is detected (`ZIP root` or `first child folder`)
3. manifest schema is validated
4. renderer + default settings file existence is validated
5. module files are installed under `modules/<folder>`
6. `modules` DB table is inserted/updated (`module_key`, `name`, `description`, `is_active=1`)

If target folder already exists, import is blocked unless overwrite is explicitly enabled.

## 4) Runtime resolution and API behavior

Runtime module path resolution is centralized in:

- `webserver/control_edudisplej_sk/modules/module_standard.php`

This ensures:

- module key → folder mapping is consistent across admin/API
- required files and manifest are validated before serving module files
- new standalone modules work without hardcoded switch logic in API

## 5) Shared renderer / shared folder modules

Some module keys may share one implementation folder (for example `clock`, `datetime`, `dateclock`).
Mapping is defined in:

- `webserver/control_edudisplej_sk/modules/module_registry.php`

Rules:

- DB can store multiple module keys
- runtime can resolve them to one physical module folder
- deletion/import must account for shared folder usage

## 6) Developer starter template

Reference template:

- `webserver/control_edudisplej_sk/modules/_template/module.json`
- `webserver/control_edudisplej_sk/modules/_template/config/default_settings.json`
- `webserver/control_edudisplej_sk/modules/_template/m_my-module.html`

Recommended workflow for external developers:

1. copy `modules/_template`
2. rename key/files
3. implement renderer
4. test with default settings
5. zip package root and import from admin

## 7) Migration checklist for existing modules

For every existing module, verify:

- `module.json` exists and has required fields
- `config/default_settings.json` exists
- `renderer` path in manifest is valid
- `module_key` matches standard format
- module is present in DB `modules`

Current built-in modules already follow this model via registry + manifest approach.
