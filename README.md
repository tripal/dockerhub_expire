# dockerhub_expire

[![GitHub Action](https://img.shields.io/badge/GitHub-Action-blue?logo=github)](https://github.com/marketplace/actions/>
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

This module provides a GitHub action using a script to allow automation
of removal of old images on DockerHub using simple regex rules.

This workflow is intended in the case where an updated docker image is published
daily using the same tag name. This action allows us to cleanup the old images.

This was inspired by https://github.com/lostlink/docker-cleanup

## GitHub Secrets

You need two secrets added to your repository:

1. Go to **Settings** → **Secrets and variables** → **Actions**
2. Add the following secrets:
  - `DOCKERHUB_USERNAME`: Your Docker Hub username
  - `DOCKERHUB_PASSWORD`: Your Docker Hub password or [Personal Access Token](#personal-access-token)

## Basic Usage

```yaml
name: DockerHub Cleanup
on:
  schedule:
    - cron: '1 2 * * 0'  # Weekly on Monday at 2 AM UTC
  workflow_dispatch:  # Allow manual trigger

jobs:
  cleanup:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v6

      - name: DockerHub Expire
        uses: tripal/dockerhub_expire@v1
        with:
          username: ${{ secrets.DOCKERHUB_USERNAME }}
          password: ${{ secrets.DOCKERHUB_PASSWORD }}
          repositories: 'namespace/name, secondnamespace/secondname'
          rules: 'build:2, testing:30, latest'
          dry-run: true  # Only set to false after you have tested
```

## Running the script locally

`DOCKERHUB__USERNAME=Your_DockerHub_username DOCKERHUB_PASSWORD=password_or_pat php dockerhub_exipre.php --dry-run --repositories=namespace/name --rules="build:2, testing:30, latest'`

