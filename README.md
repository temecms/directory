# The Teme directory

The free directory of plugins and themes for [Teme](https://github.com/temecms/teme). A package listed here appears on temecms.com and in every Teme site's **Plugins › Add plugin** and **Themes › Add theme**, and installs from there.

Listing is free. Your package stays in your own GitHub repository; this repository only holds one small file that points at it.

## How to list a package

1. **Publish it on GitHub**, in a public repository, as a Composer package (see [What your package needs](#what-your-package-needs)).
2. **Tag a release** that Composer reads as a version, such as `v1.0.0` or `1.0.0`.
3. **Open a pull request** that adds one file, `listings/<vendor>/<name>.json`, named after your package's Composer name:

   ```json
   {
       "repository": "https://github.com/acme/seo-audit",
       "kind": "plugin",
       "categories": ["seo"],
       "picked": false
   }
   ```

   The Submit form on temecms.com opens the same pull request for you. [listings/README.md](listings/README.md) explains each field.
4. **The checks run** on your newest release and comment on the pull request in plain words. If something needs fixing, push a fix to the pull request or tag a new release, and they run again.
5. **A maintainer reviews** your first listing: who you are and what the package does.
6. **Once it is merged, the package is listed.** Later releases are picked up from your tags automatically and go through the same checks.

To take a package out of the directory, open a pull request that deletes its listing file.

## What your package needs

At the root of the release:

| File | What it holds |
|---|---|
| `composer.json` | `name` (the same as the listing file's name), `type` (`teme-plugin` or `teme-theme`), `license`, `description`, `authors`, and the Teme versions it works with in `require` (`"teme/teme": "^1.2"`). `support.issues`, `support.docs` and `support.security` become links on its page. |
| `teme-plugin.json` or `teme-theme.json` | Teme's manifest, which Teme itself reads. Its `label` (or a theme's `name`) and `description` are the name and the one-line summary on the directory's pages. A plugin lists the `capabilities` it asks for, as `[]` when it asks for none. |
| `README.md` | What the package does, shown as its page's Overview. |
| `CHANGELOG.md` | Optional, and recommended: one heading per version, with its date. Shown as the Changelog. |

**Icon and screenshots** go in the manifest as paths inside the package:

```json
{
    "icon": "art/icon.png",
    "screenshots": [
        { "path": "art/audit.png", "caption": "The audit of every post" }
    ]
}
```

A theme's `screenshot` is shown too. Each image is a PNG, JPEG or WebP file under 2 MB. The directory keeps its own copy of every image, so your pages never break if you move files later.

## The rules

- **One listing per package**, named after its Composer name, pointing at the package's own public GitHub repository.
- **A licence is named** in `composer.json`.
- **The README describes the package honestly**, and the listing's categories fit it.
- **Ask only for what you use.** Every capability a plugin asks for is shown on its page, in plain words, before anyone installs it.
- **A release that asks for more** than the listed release did (a new capability, or a new host to reach) waits for a maintainer before it is offered to sites. So does a release that fails a check.
- **Releases are never changed after they are tagged.** The directory records each release's checksum once; a release whose archive changes is held.
- **picked** is the Teme team's pick, and only maintainers set it.

A maintainer may hold or remove a listing that breaks these rules, and says why on the pull request or in an issue.

## What the checks look at

On a pull request, and on every new release:

- `composer.json` exists, names the package as the listing does, has the right `type` and names a licence;
- the manifest follows Teme's manifest format (the schema in Teme, copied to [schema/](schema));
- the Teme versions it claims match at least one released Teme;
- `README.md` is at the root;
- the icon and screenshots exist and are PNG, JPEG or WebP images under 2 MB;
- a plugin lists its capabilities;
- it installs with Composer on a fresh Teme of each Teme version it claims (the newest release in each minor series), and a plugin can be enabled there, or a theme passes Teme's theme check;
- if it ships its own tests (a `test` script in `composer.json`, or a PHPUnit or Pest configuration), they pass. When their dependencies cannot be installed, the tests are reported as not run and the release is judged on the other checks.

## How your README is shown

The README is read from the release tag, not from your default branch, so the page always matches the code people install.

- The first heading is dropped, because the page already shows the name.
- When the manifest has no description, the first paragraph is the summary.
- Raw HTML is removed, keeping its words; scripts, styles and comments are removed entirely. Badges are removed, since the page shows the same facts.
- Headings are kept between levels 2 and 4.
- Relative links point at the file on GitHub at the release tag. Only `https`, `mailto` and `#anchor` links are kept; other links keep only their words.
- Up to 10 images are kept and copied into the directory. An image that cannot be fetched, or is not a PNG, JPEG or WebP under 2 MB, is dropped.
- Anything past 100 KB is cut, with a link to read the rest on GitHub.

Recommended headings, none of them required: Features, Install, Usage, Configuration, Support, Licence.

## Categories

From [categories.json](categories.json); a listing names one to three of its kind.

| Plugins | | Themes |
|---|---|---|
| `seo` SEO | `security` Security | `blog` Blog |
| `editing` Editing | `performance` Performance | `magazine` Magazine and news |
| `content` Content | `scheduling` Scheduling | `business` Business |
| `media` Media | `navigation` Navigation | `portfolio` Portfolio |
| `social` Social | `languages` Languages | `documentation` Documentation |
| `forms` Forms | `integrations` Integrations | |
| `analytics` Analytics | `developer` Developer tools | |
| `advertising` Advertising | | |

## For sites and tools

The directory is published at `https://temecms.github.io/directory/`:

- `index.json`: every listed package, with a `sequence` that rises with each publication and an `expires` time;
- `index.json.sig`: an Ed25519 signature over the exact bytes of `index.json`, in base64;
- `packages/<vendor>/<name>.json`: everything a package's page shows, with each release's archive address and SHA-256;
- `composer/packages.json`: a Composer repository of the listed releases.

`index.json` lists the SHA-256 of every other file, so the signature covers them all. Teme checks the signature, the sequence and the expiry before it trusts the index.

## Licence

The listings and the tools in this repository are released under the [MIT licence](LICENSE). Each listed package has its own licence.
