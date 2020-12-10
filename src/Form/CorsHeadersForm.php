<?php
namespace Drupal\fastly\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fastly\VclHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * CorsHeadersForm.
 *
 * @package Drupal\fastly\Form
 */
class CorsHeadersForm extends ConfigFormBase {

  /**
   * @var VclHandler
   */
  protected $vclHandler;

  /**
   * Constructs a CorsHeadersForm object.
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
    return 'cors_headers';
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->config('fastly.edge_modules.cors_headers');
    $form['origin'] = [
      '#title' => $this->t('Origins allowed'),
      '#description' => $this->t('What origins are allowed'),
      '#type' => 'select',
      '#options'=> [
        'anyone' => $this->t('Allow anyone (*)'),
        'regex-match' => $this->t('Regex matching set of origins. Do not supply http://'),
      ],
      "#default_value" => $config->get('origin'),
      '#required' => TRUE,
    ];
    $form['cors_allowed_methods'] = [
      '#title' => $this->t('Allowed HTTP methods'),
      '#description' => $this->t('Allowed HTTP Methods that requestor can use'),
      '#type' => 'textfield',
      "#default_value" => !$config->get('cors_allowed_methods') ? "GET,HEAD,POST,OPTIONS" : $config->get('cors_allowed_methods'),
      '#required' => TRUE,
    ];
    $form['cors_allowed_origins_regex'] = [
      '#title' => $this->t('Regex matching origins'),
      '#description' => $this->t('Regex matching origins that are allowed to access this service'),
      '#type' => 'textfield',
      "#default_value" => $config->get('cors_allowed_origins_regex'),
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
      $this->config('fastly.edge_modules.cors_headers')
        ->set('origin', '')
        ->set('cors_allowed_methods', '')
        ->set('cors_allowed_origins_regex', '')
        ->save();
    }else{
      $this->config('fastly.edge_modules.cors_headers')
        ->set('origin', $form_state->getValue('origin'))
        ->set('cors_allowed_methods', $form_state->getValue('cors_allowed_methods'))
        ->set('cors_allowed_origins_regex', $form_state->getValue('cors_allowed_origins_regex'))
        ->save();
      $response = $this->vclHandler->uploadEdgeModule($this->getFormId(),[
        'origin' => $form_state->getValue('origin'),
        'cors_allowed_methods' => $form_state->getValue('cors_allowed_methods'),
        'cors_allowed_origins_regex' => $form_state->getValue('cors_allowed_origins_regex'),
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
    return ['fastly.edge_modules.cors_headers'];
  }
}
