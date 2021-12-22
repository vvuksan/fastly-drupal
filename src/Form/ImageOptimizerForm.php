<?php

namespace Drupal\fastly\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\OpenModalDialogCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\fastly\Api;
use Drupal\fastly\VclHandler;
use Drupal\fastly\Services\Webhook;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class ImageOptimizerForm.
 *
 * @package Drupal\fastly\Form
 */
class ImageOptimizerForm extends ConfigFormBase {

  /**
   * The Fastly API.
   *
   * @var \Drupal\fastly\Api
   */
  protected $api;

  /**
   * The Fastly VclHandler.
   *
   * @var \Drupal\fastly\VclHandler
   */
  protected $vclHandler;

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
  public function __construct(ConfigFactoryInterface $config_factory, Api $api, VclHandler $vclHandler, Webhook $webhook, RequestStack $requestStack) {
    parent::__construct($config_factory);
    $this->api = $api;
    $this->vclHandler = $vclHandler;
    $this->webhook = $webhook;
    $this->baseUrl = $requestStack->getCurrentRequest()->getHost();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('fastly.api'),
      $container->get('fastly.vclhandler'),
      $container->get('fastly.services.webhook'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'fastly_settings_image_optimizer';
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
    if (!$this->api->validatePurgeCredentials()) {
      $this->messenger()->addError($this->t('You need to have valid credentials before changing this configuration.'));
      return $form;
    }
    if (!$this->vclHandler->checkImageOptimizerStatus()) {
      $this->messenger()->addWarning($this->t('Please contact your sales rep or send an email to support@fastly.com to request image optimization activation for your fastly service!'));
      return $form;
    }

    if ($config->get('image_optimization') == 1 && !$this->api->ioEnabled()){
      $this->messenger()->addError($this->t('You have Fastly image optimization enabled in configuration but you don\'t have it available on service!'));
    }

    $form['io'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Turn on image optimizations and configure basic image settings. More details can be found <a target="_blank" href=":image_optimizer">here</a> ',[':image_optimizer' => 'https://docs.fastly.com/en/guides/about-fastly-image-optimizer#setting-up-image-optimization']),
    ];

    $form['image_optimization'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable image optimization'),
      '#description' => $this->t('Enabling image optimization will upload VCL file which will add X-Fastly-Imageopto-Api header to all images and thus enable Fastly Image optimization API.'),
      '#default_value' => $config->get('image_optimization'),
      '#ajax' => array(
        'callback' => array($this, 'updateIOCallback'),
        'event' => 'change',
      ),
      '#attached' => [
        'library' => [
          'core/jquery',
          'core/drupal.dialog.ajax',
        ],
      ],
    ];
    $form['optimize'] = [
      '#title' => t('Optimize'),
      '#type' => 'select',
      '#description' => $this->t('Automatically applies optimal quality compression to produce an output image with as much visual fidelity as possible, while minimizing the file size. More details <a href=":url" target="_blank">here</a>.', [':url' => 'https://docs.fastly.com/en/image-optimization-api/enable']),
      '#default_value' => $config->get('optimize'),
      '#empty_option' => t('None'),
      '#options' => [
        'low' => 'low',
        'medium' => 'medium',
        'high' => 'high'
      ],
      '#states' => [
        'visible' => array(
          ':input[name="image_optimization"]' => array('checked' => TRUE),
        ),
      ]
    ];
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced settings'),
      '#open' => FALSE,
    ];
    $form['advanced']['webp'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto WebP?'),
      '#default_value' => $config->get('webp') ?: TRUE,
      '#states' => [
        'visible' => array(
          ':input[name="image_optimization"]' => array('checked' => TRUE),
        ),
      ]
    ];
    $form['advanced']['webp_quality'] = [
      '#type' => 'number',
      '#title' => $this->t('Default WebP (lossy) quality.'),
      '#min' => 1,
      '#max' => 100,
      '#step' => 1,
      '#default_value' => $config->get('webp_quality') ?: 85,
      '#states' => [
        'visible' => array(
          ':input[name="image_optimization"]' => array('checked' => TRUE),
        ),
      ]
    ];
    $form['advanced']['jpeg_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Default JPEG format.'),
      '#options' => [
        'auto' => 'auto',
        'baseline' => 'baseline',
        'progressive' => 'progressive'
      ],
      '#default_value' => $config->get('jpeg_type') ?: 'auto',
      '#states' => [
        'visible' => array(
          ':input[name="image_optimization"]' => array('checked' => TRUE),
        ),
      ]
    ];
    $form['advanced']['jpeg_quality'] = [
      '#type' => 'number',
      '#title' => $this->t('Default JPEG quality.'),
      '#min' => 1,
      '#max' => 100,
      '#step' => 1,
      '#default_value' => $config->get('jpeg_quality') ?: 85,
      '#states' => [
        'visible' => array(
          ':input[name="image_optimization"]' => array('checked' => TRUE),
        ),
      ]
    ];
    $form['advanced']['upscale'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow upscaling?'),
      '#default_value' => $config->get('upscale') ?: FALSE,
      '#states' => [
        'visible' => array(
          ':input[name="image_optimization"]' => array('checked' => TRUE),
        ),
      ]
    ];
    $form['advanced']['resize_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Resize filter?'),
      '#options' => [
        'lanczos3' => 'lanczos3',
        'lanczos2' => 'lanczos2',
        'bicubic' => 'bicubic',
        'bilinear' => 'bilinear',
        'nearest' => 'nearest',
      ],
      '#default_value' => $config->get('resize_filter') ?: 'lanczos3',
      '#states' => [
        'visible' => array(
          ':input[name="image_optimization"]' => array('checked' => TRUE),
        ),
      ]
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory()->get('fastly.settings');

