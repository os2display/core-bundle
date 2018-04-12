<?php

namespace Os2Display\CoreBundle\Traits;

use JMS\Serializer\Annotation as Serializer;

trait ApiData {
  /**
   * @var array
   * @Serializer\Groups({"api", "api-bulk", "screen"})
   */
  protected $apiData;

  /**
   * @param array $apiData
   *
   * @return ApiData
   */
  public function setApiData(array $apiData) {
    if (is_array($this->apiData)) {
      $this->apiData = array_merge($this->apiData, $apiData);
    } else {
      $this->apiData = $apiData;
    }

    return $this;
  }

  /**
   * @return array
   */
  public function getApiData() {
    return $this->apiData;
  }
}
