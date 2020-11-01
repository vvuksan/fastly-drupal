<?php
namespace Drupal\fastly\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fastly\VclHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * CountryblockForm.
 *
 * @package Drupal\fastly\Form
 */
class CountryblockForm extends ConfigFormBase {

  /**
   * @var VclHandler
   */
  protected $vclHandler;

  /**
   * Constructs a CountryblockForm object.
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
    return 'countryblock';
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->config('fastly.edge_modules.countryblock');
    $form['countries'] = [
      '#title' => $this->t('Countries'),
      '#description' => $this->t('List countries to block, using [ISO-3166-1 alpha 2](https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2) codes, separated by spaces, eg `us de cn`'),
      '#type' => 'textfield',
      "#default_value" => $config->get('countries'),
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
      $this->config('fastly.edge_modules.countryblock')
        ->set('countries', '')
        ->save();
    }else{
      $this->config('fastly.edge_modules.countryblock')
        ->set('countries', $form_state->getValue('countries'))
        ->save();
      $response = $this->vclHandler->uploadEdgeModule($this->getFormId(),[
        'countries' => explode( ' ', $form_state->getValue('countries')),
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
    return ['fastly.edge_modules.countryblock'];
  }
}