    if(empty($config->get('api_key'))) {
      $element = [];
      $form_state->setError($element, $this->t('You must set an API token <a href=":url">here</a> first',[':url' => Url::fromRoute('fastly.settings')->toString()]));
    }

    if ($form_state->getValue('image_optimization') == 1) {
      if(!$this->api->ioEnabled($config->get('service_id'))){
        $form_state->setErrorByName('image_optimization',$this->t('You cannot enable Fastly image optimization in configuration until you have it available on service!'));
      }
    }

    if ($form_state->getValue('image_optimization') && !$form_state->getValue('optimize')) {
      $form_state->setErrorByName('optimize', $this->t('You need to set default optimization of images to low, medium or high.'));
    }

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $originalImageOptimization =  $this->config('fastly.settings')->get('image_optimization');
    $originalOptimizeDefaults =  $this->config('fastly.settings')->get('optimize');

    $this->config('fastly.settings')
      ->set('image_optimization', $form_state->getValue('image_optimization'))
      ->set('webp', $form_state->getValue('webp'))
      ->set('webp_quality', $form_state->getValue('webp_quality'))
      ->set('jpeg_type', $form_state->getValue('jpeg_type'))
      ->set('jpeg_quality', $form_state->getValue('jpeg_quality'))
      ->set('upscale', $form_state->getValue('upscale'))
      ->set('resize_filter', $form_state->getValue('resize_filter'))
      ->set('optimize', $form_state->getValue('optimize'))
      ->save();

    //if optimisation is turned on then trigger optimization
    if ($form_state->getValue('image_optimization')) {
      $this->vclHandler->setImageOptimization([
        'webp' => boolval($form_state->getValue('webp')),
        'webp_quality' => $form_state->getValue('webp_quality'),
        'jpeg_type' => $form_state->getValue('jpeg_type'),
        'jpeg_quality' => $form_state->getValue('jpeg_quality'),
        'upscale' => boolval($form_state->getValue('upscale')),
        'resize_filter' => $form_state->getValue('resize_filter'),
        'optimize' => $form_state->getValue('optimize')
      ]);
    } elseif ($originalImageOptimization && !$form_state->getValue('image_optimization')){
      $this->vclHandler->removeImageOptimization();
    }
    // Reattach Image Optimization with new settings.
    if($originalImageOptimization && $originalOptimizeDefaults && $originalOptimizeDefaults != $form_state->getValue('optimize')){
      $this->vclHandler->removeImageOptimization();
      $this->vclHandler->setImageOptimization([
        'webp' => boolval($form_state->getValue('webp')),
        'webp_quality' => $form_state->getValue('webp_quality'),
        'jpeg_type' => $form_state->getValue('jpeg_type'),
        'jpeg_quality' => $form_state->getValue('jpeg_quality'),
        'upscale' => boolval($form_state->getValue('upscale')),
        'resize_filter' => $form_state->getValue('resize_filter'),
        'optimize' => $form_state->getValue('optimize')
      ]);
    }

    $this->webhook->sendWebHook($this->t("Fastly module configuration changed on %base_url", ['%base_url' => $this->baseUrl]), "config_save");

    parent::submitForm($form, $form_state);
  }

  /**
   * Image optimization
   * @param array $form
   * @param FormStateInterface $form_state
   * @return AjaxResponse
   */
  public function updateIOCallback(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
    $ajax_response = new AjaxResponse();
    $form['#attached']['library'][] = 'core/jquery';
    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $image_optimizer = $form_state->getValue('image_optimization');
    if($image_optimizer){
      $title = $this->t('Are you sure you want to enable Image optimizer?');
      $content = $this->t('Enabling image optimization will upload VCL file which will add X-Fastly-Imageopto-Api header to all images and thus enable Fastly Image optimization API. Please set default image optimization to low, medium or high');
      $ajax_response->addCommand(new OpenModalDialogCommand($title, $content,[
        'width' => '700',
        'buttons' => [
          'confirm' => [
            'text' => $this->t('Yes'),
            'onclick' => 'jQuery("#drupal-modal").dialog("close");',
          ],
          'cancel' => [
            'text' => $this->t('No'),
            'onclick' => 'jQuery("#drupal-modal").dialog("close"); jQuery("#edit-image-optimization").click();',
          ]
        ]
      ]));
    }
    return $ajax_response;
  }

}
