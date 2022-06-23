<?php

namespace LocalGovDrupal\GithubWorkflowManager\Commands;

use Github\AuthMethod;
use Github\Client;
use Github\Exception\RuntimeException;
use LocalGovDrupal\GithubWorkflowManager\Config;
use LocalGovDrupal\GithubWorkflowManager\TemplateRenderer\LocalGovRenderer;
use LocalGovDrupal\GithubWorkflowManager\TemplateRenderer\TemplateRendererInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;


/**
 * Update GitHub workflow files.
 */
#[AsCommand(
  name: 'update',
  description: 'Update Github workflow files.',
  aliases: ['up'],
  hidden: false
)]
class WorkflowUpdater extends Command {

  /**
   * GitHub API timeout.
   *
   * @var int
   */
  const TIMEOUT = 2;

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
   * Github access token.
   *
   * @var string
   */
  protected string $github_access_token;

  /**
   * Symfony IO.
   *
   * @var \Symfony\Component\Console\Style\SymfonyStyle
   */
  protected $io;

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
    parent::__construct();

    // Initialise config.
    $this->config = $config;
    $this->organization = $this->config->get('organization');
    $this->github_access_token = $github_access_token;

    // Initialise template renderer.
    $this->renderer = new LocalGovRenderer('templates', $this->config);
  }

  /**
   * @inheritdoc
   */
  protected function configure(): void {

    $this
      ->addArgument(
        'project',
        InputArgument::REQUIRED,
        'Base project, as listed in config.yml, to the apply workflow for. Try \'all\' to apply all listed workflows.'
      )
      ->addOption(
        'branch',
        'b',
        InputOption::VALUE_REQUIRED,
        'Override branch name in config when making changes.',
      )
      ->addOption(
        'check',
        'c',
        InputOption::VALUE_NONE,
        'Check mode. Don\'t make any changes.',
      )
//      ->addOption(
//        'diff',
//        'd',
//        InputOption::VALUE_NONE,
//        'Display diff of any changes.',
//      )
//      ->addOption(
//        'limit',
//        'l',
//        InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
//        'Limit to the listed repos.',
//      )
      ->addOption(
        'message',
        'm',
        InputOption::VALUE_REQUIRED,
        'Commit message',
        'Updated GitHub workflow'
      );
  }

  /**
   * @inheritdoc
   */
  public function execute(InputInterface $input, OutputInterface $output): int {

    // Initialise IO.
    $this->io = new SymfonyStyle($input, $output);

    // Check project.
    $project_to_update = $input->getArgument('project');
    $base_project_config = $this->config->get('base_projects');
    if ($project_to_update != 'all' && !in_array($project_to_update, array_keys($base_project_config))) {
      $this->log('Project not listed in config. Try \'all\' to apply all projects.', 'error');
      return Command::INVALID;
    }

    // Set flags.
    $this->check = $input->getOption('check');

    // Check branch name.
    if ($input->getOption('branch')) {
      $branch  = $input->getOption('branch');
    }
    else {
      $branch = $this->config->get_branch();
    }

    // Initialise GitHub client.
    if ($client = $this->authenticate($this->github_access_token)) {
      $this->client = $client;
    }
    else {
      return Command::FAILURE;
    }

    // Set projects to update.
    if ($project_to_update == 'all') {
      $updates = $base_project_config;
    }
    else {
      $updates = [
        $project_to_update => $base_project_config[$project_to_update],
      ];
    }

    // Iterate all the configured projects and supported versions.
    foreach($updates as $base_project => $config) {

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
              $this->log('OK ' . $project['repo'], 'comment');
              continue;
            }
          }
          catch (RuntimeException $e) {
            // File not found so continue with update.
          }

          // Update workflow and create PR for project.
          $this->create_branch($project['repo'], $branch, $project_version);
          $this->update_file($project['repo'], $workflow_file, $branch, $workflow, $input->getOption('message'));
          $this->create_pull_request($project['repo'], $project_version, $branch, 'Update ' . $workflow_file . ' workflow on ' . $project_version . ' branch');
        }
      }
    }

    return Command::SUCCESS;
  }

  /**
   * Authenticate with GitHub.
   *
   * @param $github_access_token string
   *   GitHub Access token with repo and workflow scopes.
   *
   * @return \Github\Client|FALSE
   */
  protected function authenticate(string $github_access_token): Client|FALSE {

    // Create client.
    $client = new Client();
    $client->authenticate($github_access_token, NULL, AuthMethod::ACCESS_TOKEN);

    // Check authentication.
    try {
      $client->organization()->show($this->organization);
    }
    catch (RuntimeException $e) {
      $this->log('Unable to authenticate with Github: ' . $e->getMessage(), 'error');
      return FALSE;
    }

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
      if (!$this->check) {
        $this->client->git()->references()->create($this->organization, $repo, $params);
        sleep(WorkflowUpdater::TIMEOUT);
      }
      $this->log('Created ' . $branch . ' branch in ' . $repo, 'comment');
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
      if (!$this->check) {
        $this->client->pullRequest()->create($this->organization, $repo, $params);
        sleep(WorkflowUpdater::TIMEOUT);
      }
      $this->log('Created pull request in ' . $repo, 'comment');
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
   * @param string type
   *   Message type to log. One of [info, warning, success, error]. Default info.
   */
  protected function log(string $message, string $type = 'info'): void {

    $this->io->write("<${type}>${message}</>", TRUE);
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
        if (!$this->check) {
          $this->client->repo()->contents()->update($this->organization, $repo, $path, $content, $message, $file_info['sha'], $branch);
          sleep(WorkflowUpdater::TIMEOUT);
        }
        $this->log('Updated workflow in ' . $repo . ' on the ' . $branch . ' branch', 'comment');
      }
    }
    catch (RuntimeException $e) {

      // Create file.
      if (!$this->check) {
        $this->client->repo()->contents()->create($this->organization, $repo, $path, $content, $message, $branch);
        sleep(WorkflowUpdater::TIMEOUT);
      }
      $this->log('Created workflow in ' . $repo . ' on the ' . $branch . ' branch', 'comment');
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
