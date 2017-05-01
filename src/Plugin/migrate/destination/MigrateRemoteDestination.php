<?php

namespace Drupal\migrate_remote_destination\plugin\migrate\destination;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\MigrateException;
use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * This destination plugin POSTs to a remote API expecting JSON as return.
 *
 * The configuration object migrate_remote_destination.setttings is keyed by
 * migration id and is merged into the migration definition. This way URL
 * endpoints and authentication keys can be in config or a config override.
 *
 * This destination uses the following keys in its configuration:
 *
 * - url_property The name of the property containing the remote API endpoint.
 * - format Can be json or form_params (default) or even multipart.
 * - ids Will be returned as is from the getIds() method. When set, the API
 * call return is json_decode()'d and the returned ids saved into id map. When
 * unset, the return is disregarded.
 *
 * @see \GuzzleHttp\RequestOptions
 * @see \Drupal\migrate\Plugin\MigrateDestinationInterface::getIds()
 *
 * @MigrateDestination(
 *   id = "migrate_remote"
 * )
 */
class MigrateRemoteDestination extends DestinationBase implements ContainerFactoryPluginInterface {

  protected $client;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, Client $client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
    $this->client = $client;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('http_client')
    );
  }

  /**
   * Import the row.
   *
   * Derived classes must implement import(), to construct one new object
   * (pre-populated) using ID mappings in the Migration.
   *
   * @param \Drupal\migrate\Row $row
   *   The row object.
   * @param array $old_destination_id_values
   *   (optional) The old destination IDs. Defaults to an empty array.
   *
   * @return mixed
   *   The entity ID or an indication of success.
   */
  public function import(Row $row, array $old_destination_id_values = array()) {
    $url = $row->getDestinationProperty($this->configuration['url_property']);
    $values = $row->getDestination();
    NestedArray::unsetValue($values, explode(Row::PROPERTY_SEPARATOR, $this->configuration['url_property']));
    $format = isset($this->configuration['format']) ? $this->configuration['format'] : RequestOptions::FORM_PARAMS;
    $response = $this->client->post($url, [$format => $values]);
    if (substr($response->getStatusCode(), 0, 1) === '2') {
      if (isset($this->configuration['ids'])) {
        $response = json_decode($response->getBody(), TRUE);
        if (is_scalar($response) && $response !== FALSE && count($this->configuration['ids']) == 1) {
          return array_combine(array_keys($this->configuration['ids']), [$response]);
        }
        if (is_array($response) && ($return = array_intersect_key($response, $this->configuration['ids']))) {
          return $return;
        }
      }
      else {
        return TRUE;
      }
    }
    throw new MigrateException('POST unsuccessful');
  }

  public function checkRequirements() {
    parent::checkRequirements();
    if (!isset($this->configuration['url_property'])) {
      throw new RequirementsException("The destination configuration key url_property is required");
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    // Something must be returned here.
    return isset($this->configuration['ids']) ? $this->configuration['ids'] : ['id' => ['type' => 'string']];
  }

  /**
   *
   */
  public function fields(MigrationInterface $migration = NULL) {
    return isset($this->configuration['fields']) ? $this->configuration['fields'] : [];
  }

}
