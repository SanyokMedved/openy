<?php

namespace Drupal\openy_activity_finder\Form;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings Form for daxko.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Guzzle Http Client.
   *
   * @var GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructor
   *
   * @param \GuzzleHttp\Client $http_client
   * @param CacheBackendInterface $cache
   * @param MessengerInterface $messenger
   * @param EntityTypeManagerInterface $entityTypeManager
   * @param ModuleHandlerInterface $moduleHandler
   */
  public function __construct(Client $http_client, CacheBackendInterface $cache, MessengerInterface $messenger, EntityTypeManagerInterface $entityTypeManager, ModuleHandlerInterface $moduleHandler) {
    $this->httpClient = $http_client;
    $this->cache = $cache;
    $this->messenger = $messenger;
    $this->entityTypeManager = $entityTypeManager;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('http_client'),
      $container->get('cache.render'),
      $container->get('messenger'),
      $container->get('entity_type.manager'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'openy_activity_finder_admin_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return 'openy_activity_finder.settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getSearchServerName() {
    return 'openy_activity_finder.solr_backend';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory()->getEditable($this->getEditableConfigNames());

    $form_state->setCached(FALSE);

    $backend_options = [];

    if ($this->moduleHandler->moduleExists('openy_daxko2')){
      $backend_options['openy_daxko2.openy_activity_finder_backend'] = $this->t('Daxko 2 (live API calls)');
    }

    if (!$this->moduleHandler->moduleExists('search_api')) {
      $this->messenger->addError($this->t('Warning. You can\'t use local search. Please install Search API module and configure search index for it.'));
    }
    else {
      $backend_options[$this->getSearchServerName()] = $this->t('Solr Backend (local db)');
      $query = $this->entityTypeManager->getStorage('search_api_index')->getQuery();
      $query->condition('status', 1);
      $searchApiIndexOptions = $query->execute();

      if (!empty($searchApiIndexOptions)) {
        $form['backend_active_index'] = [
          '#title' => $this->t('Select Active Index'),
          '#type' => 'select',
          '#options' => $searchApiIndexOptions,
          '#default_value' => $config->get('backend_active_index') ? $config->get('backend_active_index') : 'default',
          '#empty_value' => $this->t('Select active index'),
          '#states' => [
            'visible' => [
              '[name="backend"]' => [
                'value' => $this->getSearchServerName(),
              ],
            ],
          ],
          '#weight' => 1,
        ];
      }
      else {
        $form['backend_active_index'] = [
          '#title' => $this->t('Select Active Index'),
          '#type' => 'item',
          '#description' => '<h3>' . $this->t('Warning. Module Search Api is installed, but you don\'t have any active server and index. Please Create them if you want to use local search.') . '</h3>',
          '#weight' => 1,
          '#states' => [
            'visible' => [
              '[name="backend"]' => [
                'value' => $this->getSearchServerName(),
              ],
            ],
          ],
        ];
        //$this->messenger->addError($this->t('Warning. Module Search Api is installed, but you don\'t have any active server and index. Please Create them if you want to use local search.'));
      }
    }

    $form['backend'] = [
      '#type' => 'select',
      '#options' => $backend_options,
      '#required' => TRUE,
      '#title' => $this->t('Backend for Activity Finder'),
      '#default_value' => $config->get('backend'),
      '#description' => t(''),
      '#weight' => 0,
    ];

    $form['ages'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Ages'),
      '#default_value' => $config->get('ages'),
      '#description' => t('Ages mapping. One per line. "<number of months>,<age display label>". Example: "660,55+"'),
      '#weight' => 2,
    ];

    $form['exclude'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Exclude category -- so we do not display Group Exercises'),
      '#default_value' => $config->get('exclude'),
      '#description' => t('Provide ID of the Program Subcategory to exclude. You do not need to provide this if you use Daxko. Needed only for Solr backend.'),
      '#weight' => 3,
    ];

    $form['disable_search_box'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable Search Box'),
      '#default_value' => $config->get('disable_search_box'),
      '#description' => t('When checked hides search text box (both for Activity Finder and Results page).'),
      '#weight' => 3,
    ];

    $form['disable_spots_available'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Disable Spots Available'),
      '#default_value' => $config->get('disable_spots_available'),
      '#description' => t('When checked disables Spots Available feature on Results page.'),
      '#weight' => 3,
    ];

    $form['collapse'] = [
      '#type' => 'details',
      '#title' => $this->t('Group collapse settings.'),
      '#open' => TRUE,
      '#description' => $this->t('Please select items to show them as Expanded on program search. Default state is collapsed'),
      '#weight' => 3,
    ];

    $form['collapse']['schedule'] = [
      '#type' => 'details',
      '#title' => $this->t('Schedule preferences'),
      '#open' => TRUE,
    ];

    $options = [
      'disabled' => $this->t('Disabled'),
      'enabled_collapsed' => $this->t('Enabled - Collapsed'),
      'enabled_expanded' => $this->t('Enabled - Expanded'),
    ];

    $form['collapse']['schedule']['schedule_collapse_group'] = [
      '#title' => $this->t('Settings for whole group.'),
      '#type' => 'radios',
      '#options' => $options,
      '#default_value' => $config->get('schedule_collapse_group') ? $config->get('schedule_collapse_group') : 'disabled',
      '#description' => $this->t('Check this if you want default state for whole this group is "Collapsed"'),
    ];

    $form['collapse']['category'] = [
      '#type' => 'details',
      '#title' => $this->t('Activity preferences'),
      '#open' => TRUE,
    ];
    $form['collapse']['category']['category_collapse_group'] = [
      '#title' => $this->t('Settings for whole group.'),
      '#type' => 'radios',
      '#options' => $options,
      '#default_value' => $config->get('category_collapse_group') ? $config->get('category_collapse_group') : 'disabled',
      '#description' => $this->t('Check this if you want default state for whole this group is "Collapsed"'),
    ];

    $form['collapse']['locations'] = [
      '#type' => 'details',
      '#title' => $this->t('Location preferences'),
      '#open' => TRUE,
    ];

    $form['collapse']['locations']['locations_collapse_group'] = [
      '#title' => $this->t('Settings for whole group.'),
      '#type' => 'radios',
      '#options' => $options,
      '#default_value' => $config->get('locations_collapse_group') ? $config->get('locations_collapse_group') : 'disabled',
      '#description' => $this->t('Check this if you want default state for whole this group is "Collapsed"'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $config = $this->configFactory()->getEditable($this->getEditableConfigNames());

    $config->set('backend', $form_state->getValue('backend'))->save();

    if ($form_state->getValue('backend') == $this->getSearchServerName()) {
      $config->set('backend_active_index', $form_state->getValue('backend_active_index'))->save();
    }
    else {
      $config->set('backend_active_index', '')->save();
    }

    $config->set('ages', $form_state->getValue('ages'))->save();

    $config->set('exclude', $form_state->getValue('exclude'))->save();

    $config->set('disable_search_box', $form_state->getValue('disable_search_box'))->save();

    $config->set('disable_spots_available', $form_state->getValue('disable_spots_available'))->save();

    $config->set('schedule_collapse_group', $form_state->getValue('schedule_collapse_group'))->save();
    $config->set('category_collapse_group', $form_state->getValue('category_collapse_group'))->save();
    $config->set('locations_collapse_group', $form_state->getValue('locations_collapse_group'))->save();
    $this->cache->deleteAll();
    parent::submitForm($form, $form_state);
  }

  /**
   * Return Data structure the same as in Program search.
   * @return array
   */
  public function getActivityFinderDataStructure() {
    $request = $this->getRequest();
    $component = [];
    $url = Url::fromRoute('openy_activity_finder.get_results');
    $base_url = $request->getSchemeAndHttpHost();
    try {
      $response = $this->httpClient
        ->get($base_url . $url->toString());
      $data = $response->getBody();
    }
    catch (RequestException $e) {
      watchdog_exception('error', $e, $e->getMessage());
    }

    if ($data) {
      $data = json_decode($data);
      $component['facets'] = $data->facets;
      $component['groupedLocations'] = $data->groupedLocations;

      return $component;
    }
    return false;
  }
}
