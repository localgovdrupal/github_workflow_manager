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
   * Branch name for changes to workflow.
   *
   * @var string
   */
  protected string $branch;

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
    $this->branch = $this->config->get_branch();
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
      $this->log('Fetching composer dependency tree for ' . $base_project);
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
        foreach ($project_versions as $project_version => $project) {

          // Generate workflow.
          $template = $config['template'];
          $workflow_file = $config['workflow_file'];
          $this->renderer->set_variables($project);
          $workflow = $this->renderer->render($template);

          // Compare with current workflow.
          try {
            $current_workflow = $this->fetch_file($project['repo'], $workflow_file, $project_version);
            if ($current_workflow == $workflow) {
              continue;
            }
          }
          catch (RuntimeException $e) {
            // File not found so continue with update.
          }

          // Update workflow and create PR for project.
          $this->create_branch($project['repo'], $this->branch, $project_version);
          $this->update_file($project['repo'], $workflow_file, $this->branch, $workflow, 'Updated GitHub workflow');
          $this->create_pull_request($project['repo'], $project_version, $this->branch, 'Update workflow on ' . $project_version . ' branch');
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
   * Create branch in repo.
   *
   * @param string $repo
   *   The name of the repository to create branch in.
   * @param string $branch
   *   Branch name to create.
   * @param string $source
   *   Source branch to create branch from.
   */
  protected function create_branch(string $repo, string $branch, string $source): void {

    try {

      // Check if branch exists.
      $this->client->git()->references()->show($this->organization, $repo, 'heads/' . $branch);
    }
    catch (RuntimeException $e) {

      // Create branch.
      $source_info = $this->client->git()->references()->show($this->organization, $repo, 'heads/' . $source);
      $params = [
        'ref' => 'refs/heads/' . $branch,
        'sha' => $source_info['object']['sha'],
      ];
      $this->client->git()->references()->create($this->organization, $repo, $params);
      $this->log('Created ' . $branch . ' branch in ' . $repo);
    }
  }

  /**
   * Create pull request.
   *
   * @param $repo string
   *   Name of repository to create pull request in.
   * @param $base string
   *   Branch to merge into.
   * @param $branch string
   *   Branch to be merged.
   * @param $title string
   *   Title of pull request.
   * @param $body string
   *   Body of pull request.
   */
  protected function create_pull_request(string $repo, string $base, string $branch, string $title, string $body = ''): void {

    // Check if PR between $base and $branch already exists.
    $params = [
      'base' => $base,
      'head' => $this->organization . ':' . $branch,
    ];
    $pr = $this->client->pullRequest()->all($this->organization, $repo, $params);
    if (empty($pr)) {

      // Create PR.
      $params = [
        'base' => $base,
        'head' => $branch,
        'title' => $title,
        'body' => $body,
      ];
      $this->client->pullRequest()->create($this->organization, $repo, $params);
      $this->log('Created pull request in ' . $repo);
    }
  }

  /**
   * Get file contents from GitHub.
   *
   * @param string $repo
   *   The name of the repository to get file from.
   * @param string $path
   *   Path to file or directory.
   * @param string $branch
   *   Branch or commit reference to get file from.
   *
   * @return string
   *   Return the file contents.
   */
  protected function fetch_file(string $repo, string $path, string $branch): string {

    $contents = $this->client->repo()->contents()->show($this->organization, $repo, $path, $branch);
    $file_contents = base64_decode($contents['content']);

    return $file_contents;
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
      $composer_json = $this->fetch_file($repo, 'composer.json', $version);
      $composer = json_decode($composer_json, TRUE);

      // Set version info.
      $projects[$repo]['repo'] = $repo;
      $projects[$repo]['project_name'] = $composer['name'] ?? '';
      $projects[$repo]['project_type'] = $composer['type'] ?? '';
      $projects[$repo]['package_versions'] = isset($projects[$repo]['package_versions']) ? $projects[$repo]['package_versions'] + [$version] : [$version];
      $this->log('Found ' . $repo);

      // Recurse over required packages.
      if (isset($composer['require'])) {
        foreach ($composer['require'] as $package => $v) {
          if (str_starts_with($package, $this->organization . '/')) {
            $r = str_replace($this->organization . '/', '', $package);
            $v = $this->version_to_branch($v);
            if (!in_array($r, array_keys($projects))) {
              $projects += $this->get_composer_config($r, $v, $projects);
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
   * Log message.
   *
   * @param string $message
   *   Message to log
   * @param string level
   *   Message level to log. Defaults NOTICE
   */
  protected function log(string $message, string $level = 'NOTICE'): void {

    print $message . "\n";
  }

  /**
   * Update file in GitHub, creating file if it doesn't already exist.
   *
   * @param string $repo
   *   The name of the repository to get file from.
   * @param string $path
   *   Path to file or directory.
   * @param string $branch
   *   Branch or commit reference to get file from.
   * @param string $content
   *   Content of the file.
   * @param string $message
   *   Commit message.
   */
  protected function update_file(string $repo, string $path, string $branch, string $content, string $message): void {

    try {

      // Update file.
      $file_info = $this->client->repo()->contents()->show($this->organization, $repo, $path, $branch);
      $current_content = base64_decode($file_info['content']);
      if ($content != $current_content) {
        $this->client->repo()->contents()->update($this->organization, $repo, $path, $content, $message, $file_info['sha'], $branch);
        $this->log('Updated workflow in ' . $repo . ' on the ' . $branch . ' branch');
      }
    }
    catch (RuntimeException $e) {

      // Create file.
      $this->client->repo()->contents()->create($this->organization, $repo, $path, $content, $message, $branch);
      $this->log('Created workflow in ' . $repo . ' on the ' . $branch . ' branch');
    }
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
