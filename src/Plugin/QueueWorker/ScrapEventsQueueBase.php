<?php

namespace Drupal\btj_scrapper\Plugin\QueueWorker;

use Drupal\btj_scrapper\Controller\ScrapController;
use Drupal\node\Entity\Node;
use BTJ\Scrapper\Container\EventContainer;
use BTJ\Scrapper\Container\EventContainerInterface;
use Drupal\node\NodeInterface;

/**
 * Provides base functionality for the Import Content From XML Queue Workers.
 */
class ScrapEventsQueueBase extends ScrapQueueWorkerBase {

  public const DATE_FORMAT = 'Y-m-d\TH:i:s';

  /**
   * {@inheritdoc}
   */
  public function processItem($item) {
    /** @var \Drupal\btj_scrapper\Scraping\ServiceRepositoryInterface $serviceRepository */
    $serviceRepository = \Drupal::service('btj_scrapper_service_repository');
    $scrapper = $serviceRepository->getService($item->type, $item->gid);

    $container = new EventContainer();
    $scrapper->eventScrap($item->link, $container);
    sleep(1);

    if (empty($item->entity_id) || NULL === ($node = Node::load($item->entity_id))) {
      $node = Node::create(['type' => 'ding_event']);
      $node->field_municipality->target_id = $item->gid;
    }

    $this->nodePrepare($container, $node);

    $node->setOwnerId($item->uid);
    $node->save();

    ScrapController::writeRelations(
      $item->link,
      $node->bundle(),
      $node->id(),
      $node->getRevisionAuthor()->id(),
      $item->gid,
      $item->type
    );
  }

  /**
   * {@inheritdoc}
   */
  function nodePrepare($container, NodeInterface &$node) {
    // This, normally, should not happen.
    if ('ding_event' !== $node->bundle()) {
      return;
    }

    $node->setTitle($container->getTitle());

    $node->set('field_ding_event_lead', $this->plainText($container->getLead()));

    $node->field_ding_event_body->value = $container->getBody();
    $node->field_ding_event_body->format = 'full_html';

    if (!empty($container->getListImage())) {
      $node->field_ding_event_list_image->target_id = $this->prepareImage($container->getListImage());
      $node->field_ding_event_list_image->alt = $container->getTitle();
      $node->field_ding_event_list_image->title = $container->getTitle();
    }

    $node->set('field_ding_event_price', $container->getPrice());
    $node->set('field_ding_event_date', $this->prepareEventDate($container));

    $category = $container->getCategory();
    if (!empty($category)) {
      $node->field_ding_event_category->target_id = $this->prepareCategory($category, 'event');
    }

    $tags = $container->getTags();
    $tags = array_filter($tags);
    if (!empty($tags)) {
      $node->set('field_ding_event_tags', $this->prepareTags($tags));
    }

    $terms = $container->getTarget();
    $terms = array_filter($terms);
    if (!empty($terms)) {
      $target = $this->prepareTarget($terms, 'event');

      if (!empty($target)) {
        $node->set('field_ding_event_target', $target);
      }
    }
  }

  /**
   * TODO:
   *
   * @param $string
   *
   * @return string
   */
  public function plainText($string): string {
    return trim(strip_tags(str_replace(['<br>', '<br />', '<br/>'], "\n", $string)));
  }

  /**
   * Prepare date field to be saved on node creation.
   */
  public function prepareEventDate(EventContainerInterface $container) {
    $mapping = [
      '' => date('m'),
      'januari' => '01',
      'februari' => '02',
      'mars' => '03',
      'april' => '04',
      'maj' => '05',
      'juni' => '06',
      'juli' => '07',
      'augusti' => '08',
      'september' => '09',
      'oktober' => '10',
      'november' => '11',
      'december' => '12',
    ];
    $year = date('Y');
    $month = $mapping[$container->getMonth()];
    $date = $container->getDate();

    $hours = [];
    preg_match('/(\d{1,2}(?:\.\d{2})?)-?((\d{1,2}(?:\.\d{2})?))?/', $container->getTime(), $hours);

    $dt = new \DateTime('now');
    $dt->setDate($year, $month, $date);
    $dt->setTime(0, 0);

    if (!empty($hours[1])) {
      @list($h, $m) = explode('.', $hours[1]);
      $dt->setTime($h, $m);
    }

    $start = $dt->format(self::DATE_FORMAT);

    if (!empty($hours[3])) {
      @list($h, $m) = explode('.', $hours[3]);
      $dt->setTime($h, $m);
    }

    $end = $dt->format(self::DATE_FORMAT);

    return ['value' => $start, 'end_value' => $end];
  }

}
