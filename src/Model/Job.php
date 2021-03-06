<?php

namespace Webgriffe\Esb\Model;

class Job
{
    /**
     * @var array
     */
    private $payloadData;

    /**
     * Job constructor.
     * @param array $payloadData
     */
    public function __construct(array $payloadData)
    {
        $this->payloadData = $payloadData;
    }

    /**
     * @return array
     */
    public function getPayloadData(): array
    {
        return $this->payloadData;
    }
}
