# MediaDC (Fork)

**Collect photo and video duplicates to save your cloud storage space.**

This is a fork of [cloud-py-api/mediadc](https://github.com/cloud-py-api/mediadc) by [Andrey Borysenko](https://github.com/andrey18106) and [Alexander Piskun](https://github.com/bigcat88), who built an amazing tool for finding duplicate media in Nextcloud. Huge thanks to them for all the work they put into this project.

The original repository was archived as the maintainers no longer had capacity to keep it going. I forked it because I rely on MediaDC for my own Nextcloud setup and wanted to keep it working with recent versions.

This fork was updated almost entirely using LLM tooling ([Claude Code](https://claude.ai/code)). It brings compatibility with **Nextcloud 33**, removes the `cloud_py_api` dependency, migrates the frontend to **Vue 3**, and ships **pre-compiled Python binaries** so you don't need Python installed on your server.

## Installation

### Option 1: Download from releases (recommended)

```bash
cd /var/www/html/apps
wget https://github.com/marcbenedi/mediadc/releases/latest/download/mediadc.tar.gz
tar xzf mediadc.tar.gz && rm mediadc.tar.gz
sudo -u www-data php /var/www/html/occ app:enable mediadc
```

The release package includes pre-compiled Python binaries — no Python, pip, or ffmpeg needed on the server.

### Option 2: Install from source

```bash
cd /var/www/html/apps
git clone https://github.com/marcbenedi/mediadc.git
cd mediadc
npm ci && npm run build
```

Then install the Python dependencies on the server:

```bash
apt install python3-pip python3-dev build-essential ffmpeg
pip install -r requirements.txt
```

Enable the app:

```bash
sudo -u www-data php /var/www/html/occ app:enable mediadc
```

When installing from source, the app will try to download a pre-compiled binary on first enable. If that fails, it falls back to using the system Python — in that case make sure python3, pip, and ffmpeg are installed.

---

*Everything below this line is the original README from the upstream repository.*

---

## This Repository is Archived

We are immensely grateful to everyone who has been a part of the Nextcloud MediaDC journey. Your contributions and support have been invaluable.

Unfortunately, we no longer have the capacity to maintain this repository, and it is now in an archived state. We need to move forward, and we thank you for your understanding.

# Nextcloud MediaDC

![build](https://github.com/cloud-py-api/mediadc/actions/workflows/create-release-draft.yml/badge.svg)
[![Publish to Nextcloud app store](https://github.com/cloud-py-api/mediadc/actions/workflows/publish-appstore.yml/badge.svg)](https://github.com/cloud-py-api/mediadc/actions/workflows/publish-appstore.yml)
[![Test Binaries](https://github.com/cloud-py-api/mediadc/actions/workflows/test-binaries.yml/badge.svg)](https://github.com/cloud-py-api/mediadc/actions/workflows/test-binaries.yml)
[![Github All Releases](https://img.shields.io/github/downloads/andrey18106/mediadc/total.svg)](https://github.com/cloud-py-api/mediadc/releases)



**📸📹 Collect photo and video duplicates to save your cloud storage space**

**[cloud_py_api](https://apps.nextcloud.com/apps/cloud_py_api)** required to be installed and enabled first.

| **Not working on FreeBSD systems for now**

![Home page](/screenshots/mediadc_home.png)
![Task page](/screenshots/mediadc_task_details_2.png)
Nextcloud Media Duplicate Collector application

## Why is this so awesome?

* **♻ Detects similar and duplicate photos/videos with different resolutions, sizes and formats**
* **💡 Easily saves your cloud storage space and time for sorting**
* **⚙ Flexible configuration**

## 🚀 Installation

First of all, in you Nextcloud install and enable [`cloud_py_api`](https://apps.nextcloud.com/apps/cloud_py_api) through the Apps management, then install MediaDC app.
Starting from 0.2.0 version MediaDC is only included in Nextcloud v25 and higher.
#### Read more on [Wiki page](https://github.com/cloud-py-api/mediadc/wiki)

## Maintainers

* [Andrey Borysenko](https://github.com/andrey18106)
* [Alexander Piskun](https://github.com/bigcat88)

## State of the Maintenance

As Andrey and I(Alexander) are fully committed to the **NextCloud App Ecosystem** project, 
we will be working tirelessly around the clock for the next two months. 
Due to our intense dedication to that project, our availability will be limited during this period. 
However, we encourage and welcome any contributions from the community in the form of pull requests.

After Nextcloud App Ecosystem V2 finished we'll rewrite MediaDC to use the new system, and write many other amazing applications.

For All Coders who want to write New Amazing Applications for 
Nextcloud with New App Ecosystem - we are avalaible to discuss its API, prototypes, etc. in their repositories. 
