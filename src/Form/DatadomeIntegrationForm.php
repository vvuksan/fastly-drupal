<?php
namespace Drupal\fastly\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fastly\VclHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * DatadomeIntegrationForm.
 *
 * @package Drupal\fastly\Form
 */
class DatadomeIntegrationForm extends ConfigFormBase {

  /**
   * @var VclHandler
   */
  protected $vclHandler;

  /**
   * Constructs a DatadomeIntegrationForm object.
   *
   * @param ConfigFactoryInterface $config_factory
   * @param \Drupal\fastly\VclHandler $vclHandler
   *   Vcl handler.
   */
  public function __construct(ConfigFactoryInterface $config_factory, VclHandler $vclHandler) {
    parent::__construct($config_factory);
    $this->vclHandler = $vclHandler;
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
    return 'datadome_integration';
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->config('fastly.edge_modules.datadome_integration');
    $form['datadome_api_key'] = [
      '#title' => $this->t('API Key'),
      '#description' => $this->t('API Key'),
      '#type' => 'textfield',
      "#default_value" => $config->get('datadome_api_key') ? $config->get('datadome_api_key') : '' ,
      '#required' => TRUE,
    ];
    $form['datadome_exclusion_ext'] = [
      '#title' => $this->t('Exclusion regex'),
      '#description' => $this->t('The regex that will be applied to req.url.path'),
      '#type' => 'textarea',
      "#default_value" => $config->get('datadome_exclusion_ext') ? $config->get('datadome_exclusion_ext') : '(?i)\.(avi|flv|mka|mkv|mov|mp4|mpeg|mpg|mp3|flac|ogg|ogm|opus|wav|webm|webp|bmp|gif|ico|jpeg|jpg|png|svg|svgz|swf|eot|otf|ttf|woff|woff2|css|less|js)$' ,
      '#required' => TRUE,
    ];
    $form['datadome_connect_timeout'] = [
      '#title' => $this->t('Connection timeout'),
      '#description' => $this->t('How long to wait for a timeout in milliseconds.'),
      '#type' => 'number',
      "#default_value" => $config->get('datadome_connect_timeout') ? $config->get('datadome_connect_timeout') : 300 ,
      '#required' => TRUE,
    ];
    $form['datadome_first_byte_timeout'] = [
      '#title' => $this->t('First byte timeout'),
      '#description' => $this->t('How long to wait for the first byte in milliseconds.'),
      '#type' => 'number',
      "#default_value" => $config->get('datadome_first_byte_timeout') ? $config->get('datadome_first_byte_timeout') : 100 ,
      '#required' => TRUE,
    ];
    $form['datadome_between_bytes_timeout'] = [
      '#title' => $this->t('Between bytes timeout'),
      '#description' => $this->t('How long to wait between bytes in milliseconds.'),
      '#type' => 'number',
      "#default_value" => $config->get('datadome_between_bytes_timeout') ? $config->get('datadome_between_bytes_timeout') : 100 ,
      '#required' => TRUE,
    ];
    $form['logging_endpoint'] = [
      '#title' => $this->t('Logging Endpoint for Debugging (Optional)'),
      '#description' => $this->t('Name of a logging endpoint that has been already set up in Fastly.'),
      '#type' => 'textfield',
      "#default_value" => $config->get('logging_endpoint') ? $config->get('logging_endpoint') : '' ,
      '#required' => FALSE,
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
      $this->config('fastly.edge_modules.datadome_integration')
        ->set('datadome_api_key', '')
        ->set('datadome_exclusion_ext', '')
        ->set('datadome_connect_timeout', '')
        ->set('datadome_first_byte_timeout', '')
        ->set('datadome_between_bytes_timeout', '')
        ->set('logging_endpoint', '')
        ->save();
    }else{
      $this->config('fastly.edge_modules.datadome_integration')
        ->set('datadome_api_key', $form_state->getValue('datadome_api_key'))
        ->set('datadome_exclusion_ext', $form_state->getValue('datadome_exclusion_ext'))
        ->set('datadome_connect_timeout', $form_state->getValue('datadome_connect_timeout'))
        ->set('datadome_first_byte_timeout', $form_state->getValue('datadome_first_byte_timeout'))
        ->set('datadome_between_bytes_timeout', $form_state->getValue('datadome_between_bytes_timeout'))
        ->set('logging_endpoint', $form_state->getValue('logging_endpoint'))
        ->save();
      $response = $this->vclHandler->uploadEdgeModule($this->getFormId(),[
        'datadome_api_key' => $form_state->getValue('datadome_api_key'),
        'datadome_exclusion_ext' => $form_state->getValue('datadome_exclusion_ext'),
        'datadome_connect_timeout' => $form_state->getValue('datadome_connect_timeout'),
        'datadome_first_byte_timeout' => $form_state->getValue('datadome_first_byte_timeout'),
        'datadome_between_bytes_timeout' => $form_state->getValue('datadome_between_bytes_timeout'),
        'logging_endpoint' => $form_state->getValue('logging_endpoint'),
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
    return ['fastly.edge_modules.datadome_integration'];
  }
}
