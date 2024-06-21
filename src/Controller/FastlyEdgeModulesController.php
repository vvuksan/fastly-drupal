<?php

namespace Drupal\fastly\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Messenger\Messenger;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\fastly\Api;
use Drupal\fastly\EdgeModules;
use Drupal\fastly\Utility\FastlyEdgeModulesHelper;
use Drupal\fastly\VclHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class FastlyEdgeModulesController
 *
 * @package Drupal\fastly\Controller
 */
class FastlyEdgeModulesController extends ControllerBase
{

  /**
   * The Fastly API.
   *
   * @var \Drupal\fastly\Api
   */
  protected $api;

  /**
   * The Fastly VCL handler.
   *
   * @var \Drupal\fastly\VclHandler
   */
  protected $vclHandler;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\Messenger
   */
  protected $messenger;
  protected $request;
  protected $edgeModules;
  /**
   * Constructs a new FastlyEdgeModulesController object.
   *
   * @param \Drupal\fastly\Api $api
   *   The Fastly API.
   * @param \Drupal\fastly\VclHandler $vcl_handler
   *   The Fastly VCL handler.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Messenger\Messenger $messenger
   *   The messenger.
   */
  public function __construct(Api $api, VclHandler $vcl_handler, FileSystemInterface $file_system, Messenger $messenger, RequestStack $requestStack,EdgeModules $edge_modules){
    $this->api = $api;
    $this->vclHandler = $vcl_handler;
    $this->fileSystem = $file_system;
    $this->messenger = $messenger;
    $this->request = $requestStack->getCurrentRequest();
    $this->edgeModules = $edge_modules;
    $this->jsonModules = FastlyEdgeModulesHelper::getModulesJson();
  }
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('fastly.api'),
      $container->get('fastly.vclhandler'),
      $container->get('file_system'),
      $container->get('messenger'),
      $container->get('request_stack'),
      $container->get('fastly.edge_modules')
    );
  }
  /**
   * List all edge modules
   */
  public function getEdgeModulesJson(){
    $apiCheck = $this->api->testFastlyApiConnection();
    if (!$apiCheck['status']) {
      $this->messenger->addError($apiCheck['message']);
      return [];
    }
    $snippets = $this->vclHandler->getAllSnippets();
    $data['title'] = [
      '#markup' => $this->t('Fastly Edge Modules is a framework that allows you to enable specific functionality on Fastly without needing to write any VCL code. Below is a list of functions you can enable. Some may have additional options you can configure. To enable or disable click on the <strong>Manage</strong> button next to the functionality you want to enable, configure any available options then click <strong>Upload</strong>. To disable/remove the module click on <strong>Manage</strong> then click on <strong>Disable</strong>.')
    ];
    $data['modules'] = [
      '#type' => 'table',
      '#header' => [t('Module'), t('Description'), t('Status'), t('Operations')]
    ];
    foreach ($this->jsonModules as $id => $module) {
      $data['modules'][$id]['label'] = [
        '#plain_text' => $module['name']
      ];
      $data['modules'][$id]['description'] = [
        '#markup' => $module['description']
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
          'title' => $this->t('Manage'),
          'url' => Url::fromRoute('fastly.get_module_form_json', ['module' => $id]),
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
    $data['#attached']['library'][] = 'fastly/handlebars_forms';
    return $data;
  }
  /**
   * Title callback.
   *
   * @param $module
   * @return array
   */
  public function titleCallback($module){
    return $this->jsonModules[$module]['name'];
  }
  public function edgeModuleForm($module){
    if($this->hasAcl($module) && !$this->aclUploaded()){
      $form['error'] = [
        '#markup' => $this->t('Please add ACL to the configuration on Fastly to be able to change settings.')
      ];
      return $form;
    }
    if($this->hasDictionaries($module) && !$this->dictionariesUploaded()){
      $form['error'] = [
        '#markup' => $this->t('Please add Dictionaries to the configuration on Fastly to be able to change settings.')
      ];
      return $form;
    }
    $content = $this->edgeModules->renderForm($module);
    $form['#attached']['library'][] = 'fastly/handlebars_forms';
    $form['content'] = [
      '#type' => 'markup',
      '#markup' => Markup::create($content),
      '#allowed_tags' => TRUE,
      '#cache' => [
        'max-age' => 0,
      ],
    ];
    return $form;
  }
  public function processEdgeModuleFormSubmission(){
    $this->vclHandler->upload_snippet();
    $redirectUrl = Url::fromRoute('fastly.edge_modules')->toString();
    return new RedirectResponse($redirectUrl);
  }
  public function disableEdgeModuleFormSubmission(){
    $formData = $this->request->request->all();
    $this->vclHandler->removeEdgeModule($formData['module_name']);
    $redirectUrl = Url::fromRoute('fastly.edge_modules')->toString();
    return new RedirectResponse($redirectUrl);
  }
  private function hasAcl($moduleId){
    $moduleProperties = $this->jsonModules[$moduleId]['properties'];
    foreach($moduleProperties as $i => $property){
      if($property['type'] == 'acl'){
        return TRUE;
      }
      elseif($property['type'] == 'group'){
        foreach($property['properties'] as $key => $subProperty){
          if($subProperty['type'] == 'acl'){
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }
  private function aclUploaded(){
    $acls = $this->vclHandler->getAllAcls();
    if(count($acls)){
      return TRUE;
    }
    return FALSE;
  }
  private function hasDictionaries($moduleId){
    $moduleProperties = $this->jsonModules[$moduleId]['properties'];
    foreach($moduleProperties as $i => $property){
      if($property['type'] == 'dict'){
        return TRUE;
      }
      elseif($property['type'] == 'group'){
        foreach($property['properties'] as $key => $subProperty){
          if($subProperty['type'] == 'dict'){
            return TRUE;
          }
        }
      }
    }
    return FALSE;
  }
  private function dictionariesUploaded(){
    $dict = $this->vclHandler->getAllDictionaries();
    if(count($dict)){
      return TRUE;
    }
    return FALSE;
  }
}
