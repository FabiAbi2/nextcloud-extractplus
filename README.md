<!--
 - SPDX-FileCopyrightText: 2025 LibreCode coop and contributors
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Extract
Place this app in **nextcloud/apps/**

This app does only support local external storage backend

## Supported

- Zip 
- Rar
- Tar
- Gzip
- 7z
- Deb
- Bzip2

## Requirements

- Rar PHP extension 
```bash
pecl -v install rar ## or ## sudo apt-get install unrar
```

- p7zip and p7zip-full 

## Steps to install p7zip on Linux &raquo; Ubuntu and Fedora or CentOS / RHEL

#### MacOS

```bash
brew install p7zip
```

#### Ubuntu

```bash
sudo apt-get install p7zip p7zip-full
```

#### CentOS 7.x or Fedora
#### Download and install manually 

```bash
wget https://www.mirrorservice.org/sites/dl.fedoraproject.org/pub/epel/7/x86_64/Packages/p/p7zip-16.02-10.el7.x86_64.rpm
wget https://www.mirrorservice.org/sites/dl.fedoraproject.org/pub/epel/7/x86_64/Packages/p/p7zip-plugins-16.02-10.el7.x86_64.rpm

sudo rpm -U --quiet p7zip-16.02-10.el7.x86_64.rpm
sudo rpm -U --quiet p7zip-plugins-16.02-10.el7.x86_64.rpm
```

#### CentOS 6.7
#### From EPEL
Download and install using Package manager

```bash
cat /etc/*release
sudo yum install -y -q epel-release
sudo rpm -U --quiet http://mirrors.kernel.org/fedora-epel/6/i386/epel-release-6-8.noarch.rpm
sudo rpm --import /etc/pki/rpm-gpg/RPM-GPG-KEY-EPEL-6
sudo yum repolist
sudo yum install -y -q p7zip p7zip-plugins
```

#### Download and install manually 
In case of connectivity [or any other] issues, please follow these steps to download and install the following packages directly.

```bash
wget https://www.mirrorservice.org/sites/dl.fedoraproject.org/pub/epel/6/x86_64/Packages/p/p7zip-16.02-10.el6.x86_64.rpm
wget https://www.mirrorservice.org/sites/dl.fedoraproject.org/pub/epel/6/x86_64/Packages/p/p7zip-plugins-16.02-10.el6.x86_64.rpm

sudo rpm -U --quiet p7zip-16.02-10.el6.x86_64.rpm
sudo rpm -U --quiet p7zip-plugins-16.02-10.el6.x86_64.rpm
```

## Progress

Extracting a large archive takes a while. Instead of leaving the browser on a
silent request, the app opens a dialog that shows the percentage, the file it is
currently unpacking and the phase it is in. The dialog can be hidden - the
extraction keeps running on the server - and a failed extraction is shown there
instead of disappearing into the browser console.

Progress state is kept in the distributed cache when the instance has one
configured (Redis, Memcached, APCu) and falls back to a file in the temp
directory otherwise, so no extra setup is required.

## Development

```bash
npm install
npm run build          # production bundle into js/
npm run watch          # rebuild on change
npm run lint           # eslint
npm run typescript:check
composer run lint      # php -l over the sources
composer run psalm     # static analysis
```

On Windows, `npm install` also runs `build/patch-vite-config-win.mjs`. It works
around a bug in `@nextcloud/vite-config` whose license plugin recurses forever at
the drive root and makes the production build run out of memory. The script is a
no-op on every other platform.

## TODO

- Add password support
- Add viewer for archives
- Support nextcloud's encryption module

## Preview

![alt text](https://raw.githubusercontent.com/PaulLereverend/NextcloudExtract/master/img/extract.png)
