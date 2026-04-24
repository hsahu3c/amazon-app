<?php

namespace App\Core\Components;

use DOMDocument;
use DOMElement;

class Phpunit extends Base
{
    const REPORT_FILE = BP . '/var/coverage.crap4j';
    const SAVE_FILE = BP . '/var/coverage.json';
    private Helper $helper;

    public function __construct(Helper $helper)
    {
        $this->helper = $helper;
    }
    /**
     * Generate phpunit.xml file.
     *
     * @return bool
     */
    public function generateConfig($coverageReport = false): bool
    {
        $dom = new \DOMDocument();
        $dom->encoding = 'utf-8';
        $dom->xmlVersion = '1.0';
        $dom->formatOutput = true;
        $phpUnit = $dom->createElement('phpunit');

        $this->addAttribute($phpUnit, "xmlns:xsi", "http://www.w3.org/2001/XMLSchema-instance")
            ->addAttribute($phpUnit, 'bootstrap', BP . '/app/phpunit.php')
            ->addAttribute($phpUnit, 'backupGlobals', "false")
            ->addAttribute($phpUnit, 'colors', "true")
            ->addAttribute($phpUnit, 'processIsolation', "false")
            ->addAttribute($phpUnit, 'stopOnFailure', "false")
            ->addAttribute($phpUnit, "xsi:noNamespaceSchemaLocation", "https://schema.phpunit.de/10.0/phpunit.xsd")
            ->addAttribute($phpUnit, 'backupStaticProperties', "false")
            ->addAttribute($phpUnit, 'cacheDirectory', "phpunit.cache");

        $xmlFileName = BP . '/app/phpunit.xml';

        $this->prepareTestSuite($dom, $phpUnit);
        $this->prepareFilter($dom, $phpUnit);
        $this->prepareCoverage($dom, $phpUnit, $coverageReport);
        $dom->appendChild($phpUnit);
        $dom->save($xmlFileName);

        return true;
    }
    /**
     * Prepare test suite for phpunit
     *
     * @param DOMDocument $dom
     * @param DOMElement $phpUnit
     * @return void
     */
    public function prepareTestSuite(DOMDocument $dom, DOMElement &$phpUnit): void
    {
        $modules = $this->helper->getAllModules();
        $testSuite = $dom->createElement('testsuite');
        $this->addAttribute($testSuite, 'name', 'Phalcon - TestSuite');

        foreach ($modules as $module => $active) {
            $directory = $dom->createElement('directory', BP . '/app/code/' . $module);
            $testSuite->appendChild($directory);
        }
        $phpUnit->appendChild($testSuite);
    }

    /**
     * Prepare filter
     *
     * @param DOMDocument $dom
     * @param DOMElement $phpUnit
     * @return void
     */
    public function prepareFilter(DOMDocument $dom, DOMElement &$phpUnit): void
    {
        $blackListItems = [
            "dirs" => ['Test', "etc", "Exceptions", "traits", "translation", "view", "Enum"],
            "files" => ["Register.php", "module.php", "Application.php", "ConsoleApplication.php", "UnitApplication.php"]
        ];
        $filter = $dom->createElement('source');
        $whitelist = $dom->createElement('include');
        $blacklist = $dom->createElement('exclude');
        $modules = $this->helper->getAllModules();
        foreach ($modules as $module => $active) {
            $directory = $dom->createElement('directory', BP . '/app/code/' . $module);
            $whitelist->appendChild($directory);
            foreach ($blackListItems["dirs"] as $dir) {
                $directory = $dom->createElement('directory', BP . '/app/code/' . $module . "/{$dir}");
                $blacklist->appendChild($directory);
            }
            foreach ($blackListItems["files"] as $dir) {
                $directory = $dom->createElement('file', BP . '/app/code/' . $module . "/{$dir}");
                $blacklist->appendChild($directory);
            }
        }
        $filter->appendChild($whitelist);
        $filter->appendChild($blacklist);
        $phpUnit->appendChild($filter);
    }

    /**
     * Add new attribute
     *
     * @param DOMElement $node
     * @param string $attribute
     * @param string $value
     * @return \App\Core\Components\Phpunit;
     */
    public function addAttribute(DOMElement &$node, string $attribute, string $value): self
    {
        $attribute = new \DOMAttr($attribute, $value);
        $node->setAttributeNode($attribute);
        return $this;
    }

    public function prepareCoverage(DOMDocument $dom, DOMElement &$phpUnit, $createReport = false): void
    {
        $filter = $dom->createElement('coverage');
        $filter->setAttribute('includeUncoveredFiles', 'true');
        if ($createReport) {
            $report = $dom->createElement('report');

            $crap4j = $dom->createElement('crap4j');
            $crap4j->setAttribute('outputFile', BP . DS . 'var/coverage.crap4j');
            $report->appendChild($crap4j);

            $html = $dom->createElement('html');
            $html->setAttribute('outputDirectory', BP . DS . 'var/coverage-html');
            $html->setAttribute('lowUpperBound', '50');
            $html->setAttribute('highLowerBound', '90');
            $report->appendChild($html);

            $filter->appendChild($report);
        }
        $phpUnit->appendChild($filter);
    }
    public function createCoverageReport($threshold = 101): bool
    {
        if (!file_exists(self::REPORT_FILE)) {
            return false;
        }
        $report = simplexml_load_string(file_get_contents(self::REPORT_FILE));
        $methods = $report->methods->method;
        $jsonData = [];
        foreach ($methods as $method) {
            if ($method->coverage < $threshold) {
                $jsonData["$method->className"]["$method->methodName"] = "$method->coverage%";
            }
        }
        return file_put_contents(self::SAVE_FILE, json_encode($jsonData, JSON_PRETTY_PRINT));
    }
}
