<?php
namespace Drupal\fastly\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\fastly\VclHandler;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ForceCacheMissOnHardReloadForAdminsForm.
 *
 * @package Drupal\fastly\Form
 */
class ForceCacheMissOnHardReloadForAdminsForm extends ConfigFormBase {

  /**
   * @var VclHandler
   */
  protected $vclHandler;

  /**
   * @var array All Acls.
   */
  protected $acls;

  /**
   * Constructs a ForceCacheMissOnHardReloadForAdminsForm object.
   *
   * @param ConfigFactoryInterface $config_factory
   * @param \Drupal\fastly\VclHandler $vclHandler
   *   Vcl handler.
   */
  public function __construct(ConfigFactoryInterface $config_factory, VclHandler $vclHandler) {
    parent::__construct($config_factory);
    $this->vclHandler = $vclHandler;
    $acls = $this->vclHandler->getAllAcls();
    foreach($acls as $acl){
      $this->acls[$acl['name']] = $acl['name'];
    }
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
    return 'force_cache_miss_on_hard_reload_for_admins';
  }

  /**
   * {@inheritDoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    if ($this->acls) {
      $config = $this->config('fastly.edge_modules.force_cache_miss_on_hard_reload_for_admins');
      $form['acl'] = [
        '#title' => $this->t('ACL'),
        '#description' => $this->t('ACL that contains IPs of users allowing to force cache misses'),
        '#type' => 'select',
        '#options' => $this->acls,
        "#default_value" => $config->get('acl'),
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
    } else {
      $form['error'] = [
        '#markup' => $this->t('Please add ACL to the configuration on Fastly to be able to change settings.')
      ];
    }
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
      $this->config('fastly.edge_modules.force_cache_miss_on_hard_reload_for_admins')
        ->set('acl', '')
        ->save();
    }else{
      $this->config('fastly.edge_modules.force_cache_miss_on_hard_reload_for_admins')
        ->set('acl', $form_state->getValue('acl'))
        ->save();
      $response = $this->vclHandler->uploadEdgeModule($this->getFormId(),[
        'acl' => $form_state->getValue('acl'),
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
    return ['fastly.edge_modules.force_cache_miss_on_hard_reload_for_admins'];
  }
}
