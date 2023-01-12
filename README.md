# GitHub Workflow Manager

Manage GitHub workflows across the LocalGov Drupal organization.

## Installation

### Requires

  * PHP 8+
  * Composer

### Install

```shell
git clone git@github.com:localgovdrupal/github_workflow_manager.git
cd github_workflow_manager
composer install
```

Create a personal [GitHub access token](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/creating-a-personal-access-token) with the repo and workflow scopes. Then create or add this to a `.env` file in the base of the root of this project with the content:

```ini
GITHUB_ACCESS_TOKEN=my_github_access_token
```

## Maintainers

This project is currently maintained by:

 - Stephen Cox: https://github.com/stephen-cox
 - Finn Lewis: https://github.com/finnlewis
