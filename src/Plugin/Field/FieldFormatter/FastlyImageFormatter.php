<?php

namespace Drupal\fastly\Plugin\Field\FieldFormatter;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\image\Plugin\Field\FieldFormatter\ImageFormatterBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Cache\Cache;

/**
 * Plugin implementation of the fastly 'image' formatter.
 *
 * @FieldFormatter(
 *   id = "fastly_image",
 *   label = @Translation("Fastly Image Formatter"),
 *   field_types = {
 *     "image"
 *   },
 *   quickedit = {
 *     "editor" = "image"
 *   }
 * )
 */
class FastlyImageFormatter extends ImageFormatterBase implements ContainerFactoryPluginInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The image style entity storage.
   *
   * @var \Drupal\image\ImageStyleStorageInterface
   */
  protected $imageStyleStorage;

  /**
   * Url Generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Constructs an ImageFormatter object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityStorageInterface $image_style_storage
   *   The image style storage.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   Url Generator.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, AccountInterface $current_user, EntityStorageInterface $image_style_storage, FileUrlGeneratorInterface $file_url_generator) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->currentUser = $current_user;
    $this->imageStyleStorage = $image_style_storage;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('current_user'),
      $container->get('entity_type.manager')->getStorage('image_style'),
      $container->get('file_url_generator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
        'width' => '',
        'height' => '',
        'image_link' => '',
        'dpr' => '',
        'fit'=> '',
        'bg' => '',
        'bg-color' => '',
        'brightness' => '',
        'contrast' => '',
        'saturation' => '',
        'blur' => '',
        'format' => '',
        'quality' => '',
        'optimize' => '',
        'auto' => '',
        'enable' => '',
        'disable' => '',
        'resize-filter' => '',
      ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element['width'] = [
      '#title' => t('Width'),
      '#type' => 'number',
      '#description' => $this->t('Entering integer is setting image in pixels (1-8192) and entering float 0.0-0.99 is setting percentage'),
      '#min' => 0,
      '#max' => 8192,
      '#step' => 0.01,
      '#default_value' => $this->getSetting('width'),
    ];
    $element['height'] = [
      '#title' => t('Height'),
      '#type' => 'number',
      '#description' => $this->t('Entering integer is setting image in pixels (1-8192) and entering float 0.0-0.99 is setting percentage'),
      '#min' => 0,
      '#max' => 8192,
      '#step' => 0.01,
      '#default_value' => $this->getSetting('height'),
    ];

    $element['quality'] = [
      '#title' => t('Quality'),
      '#type' => 'number',
      '#description' => $this->t('Parameter enables control over the compression level for lossy file-formatted images. More details <a href=":url" target="_blank">here</a>.', [':url' => 'https://docs.fastly.com/en/image-optimization-api/quality']),
      '#default_value' => $this->getSetting('quality'),
    ];

    $element['optimize'] = [
      '#title' => t('Optimize'),
      '#type' => 'select',
      '#description' => $this->t('Automatically applies optimal quality compression to produce an output image with as much visual fidelity as possible, while minimizing the file size. More details <a href=":url" target="_blank">here</a>.', [':url' => 'https://docs.fastly.com/en/image-optimization-api/enable']),
      '#default_value' => $this->getSetting('optimize'),
      '#empty_option' => t('None'),
      '#options' => [
        'low' => 'low',
        'medium' => 'medium',
        'high' => 'high'
      ],
    ];

    $element['dpr'] = [
      '#title' => t('Device pixel ratio'),
      '#type' => 'select',
      '#description' => $this->t('Parameter adds the ability to serve correctly sized images for devices that expose a device pixel ratio. More details <a href=":url" target="_blank">here</a>.', [':url' => 'https://docs.fastly.com/en/image-optimization-api/dpr']),
      '#options' => [
        '1' => '1x',
        '1.5' => '1.5x',
        '2' => '2',
        '3' => '3',
        '4' => '4',
      ],
      '#empty_option' => t('None'),
      '#default_value' => $this->getSetting('dpr'),
    ];
    $element['fit'] = [
      '#title' => t('Fit'),
      '#type' => 'select',
      '#description' => $this->t('Set how the image will fit within the size bounds provided. Parameter controls how the image will be constrained within the provided size (width | height) values.'),
      '#default_value' => $this->getSetting('fit'),
      '#empty_option' => t('None'),
      '#options' => [
        'bounds' => 'bounds',
        'cover' => 'cover',
        'crop' => 'crop',
      ],
    ];
    // @todo trim and crop

    $element['bg-color'] = [
      '#title' => t('Background color of an image'),
      '#type' => 'textfield',
      '#description' => $this->t('Background color of an image in HEX value. More details <a href=":url" target="_blank">here</a>.', [':url' => 'https://docs.fastly.com/en/image-optimization-api/bg-color']),
      '#default_value' => $this->getSetting('bg-color'),
    ];

    $element['brightness'] = [
      '#title' => t('Brightness'),
      '#type' => 'number',
      '#description' => $this->t('Brightness of the output image. More details <a href=":url" target="_blank">here</a>.', [':url' => 'https://docs.fastly.com/en/image-optimization-api/brightness']),
      '#default_value' => $this->getSetting('saturation'),
      '#min' => -100,
      '#max' => 100,
      '#step' => 0.01,
    ];

    $element['contrast'] = [
      '#title' => t('Contrast'),
      '#type' => 'number',
      '#description' => $this->t('The contrast parameter increases or decreases the difference between the darkest and lightest tones in an image. More details <a href=":url" target="_blank">here</a>.', [':url' => 'https://docs.fastly.com/en/image-optimization-api/contrast']),
      '#default_value' => $this->getSetting('saturation'),
      '#min' => -100,
      '#max' => 100,
      '#step' => 0.01,
    ];

    $element['saturation'] = [
      '#title' => t('Saturation'),
      '#type' => 'number',
      '#description' => $this->t('The saturation parameter increases or decreases the intensity of the colors in an image. More details <a href=":url" target="_blank">here</a>.', [':url' => 'https://docs.fastly.com/en/image-optimization-api/saturation']),
      '#default_value' => $this->getSetting('saturation'),
      '#min' => -100,
      '#max' => 100,
      '#step' => 0.01,
    ];

    // @todo Sharpen needs to be on the widget ?
    $element['blur'] = [
      '#title' => t('Blur'),
      '#type' => 'number',
      '#description' => $this->t('Blurriness of the output image. Decimal number between 1 and 1000. More details <a href=":url" target="_blank">here</a>.', [':url' => 'https://docs.fastly.com/en/image-optimization-api/blur']),
      '#default_value' => $this->getSetting('blur'),
    ];

    $element['format'] = [
      '#title' => t('Format'),
      '#type' => 'select',
      '#description' => $this->t('The format parameter enables the source image to be converted (a.k.a., "transcoded") from one encoded format to another. This is useful when the source image has been saved in a sub-optimal file format that hinders performance.. More details <a href=":url" target="_blank">here</a>.', [':url' => 'https://docs.fastly.com/en/image-optimization-api/enable']),
      '#default_value' => $this->getSetting('format'),
      '#empty_option' => t('None'),
      '#options' => [
        'gif' => $this->t('Graphics Interchange Format'),
        'png' => $this->t('Portable Network Graphics'),
        'png8' => $this->t('Portable Network Graphics palette image with 256 colors and 8-bit transparency'),
        'png24' => $this->t('Portable Network Graphics RGB image with 16.8 million colors'),
        'png32' => $this->t('Portable Network Graphics RGBA image with 16.8 million colors and 8-bit transparency'),
        'jpg' => $this->t('JPEG'),
        'pjpg' => $this->t('Progressive JPEG'),
        'bjpg' => $this->t('Baseline JPEG'),
        'webp' => $this->t('WebP'),
        'webpll' => $this->t('WebP (Lossless)'),
        'webply' => $this->t('WebP (Lossy)'),
      ],
    ];

    //@todo frame
    $element['auto'] = [
      '#title' => t('Automatic optimization'),
      '#type' => 'select',
      '#description' => $this->t('The auto parameter enables functionality that automates certain optimization features, like WebP image format support. More details <a href=":url" target="_blank">here</a>.', [':url' => 'https://docs.fastly.com/en/image-optimization-api/auto']),
      '#default_value' => $this->getSetting('auto'),
      '#empty_option' => t('None'),
      '#options' => [
        'webp' => 'webp',
      ],
    ];
    $element['resize-filter'] = [
      '#title' => t('Resize filter'),
      '#type' => 'select',
      '#description' => $this->t('Enables control over the resizing filter used to generate a new image with a higher or lower number of pixels. More details <a href=":url" target="_blank">here</a>.', [':url' => 'https://docs.fastly.com/en/image-optimization-api/resize-filter']),
      '#default_value' => $this->getSetting('resize-filter'),
      '#empty_option' => t('None'),
      '#options' => [
        'nearest' => 'nearest',
        'bilinear' => 'bilinear',
        'bicubic' => 'bicubic',
        'lanczos2' => 'lanczos2',
        'lanczos3' => 'lanczos3',
      ],
    ];


    $link_types = [
      'content' => t('Content'),
      'file' => t('File'),
    ];
    $element['image_link'] = [
      '#title' => t('Link image to'),
      '#type' => 'select',
      '#default_value' => $this->getSetting('image_link'),
      '#empty_option' => t('Nothing'),
      '#options' => $link_types,
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $settings = $this->getSettingsForParameters();
    foreach($settings as $option){
      if ($value = $this->getSetting($option)) {
        $summary[] = $option . ' : ' . $value;
      }
    }

    $link_types = [
      'content' => t('Linked to content'),
      'file' => t('Linked to file'),
    ];
    // Display this setting only if image is linked.
    $image_link_setting = $this->getSetting('image_link');
    if (isset($link_types[$image_link_setting])) {
      $summary[] = $link_types[$image_link_setting];
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $files = $this->getEntitiesToView($items, $langcode);

    // Early opt-out if the field is empty.
    if (empty($files)) {
      return $elements;
    }

    $url = NULL;
    $image_link_setting = $this->getSetting('image_link');
    // Check if the formatter involves a link.
    if ($image_link_setting == 'content') {
      $entity = $items->getEntity();
      if (!$entity->isNew()) {
        $url = $entity->toUrl();
      }
    }
    elseif ($image_link_setting == 'file') {
      $link_file = TRUE;
    }

    // Collect cache tags to be added for each item in the field.
    $base_cache_tags = [];

    foreach ($files as $delta => $file) {
      $cache_contexts = [];
      if (isset($link_file)) {
        $image_uri = $file->getFileUri();
        // @todo Wrap in file_url_transform_relative(). This is currently
        // impossible. As a work-around, we currently add the 'url.site' cache
        // context to ensure different file URLs are generated for different
        // sites in a multisite setup, including HTTP and HTTPS versions of the
        // same site. Fix in https://www.drupal.org/node/2646744.
        $url = Url::fromUri($this->fileUrlGenerator->generateAbsoluteString($image_uri));
        $cache_contexts[] = 'url.site';
      }
      $cache_tags = Cache::mergeTags($base_cache_tags, $file->getCacheTags());

      // Extract field item attributes for the theme function, and unset them
      // from the $item so that the field template does not re-render them.
      $item = $file->_referringItem;
      $item_attributes = $item->_attributes;
      unset($item->_attributes);

      $settings = $this->getSettingsForParameters();
      $query = [];
      foreach($settings as $option){
        if ($value = $this->getSetting($option)) {
          if($value && ($option == 'width' || $option == 'height')) {
            $item->set($option, $value);
          }
          $query[$option] = $value;
        }
      }
      if($query){
        $uri = $file->getFileUri();
        $image_url = Url::fromUri($this->fileUrlGenerator->generateAbsoluteString($uri));
        $image_url->setOption('query', $query);
        $file->setFileUri($image_url->toUriString());
      }

      $elements[$delta] = [
        '#theme' => 'image_formatter',
        '#item' => $item,
        '#item_attributes' => $item_attributes,
        '#image_style' => '',
        '#url' => $url,
        '#cache' => [
          'tags' => $cache_tags,
          'contexts' => $cache_contexts,
        ],
      ];
    }

    return $elements;
  }

  /**
   * Returns settings machine names that are used to set API parameters.
   *
   * @return string[]
   */
  public function getSettingsForParameters(){
    return [
      'width',
      'height',
      'dpr',
      'fit',
      'bg-color',
      'brightness',
      'contrast',
      'saturation',
      'blur',
      'format',
      'quality',
      'optimize',
      'auto',
      'resize-filter',
      'pad'
    ];
  }
}
