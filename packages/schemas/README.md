# @kalpa/schemas — JSON Schema (draft 2020-12) per asset kind: `<kind>.schema.json`.

The API validates every asset version's spec against its kind's schema (mounted read-only into the api container at /schemas).
`x-schema-version` is stored on each asset version. The schemas are minimal for now; each module extends its own kinds.
