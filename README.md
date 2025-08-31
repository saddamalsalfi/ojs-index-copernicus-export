# Index Copernicus (ICI) Export Plugin for OJS 3.5.0-1

A modern, schema-validated export plugin that produces **Index Copernicus International (ICI)** XML from **Open Journal Systems (OJS) 3.5.0-1**.

> TL;DR: Install under `plugins/importexport/copernicus/`, enable it in **Tools → Import/Export**, select issues, and export a single XML that validates against `journal_import_ici.xsd`.

---

## Highlights

- **OJS 3.5–native** data access (Repo collectors; publication/issue–aware).
- **Clean separation** (DOAJ-style): thin plugin + dedicated `CopernicusXmlBuilder` for all XML logic.
- **Schema-compliant XML** (validated against `schema/journal_import_ici.xsd`).
- **Multi-language support**: one `<languageVersion>` per locale that actually has a title.
- **Correct author model**: `name`, `name2` (optional middle), `surname`, `order`, `ORCID`, `country`, and **`instituteAffiliation`** (now reliably exported).
- **Robust DOI handling**: normalization from publication/submission; optional extraction from unparsed references.
- **Safer references**: follow XSD sequence `unparsedContent → order → (doi)` and minimum length sanity.
- **Journal element as empty**: `<journal issn="..."/>` with **no child content** as required by the XSD.
- **Optional schema validation toggle** in the UI, enabled by default (like DOAJ plugin).

---

## Compatibility

- **OJS**: 3.5.0–1 (tested). It may work on newer 3.5.x but is not guaranteed.
- **PHP**: 8.1+ (8.2 recommended).
- **Extensions**: `dom`, `libxml` must be enabled.

> If you run into PHP/libxml differences across environments, use the built-in validation toggle to temporarily disable validation to generate the file, then validate externally.

---

## File Layout (inside `plugins/importexport/copernicus/`)

```
copernicus/
├─ CopernicusPlugin.php
├─ classes/
│  └─ CopernicusXmlBuilder.php
├─ schema/
│  └─ journal_import_ici.xsd
├─ templates/
│  ├─ index.tpl
│  └─ issues.tpl
├─ locale/
│  └─ en_US/… (and other locales as needed)
└─ README.md
```

---

## Installation

1. Copy this folder to `OJS_ROOT/plugins/importexport/copernicus/`.
2. Ensure web server can read the files (and execute PHP).
3. In OJS: **Dashboard → Tools → Import/Export** → enable **Index Copernicus Export**.
4. (Optional) Clear caches: **Administration → Clear Data Caches**.

---

## Upgrading from older versions

- Remove any previous `copernicus/` plugin folder (or move aside).
- Copy the new plugin directory.
- Clear caches.
- Re-visit the plugin UI and verify the **“Validate against schema”** checkbox state (on by default).

---

## Usage

1. Go to **Dashboard → Tools → Import/Export → Index Copernicus Export**.
2. Click **Export by issues**.
3. Select one or more **published** issues.
4. (Optional) Toggle **Validate against schema** (enabled by default).
5. Click **Export** to download the XML.

The export file groups all selected issues under the root `<ici-import>` (with a single empty `<journal issn="..."/>` element and then multiple `<issue>` siblings).

---

## What the XML looks like (simplified)

```xml
<ici-import>
  <journal issn="1234-5678"/>
  <issue number="1" volume="10" year="2025" publicationDate="2025-01-15" numberOfArticles="12">
    <article>
      <type>ORIGINAL_ARTICLE</type>

      <languageVersion language="en">
        <title><![CDATA[Example Title]]></title>
        <abstract><![CDATA[Plain-text abstract...]]></abstract>
        <pdfFileUrl>https://.../article/download/123/456</pdfFileUrl>
        <articleUrl>https://.../article/view/123</articleUrl>
        <publicationDate>2025-01-15</publicationDate>
        <pageFrom>1</pageFrom>
        <pageTo>12</pageTo>
        <doi>10.1234/abcd.efgh</doi>
        <keywords>
          <keyword><![CDATA[keyword one]]></keyword>
        </keywords>
        <license type="CC BY">https://creativecommons.org/licenses/by/4.0/</license>
      </languageVersion>

      <authors>
        <author>
          <name><![CDATA[Ahmad]]></name>
          <surname><![CDATA[Saleh]]></surname>
          <email>ahmad@example.org</email>
          <order>1</order>
          <instituteAffiliation><![CDATA[Dept. of X ; Research Center Y]]></instituteAffiliation>
          <country>YE</country>
          <role>LEAD_AUTHOR</role>
          <ORCID>0000-0002-1825-0097</ORCID>
        </author>
        <!-- more authors... -->
      </authors>

      <references>
        <reference>
          <unparsedContent><![CDATA[Full free-text citation...]]></unparsedContent>
          <order>1</order>
          <doi>10.1000/xyz123</doi>
        </reference>
        <!-- more refs... -->
      </references>
    </article>
    <!-- more articles... -->
  </issue>
  <!-- more issues... -->
</ici-import>
```

