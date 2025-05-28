# snapshot

A command-line tool for analyzing and archiving websites. It helps you discover, analyze, and save website content, including hidden pages and SEO information.

## Features

- **Website Snapshot**: Download and save website pages locally
- **Sitemap Analysis**: Parse and analyze website sitemaps
- **Hidden Page Discovery**: Find internal links not listed in the sitemap
- **SEO Analysis**: Extract and analyze SEO metadata from pages
- **Robots.txt Analysis**: Download and analyze robots.txt files

## Usage

```bash
php index.php

# set the target website
host example.com

# download robots.txt
robots

# analyze sitemap
sitemap

# take a snapshot of all pages
snapshot urls

# snapshot specific pages
snapshot /page1 /page2

# discover hidden pages not in sitemap (noindex)
discover hidden

# snapshot hidden pages
snapshot urls

# extract SEO information
extract seo

# delete all snapshots
clear
```

## Output

The tool creates a directory structure like this:
```
snapshots/
  example.com/
    2024-03-21_12-34/
      index.html
      page1.html
      page2.html
      robots.txt
      seo.txt
```
