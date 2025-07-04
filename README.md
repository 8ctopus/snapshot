# snapshot

**snapshot** is a powerful and experimental command-line tool for analyzing and archiving entire websites. Effortlessly discover, analyze, and save website content, including SEO metadata, all from your terminal. Whether you’re tracking changes after a major code update or auditing a site's structure, snapshot makes it easy to compare and preserve web content.

_Note:_ This project was created in a single day of inspired coding and is still experimental.

## Features

- **Website Archiving:** Download and locally save complete websites or selected pages.
- **Robots.txt Analysis:** Fetch and inspect robots.txt files for crawling rules.
- **Sitemap Parsing:** Extract and analyze all URLs listed in a site's sitemap.
- **Hidden Page Discovery:** Uncover internal links not listed in sitemaps.
- **SEO Metadata Extraction:** Gather and review SEO-relevant data from every page.
- **Content Cleaning:** Use regex-based cleaning to prepare pages for accurate comparison.

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

# discover pages not in sitemap (noindex)
> discover
16 links stashed

# snapshot discovered pages
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