---

## Notes on key fields

### Multi-language (`<languageVersion>`)
- The builder emits one block **per locale that has a non-empty title**.
- Abstracts/keywords are included when available for the same locale.
- Page ranges accept `12-34`, single `e1234`, or a single numeric page.

### Authors and affiliations
- `name` (given) and `surname` (family) are **required**; `name2` is optional.
- Affiliation follows a DOAJ-like strategy:
  1) Use `Author::getLocalizedAffiliationNames($publication->locale)` when available (multiple values are joined with ` ; `).
  2) Fall back to `getLocalizedAffiliation()` / `getLocalizedData('affiliation')`.
  3) Lastly, use the raw localized bag `affiliation` with Latin-first selection.
- You can force a fallback string (e.g. `"No data"`) via plugin options.

### References
- The element order is **strict**: `unparsedContent` → `order` → `doi?`.
- Short fragments (under ~25 chars) are ignored.
- The builder optionally extracts a DOI from the free text if present.

### DOI
- Normalized to bare form `10.xxxx/...` (prefixes like `https://doi.org/` are removed).
- If not found on the publication, the builder checks legacy fields and submission-level storage.

### License
- `license` value is the URL; `type` attribute maps to one of: `CC BY`, `CC BY-NC`, `CC BY-NC-SA`, `CC BY-NC-ND`, `CC BY-SA`, `CC BY-ND`, or `OTHER`.

### Journal node
- Per the XSD, `<journal>` is an **empty element** that only carries the `issn` attribute. **No child elements** are allowed.

---

## Validation

Validation is **enabled by default**. You can toggle it in the plugin UI.

- **In-app**: the plugin runs `DOMDocument::schemaValidate()` against `schema/journal_import_ici.xsd` and logs errors to PHP `error_log`.
- **CLI (external)**: for troubleshooting across environments, you may run:
  ```bash
  xmllint --noout --schema schema/journal_import_ici.xsd exported.xml
  ```

If you see errors like *“Element 'order' is not expected. Expected is ( unparsedContent ).”*, it means the reference sequence was violated — upgrade to the latest version of this plugin (fixed).

---

## Troubleshooting

- **Affiliations missing**: ensure author affiliations are filled in the publication; if your workflow stores them only in a non-default locale, enable that locale and re-save; or enable the fallback text.
- **“journal content is not allowed”**: your `<journal>` element had children; update to the latest plugin where `<journal>` is empty.
- **No second language output**: the plugin adds `languageVersion` only for locales that have a **non-empty title** on the publication.
- **Validation fails on license type**: if the URL is not a CC URL, the type is set to `OTHER`.
- **Missing strings**: add or update locale XML files in `locale/<locale>/` and clear caches.

---

## Development

- Code style: PSR-4 namespaces under `APP\plugins\importexport\copernicus`.
- Keep all XML building in `classes/CopernicusXmlBuilder.php` (mirrors DOAJ plugin approach).
- Templates `index.tpl` and `issues.tpl` include a **“Validate against schema”** toggle (checked by default).

---

## License

GNU General Public License v3 (see `docs/COPYING`).

---

## Changelog

### 2.0.0 (2025‑08‑27)
- Full rewrite for OJS **3.5.0-1** compatibility.
- New **CopernicusXmlBuilder** with strict XSD conformance.
- Correct `<references>` order **(unparsedContent → order → doi)**.
- DOAJ-style affiliation resolution; reliably exports `instituteAffiliation`.
- Improved multi-language handling; only emit locales with real titles.
- Safer DOI normalization and optional DOI extraction from references.
- UI toggle for schema validation (default **ON**).

---

## Credits

- Based on the architecture patterns used in the official **DOAJ** export plugin for OJS.
- Thanks to the OJS/PKP community for guidance and testing.
