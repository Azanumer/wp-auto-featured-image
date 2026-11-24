# WP Auto Featured Image

Never publish a post without a featured image again. When a post is saved without one, the first image in its content becomes the featured image automatically — no manual step, no broken social shares.

## Features

- **Automatic** — hooks `save_post` (late priority) and sets the thumbnail from the first `<img>` in the content
- **Never overwrites** — posts that already have a featured image are left untouched
- **Media Library first** — an image already in the library is reused by URL match, so nothing is duplicated
- **Optional sideloading** — external (hotlinked) images can be downloaded into the Media Library; off by default, so nothing is fetched without your say-so
- **SVG-safe** — SVGs are skipped by default (they are not valid featured images on most setups)
- **Bulk retrofill** — **Tools → Auto Featured Image** sets featured images for existing posts in batches of 20, with a live count of posts still missing one
- **Per-post-type control** — choose which public post types the plugin applies to

## How it works

1. You save a post that has no featured image.
2. The plugin finds the first image in the post content.
3. If that image is already in the Media Library, its attachment is used.
4. If sideloading is enabled and the image is a remote `http(s)` URL, it is downloaded with `media_sideload_image()` and the new attachment is used.
5. Data URIs, SVGs (when enabled) and unresolvable images are skipped.

## Filters

- None — behaviour is controlled from **Tools → Auto Featured Image**.

## Requirements

- WordPress 5.8+
- PHP 7.4+

## License

MIT — see [LICENSE](LICENSE).
