# dockerhub_expire

[![GitHub Action](https://img.shields.io/badge/GitHub-Action-blue?logo=github)](https://github.com/marketplace/actions/dockerhub_expire)
[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

This module provides a GitHub action to allow automation
of removal of old images on DockerHub using simple regex rules.

This workflow is intended in the case where an updated docker image is published
daily using the same tag name. This action allows us to cleanup the old images.
Because of the way that the DockerHub API works, untagged images will not be visible,
so it is important that the images are originally published with a second tag that
will be unique, for example by including the build time and some characteristic label
such as `BUILT`.

This was inspired by https://github.com/lostlink/docker-cleanup

## GitHub Secrets

You need two secrets added to your repository:

1. Go to **Settings** → **Secrets and variables** → **Actions**
2. Add the following secrets:
  - `DOCKERHUB_USERNAME`: Your Docker Hub username
  - `DOCKERHUB_PASSWORD`: Your Docker Hub password or [Personal Access Token](https://docs.docker.com/security/access-tokens/)

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
          rules: 'BUILT:1, testing:30, latest'
          dry-run: true  # Only set to false after you have tested
```

## Running the script locally

`DOCKERHUB__USERNAME=Your_DockerHub_username DOCKERHUB_PASSWORD=password_or_pat php dockerhub_exipre.php --dry-run --repositories=namespace/name --rules="BUILT:1, testing:30, latest'`

