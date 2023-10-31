<?php
namespace Drupal\fastly\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fastly\Api;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * OptimizationForm.
 *
 * @package Drupal\fastly\Form
 */
class OptimizationForm extends FormBase {

  /**
   * Config Factory.
   *
   * @var ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Api.
   *
   * @var \Drupal\fastly\Api
   */
  protected $api;

  /**
   * Service ID.
   *
   * @var array|false|mixed|string|null
   */
  protected $serviceId;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    Api $api,
  ) {
    $this->configFactory = $config_factory;
    $this->api = $api;
    $serviceIdWithOverrides = $this->configFactory->get('fastly.settings')->get('service_id');
    $this->serviceId = getenv('FASTLY_API_SERVICE') ?: $serviceIdWithOverrides;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('config.factory'),
      $container->get('fastly.api'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'optimization';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Check'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $serviceDetails = $this->api->getDetails($this->serviceId);
    if ($serviceDetails && isset($serviceDetails->active_version->backends)) {
      foreach ($serviceDetails->active_version->backends as $backend) {
        if ($backend->shield) {
          $this->messenger()->addStatus(
            $this->t("Host @name(@address) has shielding set to: @shield.",
              [
              "@name" => $backend->name,
              "@address" => $backend->address,
              "@shield" => $backend->shield,
              ]
            ));
        }
        else {
          $this->messenger()->addWarning(
            $this->t("Host @name(@address) doesn't have shielding set. Check <a target='_blank' href='@link'>here</a> for more info.",
            [
              "@name" => $backend->name,
              "@address" => $backend->address,
              "@link" => "https://docs.fastly.com/en/guides/shielding"
            ]
          ));
        }
      }
    }
  }
}
