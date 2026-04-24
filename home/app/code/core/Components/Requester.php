<?php

namespace App\Core\Components;

class Requester extends Base
{

    private $sourceName = null;
    private $targetName = null;
    private $sourceId = null;
    private $targetId = null;

    public function setDi(\Phalcon\Di\DiInterface $di):void
    {
        parent::setDi($di);
        $this->di->set('requester', $this);
    }

    public function set()
    {
        return $this;
    }

    /**
     * @param $application
     * @param $decodedToken
     * Headers 
     * Ced-Source-Id
     * Ced-Source-Name
     * Ced-Target-Id
     * Ced-Target-Name
     * @return bool
     */
    public function setHeaders($headers) {
        if ( $headers ) {
            foreach($headers as $key => $header ) {
                switch($key) {
                    case "Ced-Source-Id" : $this->sourceId = $header; break;
                    case "Ced-Source-Name" : $this->sourceName = $header; break;
                    case "Ced-Target-Id" : $this->targetId = $header; break;
                    case "Ced-Target-Name" : $this->targetName = $header; break;
                    default: break;
                }
            }
        }
    }

    public function getSourceId()
    {
        return $this->sourceId;
    }

    public function getTargetId()
    {
        return $this->targetId;
    }

    public function getSourceName()
    {
        return $this->sourceName;
    }

    public function getTargetName()
    {
        return $this->targetName;
    }

    public function setSource($source)
    {
        $this->sourceId = $source['source_id'];
        $this->sourceName = $source['source_name'];
    }

    public function setTarget($target)
    {
        $this->targetId = $target['target_id'];
        $this->targetName = $target['target_name'];
    }

}
