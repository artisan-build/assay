# Dataset JSONL export v1

Assay renders dataset exports on demand from live PostgreSQL rows. It does not persist rendered files.
An export URL identifies a request; it is not a bearer capability. Download requires an authenticated
principal with a fresh `assay.content` decision, the same principal that created the request, and a request
no more than 15 minutes old.

The media type is `application/x-ndjson`. Each line is one UTF-8 JSON object with `schema` set to
`assay.dataset-item.v1`, an `item_id`, and these immutable snapshot fields:

- `agent_class`, `provider`, and `requested_model`
- `instructions`, ordered `input_messages`, and `tool_schemas`
- `observed_output`, containing `text`, `structured`, and `tool_calls`
- `labels`, `rating`, and `note`
- `source_client_version`, `deploy`, `capture`, `sampled`, and `hook_applied`
- `replay_fidelity`, either `complete` or `partial`

`complete` means the source run did not report `replay_inputs_omitted`. Any reported omission produces
`partial`; Assay does not infer fidelity from the omitted values themselves. Runs marked
`content_incomplete` cannot be added.

Dataset retention is counted from item addition. The operator default is 365 days, and each dataset may set a
different positive number of days no greater than usage metadata retention (395 days by default). No-expiry is
invalid because source run ancestry must outlive each item. Curated copies can outlive source run content.
Delete-by-subject and export rendering resolve current run ancestry under the same PostgreSQL subject lock;
the stored subject handle is used only after the source run is gone. A later download always renders the
post-erasure live state.
