# snapshot

A command-line tool for analyzing and archiving websites. It helps you discover, analyze, and save website content, including hidden pages and SEO information.

_NOTE_: This is the result of a day of vibe coding in cursor, so it's experimental.

## Features

- **Website Snapshot**: Download and save website pages locally
- **Sitemap Analysis**: Parse and analyze website sitemaps
- **Hidden Page Discovery**: Find internal links not listed in the sitemap
- **SEO Analysis**: Extract and analyze SEO metadata from pages
- **Robots.txt Analysis**: Download and analyze robots.txt files

## Usage

```bash
php index.php

# set target website
> host example.com

# download robots.txt
> robots
User-Agent: *
Sitemap: https://example.com/sitemap.xml

1 sitemaps found

# download sitemap
> sitemap
149 links stashed

# take a snapshot of all pages in sitemap
> snapshot
149 pages

# snapshot specific pages (relative path)
> snapshot /page1 /page2
2 pages

# discover hidden pages not in sitemap (noindex)
> discover hidden
16 hidden links stashed

# snapshot hidden pages
> snapshot
16 pages

# extract SEO information (see seo.txt in snapshot dir)
extract seo
SEO extracted
```

## Output

The tool creates a directory structure like this:
```
snapshots/
  example.com/
    2024-03-21_12-34/
      seo.txt
      pages/
        sitemap.xml
        robots.txt
        index.html
        page1.html
        page2.html
```
