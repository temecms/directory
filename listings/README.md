# Listings

One JSON file per package, at `listings/<vendor>/<name>.json`, where `<vendor>/<name>` is the package's Composer name in lower case.

## An example: a plugin

`listings/acme/seo-audit.json`:

```json
{
    "repository": "https://github.com/acme/seo-audit",
    "kind": "plugin",
    "categories": ["seo", "content"],
    "picked": false
}
```

| Field | What it means |
|---|---|
| `repository` | The package's public GitHub repository, as `https://github.com/<owner>/<repo>`. Releases are read from its tags. |
| `kind` | `plugin` or `theme`. A plugin's `composer.json` has the type `teme-plugin` and a `teme-plugin.json` manifest; a theme has `teme-theme` and `teme-theme.json`. |
| `categories` | One to three categories from [categories.json](../categories.json), from the list for its kind. |
| `picked` | `false`. Only maintainers set it to `true`, for the Teme team's picks. |

Nothing else goes in the file. Everything a page shows (the name, summary, icon, screenshots, versions, capabilities, links) is read from the package itself at each release, so there is nothing here to keep up to date.

[schema/listing.schema.json](../schema/listing.schema.json) describes the same format for editors that read JSON Schema.

## A theme

[temecms/theme-temecms.json](temecms/theme-temecms.json) is a real listing: the theme of temecms.com.
