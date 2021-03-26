<?php

namespace Drupal\fastlypurger\EventSubscriber;

use Drupal\Component\Diff\Engine\DiffOpChange;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManager;
use Drupal\Core\Config\ImportStorageTransformer;
use Drupal\Core\Config\StorageInterface;
use Drupal\fastly\Api;
use Drupal\fastly\State;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Config import Validator class.
 */
class ConfigImportValidator implements EventSubscriberInterface {

  /**
   * The Fastly logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * Import Storage Transformer.
   *
   * @var \Drupal\Core\Config\ImportStorageTransformer
   */
  protected $importTransformer;

  /**
   * Sync Storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $syncStorage;

  /**
   * Fastly Api.
   *
   * @var \Drupal\fastly\Api
   */
  protected $api;

  /**
   * The config manager.
   *
   * @var \Drupal\Core\Config\ConfigManager
   */
  protected $configManager;

  /**
   * Fastly State.
   *
   * @var \Drupal\fastly\State
   */
  protected $fastlyState;

  /**
   * Constructs a new CacheTagsHeaderLimitDetector object.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   The Fastly logger channel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Config\ImportStorageTransformer $import_transformer
   *   Import Storage Transformer.
   * @param StorageInterface $sync_storage
   *   Sync Storage.
   * @param \Drupal\fastly\Api $api
   *   Fastly Api.
   * @param ConfigManager $config_manager
   *   Config manager.
   * @param \Drupal\fastly\State $fastly_state
   *   Fastly State.
   */
  public function __construct(LoggerInterface $logger,
                              ConfigFactoryInterface $config_factory,
                              ImportStorageTransformer $import_transformer,
                              StorageInterface $sync_storage,
                              Api $api,
                              ConfigManager $config_manager,
                              State $fastly_state) {
    $this->logger = $logger;
    $this->config = $config_factory;
    $this->importTransformer = $import_transformer;
    $this->syncStorage = $sync_storage;
    $this->api = $api;
    $this->configManager = $config_manager;
    $this->fastlyState = $fastly_state;
  }

  /**
   * Config import validator.
   *
   * @param \Drupal\Core\Config\ConfigImporterEvent $event
   */
  public function onConfigImportValidate(\Drupal\Core\Config\ConfigImporterEvent $event){
    $changeList = $event->getChangelist();
    $apiKey = FALSE;
    foreach($changeList as $key => $item){
      if (in_array('fastly.settings',$item)) {
        $config_importer = $event->getConfigImporter();
        $storageComparer = $config_importer->getStorageComparer();
        $target = $storageComparer->getTargetStorage();
        $source = $this->importTransformer->transform($this->syncStorage);
        $diff = $this->configManager->diff($target, $source, 'fastly.settings', NULL ,"");
        $edits = $diff->getEdits();
        foreach($edits as $edit){
          if($edit instanceof DiffOpChange){
            foreach($edit->closing as $value){
              if (str_contains($value, 'api_key')) {
                $val = explode(': ',$value);
                $apiKey = $val[1];
              }
            }
          }
        }
        if ($apiKey) {
          $this->api->setApiKey($apiKey);
        }
        if(!$this->api->validatePurgeCredentials()){
          $config_importer->logError(t('Fastly purge credentials invalid. Check your API key.'));
        }else{
          $this->fastlyState->setPurgeCredentialsState(TRUE);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[ConfigEvents::IMPORT_VALIDATE][] = ['onConfigImportValidate'];
    return $events;
  }

}
