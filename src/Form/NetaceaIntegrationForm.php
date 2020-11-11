<?php
namespace Drupal\fastly\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fastly\VclHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * NetaceaIntegrationForm.
 *
 * @package Drupal\fastly\Form
 */
class NetaceaIntegrationForm extends ConfigFormBase {

  /**
   * @var VclHandler
   */
  protected $vclHandler;

  /**
   * Constructs a NetaceaIntegrationForm object.
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
    return 'netacea_integration';
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->config('fastly.edge_modules.netacea_integration');
    $form['api_key'] = [
      '#title' => $this->t('Netacea API Key'),
      '#description' => $this->t('API Key'),
      '#type' => 'textfield',
      "#default_value" => $config->get('api_key'),
      '#required' => TRUE,
    ];
    $form['secret'] = [
      '#title' => $this->t('Netacea Secret'),
      '#description' => $this->t('Secret'),
      '#type' => 'textfield',
      "#default_value" => $config->get('secret'),
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
    $data['api_key'] = $form_state->getValue('api_key');
    $data['secret'] = $form_state->getValue('secret');
    $triggeringElement = $form_state->getTriggeringElement();
    if(str_contains($triggeringElement['#id'],'disable')){
      if($this->vclHandler->removeEdgeModule($this->getFormId())){
        $this->messenger()->addMessage(t('Edge module successfully disabled'));
      }else{
        $this->messenger()->addMessage(t('Error occurred while disabling module'));
      }
      $this->config('fastly.edge_modules.netacea_integration')
        ->set('api_key', '')
        ->set('secret', '')
        ->save();
    }else{
      $this->config('fastly.edge_modules.netacea_integration')
        ->set('api_key', $data['api_key'])
        ->set('secret', $data['secret'])
        ->save();
      if($this->vclHandler->uploadEdgeModule($this->getFormId(), $data)){
        $this->messenger()->addMessage(t('Edge module successfully enabled/updated'));
      }else{
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
    return ['fastly.edge_modules.netacea_integration'];
  }
}
