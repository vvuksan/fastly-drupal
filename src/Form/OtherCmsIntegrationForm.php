<?php
namespace Drupal\fastly\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fastly\VclHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * OtherCmsIntegrationForm.
 *
 * @package Drupal\fastly\Form
 */
class OtherCmsIntegrationForm extends ConfigFormBase {

  /**
   * @var VclHandler
   */
  protected $vclHandler;

  /**
   * @var array
   */
  protected $dictionaries = [];

  /**
   * Constructs a OtherCmsIntegrationForm object.
   *
   * @param ConfigFactoryInterface $config_factory
   * @param \Drupal\fastly\VclHandler $vclHandler
   *   Vcl handler.
   */
  public function __construct(ConfigFactoryInterface $config_factory, VclHandler $vclHandler) {
    parent::__construct($config_factory);
    $this->vclHandler = $vclHandler;
    $data = $this->vclHandler->getAllDictionaries();
    foreach($data as $dict){
      $this->dictionaries[$dict['name']] = $dict['name'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('config.factory'),
      $container->get('fastly.vclhandler'),
    );
  }

  /**
   * {@inheritDoc}
   */
  public function getFormId()
  {
    return 'other_cms_integration';
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->config('fastly.edge_modules.other_cms_integration');
    $dict = $this->vclHandler->getAllDictionaries();
    $form['urls_dict'] = [
      '#title' => $this->t('URL prefixes Dictionary'),
      '#description' => $this->t('Pick the dictionary that contains a list of prefixes that should be sent to another CMS/backend'),
      '#type' => 'select',
      '#options'=> $this->dictionaries,
      "#default_value" => $config->get('urls_dict'),
      '#required' => TRUE,
    ];
    $form['override_backend_hostname'] = [
      '#title' => $this->t('Override Backend Hostname'),
      '#description' => $this->t('Optional hostname to send to the backend. DEFAULT doesn\'t modify Host header sent to the origin.'),
      '#type' => 'textfield',
      "#default_value" => $config->get('override_backend_hostname') ? $config->get('override_backend_hostname') : 'DEFAULT',
      '#required' => TRUE,
    ];

    $form['disable'] = [
      '#type' => 'submit',
      '#value' => $this->t('Disable'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $triggeringElement = $form_state->getTriggeringElement();
    if(str_contains($triggeringElement['#id'],'disable')){
      if($this->vclHandler->removeEdgeModule($this->getFormId())){
        $this->messenger()->addMessage(t('Edge module successfully disabled'));
      }else{
        $this->messenger()->addMessage(t('Error occurred while disabling module'));
      }
      $this->config('fastly.edge_modules.other_cms_integration')
        ->set('urls_dict', '')
        ->set('override_backend_hostname', '')
        ->save();
    }else{
      $this->config('fastly.edge_modules.other_cms_integration')
        ->set('urls_dict', $form_state->getValue('urls_dict'))
        ->set('override_backend_hostname', $form_state->getValue('override_backend_hostname'))
        ->save();
      $response = $this->vclHandler->uploadEdgeModule($this->getFormId(),[
        'urls_dict' => $form_state->getValue('urls_dict'),
        'override_backend_hostname' => $form_state->getValue('override_backend_hostname'),
      ]);
      if($response) {
        $this->messenger()->addMessage(t('Edge module successfully enabled/updated'));
      } else{
        $this->messenger()->addMessage(t('There were errors while trying to enable/update Edge module'));
      }
    }
    $form_state->setRedirect('fastly.edge_modules');
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return ['fastly.edge_modules.other_cms_integration'];
  }
}
