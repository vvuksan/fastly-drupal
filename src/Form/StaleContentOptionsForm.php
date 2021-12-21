<?php

namespace Drupal\fastly\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fastly\Services\Webhook;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class StaleContentOptionsForm.
 *
 * @package Drupal\fastly\Form
 */
class StaleContentOptionsForm extends ConfigFormBase {

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
   * Constructs a StaleContentOptionsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\fastly\Services\Webhook $webhook
   *   The Fastly webhook service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack object.
   */
  public function __construct(ConfigFactoryInterface $config_factory, Webhook $webhook, RequestStack $requestStack) {
    parent::__construct($config_factory);
    $this->webhook = $webhook;
    $this->baseUrl = $requestStack->getCurrentRequest()->getHost();
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
    return 'fastly_settings_stale_content_options';
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

    $form['stale_while_revalidate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Stale while revalidate'),
      '#description' => $this->t("Activate the stale-while-revalidate tag to improve experience for users attempting to access expired content."),
      '#default_value' => $config->get('stale_while_revalidate'),
    ];

    $form['stale_while_revalidate_value'] = [
      '#type' => 'number',
      '#description' => $this->t('Number of seconds to show stale content while revalidating cache. More details <a href=":serving_stale_content">here</a>.', [':serving_stale_content' => 'https://docs.fastly.com/guides/performance-tuning/serving-stale-content']),
      '#default_value' => $config->get('stale_while_revalidate_value') ?: 60,
      '#states' => [
        'visible' => [
          ':input[name="stale_while_revalidate"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="stale_while_revalidate"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['stale_if_error'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Stale if error'),
      '#description' => $this->t('Number of seconds to show stale content if the origin server becomes unavailable.'),
      '#default_value' => $config->get('stale_if_error'),
    ];

    $form['stale_if_error_value'] = [
      '#type' => 'number',
      '#description' => $this->t('Number of seconds to show stale content if the origin server becomes unavailable/returns errors. More details <a
href=":serving_stale_content">here</a>.', [':serving_stale_content' => 'https://docs.fastly.com/guides/performance-tuning/serving-stale-content']),
      '#default_value' => $config->get('stale_if_error_value') ?: 604800,
      '#states' => [
        'visible' => [
          ':input[name="stale_if_error"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="stale_if_error"]' => ['checked' => FALSE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('fastly.settings')
      ->set('stale_while_revalidate', $form_state->getValue('stale_while_revalidate'))
      ->set('stale_while_revalidate_value', $form_state->getValue('stale_while_revalidate_value'))
      ->set('stale_if_error', $form_state->getValue('stale_if_error'))
      ->set('stale_if_error_value', $form_state->getValue('stale_if_error_value'))
      ->save();
    $this->webhook->sendWebHook($this->t("Fastly module configuration changed on %base_url", ['%base_url' => $this->baseUrl]), "config_save");
    parent::submitForm($form, $form_state);
  }
}
