<?php

namespace Drupal\fastly\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fastly\Api;
use Drupal\fastly\CacheTagsHashInterface;
use Drupal\fastly\State;
use Drupal\fastly\VclHandler;
use Drupal\fastly\Services\Webhook;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class PurgeOptionsForm.
 *
 * @package Drupal\fastly\Form
 */
class PurgeOptionsForm extends ConfigFormBase {

  /**
   * Constants for the values of instant and soft purge methods.
   */
  const FASTLY_INSTANT_PURGE = 'instant';
  const FASTLY_SOFT_PURGE = 'soft';

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
    return 'fastly_settings_purge_options';
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
    $key_length = (int) $config->get('cache_tag_hash_length') ?: CacheTagsHashInterface::CACHE_TAG_HASH_LENGTH;

    $form['cache_tag_hash_length'] = [
      '#type' => 'number',
      '#min' => 4,
      '#max' => 10,
      '#title' => $this->t('Cache tag hash length'),
      '#description' => $this->t('For sites with more content, it may be necessary to increase the length of the hashed cache tags that are used for the <code>Surrogate-Key</code> header and when purging content. This is due to <a href=":hash_collisions">hash collisions</a> which will result in excessive purging of content if the key length is too short. The current key length of <strong>%key_length</strong> can provide %hash_total unique cache keys. Note that this number should not be as large as the total number of cache tags in your site, just high enough to avoid most collisions during purging. Also you can override this with environment variable <code>FASTLY_CACHE_TAG_HASH_LENGTH</code>.', [':hash_collisions' => 'https://en.wikipedia.org/wiki/Hash_table#Collision_resolution', '%key_length' => $key_length, '%hash_total' => pow(64, $key_length)]),
      '#default_value' => $key_length,
    ];


    $form['purge_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Purge method'),
      '#description' => $this->t("Switch between Fastly's Instant-Purge and Soft-Purge methods."),
      '#default_value' => $config->get('purge_method') ?: self::FASTLY_INSTANT_PURGE,
      '#options' => [
        self::FASTLY_INSTANT_PURGE => $this->t('Use instant purge'),
        self::FASTLY_SOFT_PURGE => $this->t('Use soft purge'),
      ],
    ];

    $form['purge_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable logging for purges'),
      '#description' => $this->t("Add a log entry whenever a purge is successful."),
      '#default_value' => $config->get('purge_logging'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $this->config('fastly.settings')
      ->set('purge_method', $form_state->getValue('purge_method'))
      ->set('purge_logging', $form_state->getValue('purge_logging'))
      ->set('cache_tag_hash_length', $form_state->getValue('cache_tag_hash_length'))
      ->save();

    $this->webhook->sendWebHook($this->t("Fastly module configuration changed on %base_url", ['%base_url' => $this->baseUrl]), "config_save");

    parent::submitForm($form, $form_state);
  }

}
