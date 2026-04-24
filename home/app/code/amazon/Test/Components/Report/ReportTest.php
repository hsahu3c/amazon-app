<?php

namespace App\Amazon\Test\Components\Report;

use App\Core\Components\UnitTestApp;
use App\Amazon\Components\Report\Report;


/**
 * Class Amazon/Components/Report/Report.php
 */
class ReportTest extends UnitTestApp
{
    /** send report-fetch request on amazon */
    public function testsaveFile(): void
    {
        $data = 'example_data_to_write';
        $testUserData = [
            'user_id' => '653a4cf2f2db47da030607f6',
            'target_shop_id' => '176'
        ];
        $path = BP . DS . 'var' . DS . 'file' . DS . 'mfa' . DS . $testUserData['user_id'] . DS . $testUserData['target_shop_id'] . DS . 'report.tsv';
        
        $resultPath = $this->di->getObjectManager()->get(Report::class)->saveFile($data, $path);
        $this->assertEquals($resultPath, $path, $resultPath['message'] ?? 'File could not be created');
        if (is_string($resultPath)) {
            $this->assertFileExists($resultPath, 'File does not exists');
            !unlink($resultPath);
            $this->assertFileNotExists($resultPath, 'File could not be deleted');
        }
    }
}
