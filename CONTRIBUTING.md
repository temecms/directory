# Contributing

## Listing a package

Read [README.md](README.md) first: it says what a package needs, the rules, and what the checks look at.

1. Fork this repository.
2. Add `listings/<vendor>/<name>.json` for your package, as [listings/README.md](listings/README.md) shows.
3. Open a pull request. The template asks a few questions; answer them.
4. The checks comment on the pull request. Fix what they name, in the pull request or with a new release of your package, and they run again.
5. A maintainer reviews the listing and merges it.

To change a listing's categories, or to remove a listing, open a pull request that changes or deletes its file.

You can check a listing file before you open the pull request:

```bash
composer install
php bin/directory validate
```

## Releases after the first

Nothing to do here: tag a release in your own repository and the directory picks it up and checks it. If a release fails a check, or asks for more than the listed release did, an issue labelled `held` is opened in this repository and a maintainer looks at it. You can follow it there.

## Reporting a listing

Open an issue saying which package and what is wrong. For a security problem in a listed package, report it to the package's own security contact first; if it has none, or nobody answers, open an issue here without the details and a maintainer will get in touch.

## Changing the tools

The tools that check listings and build the index are in `src/`, run from `bin/directory`, with their tests in `tests/`:

```bash
composer install
vendor/bin/phpunit
```

Behaviour that changes gets a failing test first. The categories in `categories.json` are kept short on purpose; propose a new one in an issue before a pull request.

## For maintainers

- **Holds.** A held release has an issue labelled `held`. Close it as *completed* to list the release, or as *not planned* to keep it out. The next run picks up the decision.
- **Picks.** Only maintainers set `picked` to `true`; the checks refuse it from anyone else.
- **Where things live.** `main` holds the listings and the tools. The `directory-state` branch holds what the builder remembers between runs (the tags seen, each release's checksums and checks, the sequence). The `gh-pages` branch is the published site. Neither is edited by hand.
- **The signing key** is made once with `scripts/make-signing-key`, which stores the private key as the Actions secret `DIRECTORY_SIGNING_KEY` and prints the public key. Set that public key as the Actions variable `DIRECTORY_PUBLIC_KEY`, so every run checks its own signature before publishing. The private key is never printed or written to a file.
- **Pages** publishes the `gh-pages` branch. The address is `https://temecms.github.io/directory/` unless the variable `DIRECTORY_BASE_URL` says otherwise.
- **A fresh index** can be signed and published at any time from the Build index workflow's *Run workflow* button, with *force* ticked.
