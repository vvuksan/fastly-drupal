<?php

namespace Drupal\fastly\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fastly\Services\Webhook;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class WebhookForm.
 *
 * @package Drupal\fastly\Form
 */
class WebhookForm extends ConfigFormBase {

  /**
   * The Fastly webhook service.
   *
   * @var \Drupal\fastly\Services\Webhook
   */
  protected $webhook;

  /**
   * Host of current request.
   *
   * @var string
   */
  protected $baseUrl;

  /**
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, Webhook $webhook, RequestStack $request_stack) {
    parent::__construct($config_factory);
    $this->webhook = $webhook;
    $this->baseUrl = $request_stack->getCurrentRequest()->getHost();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('fastly.services.webhook'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fastly_settings.webhook';
  }


  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['fastly.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('fastly.settings');

    $form['webhook']['webhook_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Webhooks'),
      '#description' => $this->t("Enables or disables webhook"),
      '#default_value' => $config->get('webhook_enabled'),
    ];

    $form['webhook']['webhook_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Webhook URL'),
      '#default_value' => $config->get('webhook_url'),
      '#required' => FALSE,
      '#description' => $this->t("Incoming WebHook URL"),
      '#states' => [
        'visible' => [
          ':input[name="webhook_enabled"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="webhook_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['webhook']['webhook_notifications'] = [
      '#type' => 'select',
      '#title' => $this->t('Send notifications for this events'),
      '#description' => $this->t('Choose which notifications to push to your webhook'),
      '#options' => [
        'purge_keys'  => $this->t('Purge by keys'),
        'purge_all'   => $this->t('Purge all'),
        'vcl_update'  => $this->t('VCL update'),
        'config_save'  => $this->t('Config save'),
        'maintenance_page' => $this->t('Maintenance page upload'),
      ],
      '#default_value' => $config->get('webhook_notifications'),
      '#multiple' => TRUE,
      '#size' => 5,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('fastly.settings')
      ->set('webhook_url', $form_state->getValue('webhook_url'))
      ->set('webhook_enabled', $form_state->getValue('webhook_enabled'))
      ->set('webhook_notifications', $form_state->getValue('webhook_notifications'))
      ->save();
    $this->webhook->sendWebHook($this->t("Fastly module configuration changed on %base_url", ['%base_url' => $this->baseUrl]), "config_save");
    parent::submitForm($form, $form_state);
  }

}
