<?php

namespace LocalGovDrupal\GithubWorkflowManager;

use Github\AuthMethod;
use Github\Client;
use Github\Exception\RuntimeException;
use LocalGovDrupal\GithubWorkflowManager\TemplateRenderer\LocalGovRenderer;
use LocalGovDrupal\GithubWorkflowManager\TemplateRenderer\TemplateRendererInterface;


/**
 * Update GitHub workflow files.
 */
class WorkflowUpdater {

  /**
   * GitHub client.
   *
   * @var \Github\Client
   */
  protected Client $client;

  /**
   * Config object.
   *
   * @var \LocalGovDrupal\GithubWorkflowManager\Config
   */
  protected Config $config;

  /**
   * GitHub organization.
   *
   * @var string
   */
  protected string $organization;

  /**
   * List of projects with associated config to update workflows.
   *
   * @var array[]
   */
  protected array $projects = [];

  /**
   * Template renderer.
   *
   * @var \LocalGovDrupal\GithubWorkflowManager\TemplateRenderer\TemplateRendererInterface
   */
  protected TemplateRendererInterface $renderer;

  /**
   * Initialise a WorkflowUpdate instance.
   */
  function __construct($github_access_token, $config) {

    // Initialise GitHub client.
    $this->client = $this->authenticate($github_access_token);

    // Initialise config.
    $this->config = $config;
    $this->organization = $this->config->get('organization');

    // Initialise template renderer.
    $this->renderer = new LocalGovRenderer('templates', $this->config);
  }

  /**
   * Update workflows.
   */
  public function run() {

    // Iterate all the configured projects and supported versions.
    $base_project_config = $this->config->get('base_projects');
    foreach($base_project_config as $base_project => $config) {

      // Get organization packages required by base project.
      $this->projects[$base_project] = [];
      foreach ($config['versions'] as $base_version) {

        // Get projects config.
        $projects = $this->get_composer_config($base_project, $base_version);

        // Organise projects config by name and project version.
        foreach ($projects as $repo => $project) {
          foreach ($project['package_versions'] as $project_version) {
            if (isset($this->projects[$base_project][$repo][$project_version])) {
              $this->projects[$base_project][$repo][$project_version]['base_versions'][] = $base_version;
            }
            else {
              $this->projects[$base_project][$repo][$project_version] = $project;
              $this->projects[$base_project][$repo][$project_version]['base_versions'] = [$base_version];
            }
          }
        }
      }

      // Update workflows for each project version.
      foreach ($this->projects[$base_project] as $project_versions) {
        $workflows = [];
        foreach ($project_versions as $project_version => $project) {

          // Generate workflow.
          $template = $config['template'];
          $this->renderer->set_variables($project);
          $workflow = $this->renderer->render($template);
          print_r($workflow);

          // Get current workflow.

        }
      }
    }
  }

  /**
   * Authenticate with GitHub.
   *
   * @param $github_access_token string
   *   GitHub Access token with repo and workflow scopes.
   *
   * @return \Github\Client
   */
  protected function authenticate(string $github_access_token): Client {

    $client = new Client();
    $client->authenticate($github_access_token, NULL, AuthMethod::ACCESS_TOKEN);

    return $client;
  }

  /**
   * Recurse through composer.json files collecting organization dependencies.
   *
   * @param $repo string
   *   Repo to fetch dependencies from.
   * @param $version string
   *   Branch in repo to get composer.json from.
   * @param $projects array
   *   Projects array to be recursively filled.
   *
   * @return array
   *   Return projects array.
   */
  protected function get_composer_config(string $repo, string $version, array $projects = []): array {

    try {
      // Get composer version.
      $contents = $this->client->repo()->contents()->show($this->organization, $repo, 'composer.json', $version);
      $composer_json = base64_decode($contents['content']);
      $composer = json_decode($composer_json, TRUE);

      // Set version info.
      $projects[$repo]['repo'] = $repo;
      $projects[$repo]['project_name'] = $composer['name'] ?? '';
      $projects[$repo]['project_type'] = $composer['type'] ?? '';
      $projects[$repo]['package_versions'] = isset($projects[$repo]['package_versions']) ? $projects[$repo]['package_versions'] + [$version] : [$version];

      // Recurse over required packages.
      if (isset($composer['require'])) {
        foreach ($composer['require'] as $package => $v) {
          if (str_starts_with($package, $this->organization . '/')) {
            $r = str_replace($this->organization . '/', '', $package);
            $v = $this->version_to_branch($v);
            if (!in_array($r, array_keys($projects))) {
              //$projects += $this->get_composer_config($r, $v, $projects);
            }
          }
        }
      }
    }
    catch (RuntimeException $e) {
      # No composer.json file found in repo.
    }

    return $projects;
  }

  /**
   * Convert Composer version tag to branch name.
   *
   * @param $version string
   *   Version string as found in a composer.json file.
   *
   * @return string
   *   Branch name associated with the version.
   */
  protected function version_to_branch(string $version): string {

    return intval(str_replace('^', '', str_replace('~', '', $version))) . '.x';
  }

}
