<?php
namespace Drupal\fastly;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ExtensionList;
use Drupal\fastly\Utility\FastlyEdgeModulesHelper;
use Twig\Environment as TwigEnvironment;
class EdgeModules {
  const SETTINGS = 'fastly-settings-edgemodules';
  protected $acls = null;
  protected $dictionaries = null;
  protected $vclHandler;
  protected $config_factory;
  protected $twig;
  public function __construct(VclHandler $vclHandler,ConfigFactoryInterface $config_factory , TwigEnvironment $twig , ExtensionList $extensionList){
    $this->vclHandler = $vclHandler;
    $this->configFactory = $config_factory;
    $this->twig = $twig;
    $this->templatesDirectory = $extensionList->getPath('fastly') . '/fastly_edge_modules/templates';
  }
  public function renderForm($moduleId){
    $module = $this->getModuleWithData($moduleId);
    $variables = [
      'id' => $module->id,
      'vcl' => rawurlencode(json_encode($module->vcl)),
      'module_properties' => [],
      'vcl_types' => [],
    ];
    if($module->properties){
      foreach($module->properties as $property){
        if($property->type === 'group'){
          $variables['module_properties'][] = $this->renderGroup($property, (isset($module->data[$property->name])) ? $module->data[$property->name] : null, $module->id);
        }
        else{
          $variables['module_properties'][] = $this->renderProperty($module->id, $property, (isset($module->data[$property->name])) ? $module->data[$property->name] : null);
        }
      }
    }
    for ($i = 0; $i < count($module->vcl); $i++){
      $variables['vcl_types'][] = $module->vcl[$i]->type;
    }
    $formTemplate = $this->templatesDirectory . '/form.html.twig';
    return $this->twig->render($formTemplate, $variables);
  }
  protected function renderGroup($group, $values, $suffix){
    $values = $values ? $values : [];
    $name = "{$suffix}[{$group->name}]";
    $suffix = "{$suffix}-{$group->name}";
    $variables = [
      'label' => t($group->label),
      'name' => $name,
      'suffix' => $suffix,
      'rendered_group_properties' => [],
      'rendered_template' => $this->renderGroupProperties($group->properties, [], "{$name}[x]", 'container')
    ];
    for ($i = 0; $i < count($values); $i++){
      $variables['rendered_group_properties'][] = $this->renderGroupProperties($group->properties, $values[$i], "{$name}[$i]", "{$suffix}-{$i}");
    }
    $groupTemplate = $this->templatesDirectory . '/group.html.twig';
    return $this->twig->render($groupTemplate, $variables);
  }
  protected function renderGroupProperties($properties, $values, $name, $id){
    $variables = [
      'id' => $id,
      'properties' => [],
    ];
    foreach($properties as $property){
      $variables['properties'][] = $this->renderProperty($name, $property, isset($values[$property->name]) ? $values[$property->name]:"" );
    }
    $groupPropertiesTemplate = $this->templatesDirectory . '/group_properties.html.twig';
    return $this->twig->render($groupPropertiesTemplate, $variables);
  }
  protected function renderProperty($name, $property, $value){
    $variables = [
      'name' => $property->name,
      'label' => t($property->label),
      'description' => isset($property->description) ? t($property->description) : '',
      'rendered_field' => $this->renderFieldTwig($name, $property, $value),
    ];
    $propertyTemplate = $this->templatesDirectory . '/property.html.twig';
    return $this->twig->render($propertyTemplate, $variables);
  }
  protected function renderFieldTwig($name, $property, $value = null){
    $id = $property->name;
    $name = "{$name}[{$property->name}]";
    if(!$value && isset($property->default)){
      $value = $property->default;
    }
    $required = $property->required ? 'required' : '';
    $fieldTemplate = $this->templatesDirectory . '/field.html.twig';
    $variables = [
      'id' => $id,
      'name' => $name,
      'required' => $required,
      'value' => $value,
      'type' => $property->type,
    ];
    switch ($property->type) {
      case 'acl':
        $options = [];
        foreach ($this->getAcls() as $acl) {
          $options[$acl['name']] = $acl['name'];
        };
        return $this->twig->render($fieldTemplate, $variables + [
          'element' => 'select',
          'options' => $options,
        ]);
      case 'dict':
        $options = [];
        foreach ($this->getDictionaries() as $dictionary) {
          $options[$dictionary['name']] = $dictionary['name'];
        };
        return $this->twig->render($fieldTemplate, $variables + [
          'element' => 'select',
          'options' => $options,
        ]);
      case 'select':
        $options = [];
        foreach ((array) $property->options as $k => $label) {
          $options[$k] = $label;
        };
        return $this->twig->render($fieldTemplate, $variables + [
          'element' => 'select',
          'options' => $options,
        ]);
      case 'boolean':
        $options = [
          '' => "No",
          '1' => "Yes",
        ];
        $variables['required'] = '';
        return $this->twig->render($fieldTemplate, $variables + [
          'element' => 'select',
          'options' => $options,
        ]);
      case 'integer':
      case 'float':
        return $this->twig->render($fieldTemplate, $variables + [
          'element' => 'number',
        ]);
      case 'string':
      case 'path':
      default:
        return $this->twig->render($fieldTemplate, $variables + [
          'element' => 'text',
        ]);
    }
  }
  protected function getModuleWithData($moduleId){
    $module = FastlyEdgeModulesHelper::getModulesJson(FALSE)[$moduleId];
    $existingValues = $this->configFactory->get('fastly.edge_modules.'.$module->id)->get('values');
    $module->data = is_null($existingValues) ? [] : json_decode($existingValues, TRUE);
    return $module;
  }
  protected function getAcls(){
    if (is_null($this->acls)) {
      $this->acls = $this->vclHandler->getAllAcls();
    }
    return $this->acls;
  }
  protected function getDictionaries(){
    if (is_null($this->dictionaries)) {
      $this->dictionaries = $this->vclHandler->getAllDictionaries();
    }
    return $this->dictionaries;
  }
}
