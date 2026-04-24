<?php

namespace App\Core\Components;

/**
 * Escaper component
 */
class Escaper extends Base
{
    /**
     * sanitizes nested array with given algo
     * all phalcon escaper methods will work
     * additional - escapeNoSql
     * @param array $data
     * @param array $algo
     * @return array
     */
    public function sanitizeArray($data, $algo = ["escapeJs"])
    {
        $this->escapers = ["escapeHtml", "escapeJs", "escapeCss", "escapeHtmlAttr"];
        $this->escaper = $this->di->getEscaper();
        $this->escaperMap = $algo;
        if (gettype($data) === gettype([])) {
            array_walk_recursive($data, function (&$x) {
                $x = $this->mapEscapers($x);
            });
            return $data;
        }
        return $this->escaper->$algo($data);
    }
    /**
     * prevents noSql Injection
     *
     * @param mixed $data
     * @return mixed
     */
    public function escapeNoSql($data)
    {
        if (gettype($data) === gettype([])) {
            return null;
        }
        return preg_replace('/(\$)|(\[)|(])(\.)|(\^)|(>)/i', '', $data);
    }
    /**
     * maps user input to the given escaper methods
     * @internal helper function
     * @param mixed $data
     * @return mixed
     */
    private function mapEscapers($data)
    {
        $tmp = $data;
        foreach ($this->escaperMap as $em) {
            if (in_array($em, $this->escapers)) {
                $tmp = $this->escaper->$em($data);
            }
            if ($em == "escapeNoSql") {
                $tmp = $this->escapeNoSql($tmp);
            }
        }
        return $tmp;
    }
    /**
     * exposes factory default escaper
     *
     * @return void
     */
    public function getEscaper()
    {
        return $this->di->getEscaper();
    }
}
