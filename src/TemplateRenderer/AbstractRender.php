<?php

namespace LocalGovDrupal\GithubWorkflowManager\TemplateRenderer;

use LocalGovDrupal\GithubWorkflowManager\Config;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Abstract renderer for workflow Twig template.
 */
abstract class AbstractRender implements TemplateRendererInterface {

  /**
   * Config object.
   *
   * @var \LocalGovDrupal\GithubWorkflowManager\Config
   */
  protected Config $config;

  /**
   * Twig environment.
   *
   * @var \Twig\Environment
   */
  protected Environment $twig;

  /**
   * Template variables array.
   *
   * @var array
   */
  protected array $vars = [];

  /**
   * Initialise the renderer.
   *
   * @param $template_path string
   *   Path to templates directory.
   */
  function __construct(string $template_path, Config $config) {

    $this->config = $config;
    $loader = new FilesystemLoader($template_path);
    $this->twig = new Environment($loader);
  }

  /**
   * @inheritdoc
   */
  abstract public function set_variables(array $vars): array;

  /**
   * @inheritdoc
   */
  public function render(string $template): string {

    $wrapped_template = $this->twig->load($template);
    return $wrapped_template->render($this->vars);
  }

}
