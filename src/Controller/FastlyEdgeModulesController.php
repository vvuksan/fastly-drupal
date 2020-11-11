<?php

namespace Drupal\fastly\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Url;
use Drupal\fastly\Utility\FastlyEdgeModulesHelper;
use Drupal\fastly\VclHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class FastlyEdgeModulesController
 *
 * @package Drupal\fastly\Controller
 */
class FastlyEdgeModulesController extends ControllerBase
{

  /**
   * @var VclHandler
   */
  protected $vclHandler;

  /**
   * @var FileSystem
   */
  protected $fileSystem;

  /**
   * FastlyEdgeModulesController constructor.
   *
   * @param VclHandler $vcl_handler
   * @param FileSystem $file_system
   */
  public function __construct(VclHandler $vcl_handler, FileSystem $file_system)
  {
    $this->vclHandler = $vcl_handler;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('fastly.vclhandler'),
      $container->get('file_system'),
    );
  }

  /**
   * List all edge modules
   */
  public function getEdgeModules()
  {
    $snippets = $this->vclHandler->getAllSnippets();

    $data['title'] = [
      '#markup' => 'Fastly Edge Modules is a framework that allows you to enable specific functionality on Fastly without needing to write any VCL code. Below is a list of functions you can enable. Some may have additional options you can configure. To enable or disable click on the <strong>Manage</strong> button next to the functionality you want to enable, configure any available options then click <strong>Upload</strong>. To disable/remove the module click on <strong>Manage</strong> then click on <strong>Disable</strong>.'
    ];

    $data['modules'] = [
      '#type' => 'table',
      '#header' => [t('Module'), t('Description'), t('Status'), t('Operations')]
    ];

    foreach (FastlyEdgeModulesHelper::getModules() as $id => $module) {

      $data['modules'][$id]['label'] = [
        '#plain_text' => $module['name']
      ];
      $data['modules'][$id]['description'] = [
        '#plain_text' => $module['description']
      ];
      $data['modules'][$id]['status'] = [
        '#plain_text' => t('Disabled')
      ];

      foreach ($snippets as $snippet) {
        if (substr($snippet->name, 0, strlen(FastlyEdgeModulesHelper::FASTLY_EDGE_MODULE_PREFIX . $id)) === FastlyEdgeModulesHelper::FASTLY_EDGE_MODULE_PREFIX . $id) {
          $date = DrupalDateTime::createFromFormat("Y-m-d\TH:i:s\Z", $snippet->updated_at);
          $data['modules'][$id]['status'] = [
            '#markup' => t('Enabled') . '<br>'
          ];
          $data['modules'][$id]['status'][] = [
            '#markup' => '<small><i>' . t('Uploaded: ') . $date->format('Y/m/d') . '</i></small>'
          ];
        }
      }
      $data['modules'][$id]['operations'] = [
        '#type' => 'operations',
        '#links' => [],
      ];
      if(isset($module['vcl'])){
        $data['modules'][$id]['operations']['#links']['edit'] = [
          'title' => 'Manage',
          'url' => Url::fromRoute('fastly.get_module_form', ['module' => $id]),
          'attributes' => [
            'class' => ['use-ajax'],
            'data-dialog-type' => 'modal',
            'data-dialog-options' => Json::encode([
              'width' => '40%',
            ]),
          ],
        ];
      }


    }
    return $data;
  }

  /**
   * Get module Form.
   *
   * @param $module
   * @return array
   */
  public function getModuleForm($module)
  {
    $modules = explode('_', $module);
    foreach ($modules as $key => $module) {
      $modules[$key] = ucfirst($module);
    }
    $string = implode("", $modules);
    return $this->formBuilder()->getForm('\Drupal\fastly\Form\\' . $string . 'Form');
  }

  /**
   * Title callback.
   *
   * @param $module
   * @return array
   */
  public function titleCallback($module)
  {
    $moduleConfig = FastlyEdgeModulesHelper::getModules();
    return $moduleConfig[$module]['name'];
  }


}
