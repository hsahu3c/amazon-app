<?php

namespace App\Frontend\Components;

use Exception;
use App\Core\Components\Base as Base;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Phalcon\Logger;

/**
 * Class FaqHelper
 * @package App\Frontend\Components
 */
class FaqHelper extends Base
{
    public const FAQ_STORAGE_FOLDER = BP . DS . 'public' . DS . 'amazon' . DS . 'faq' . DS;

    public const SECTION_FILE_NAME = 'sections.csv';

    public const SEQUENCE_FILE_NAME = 'sequence.csv';

    public const SEARCH_FILE_NAME = 'search.csv';

    public const ERROR_SEQUENCE_FILE_NAME = 'error_sequence.csv';

    public const FILE_EXTENSION = '.csv';

    public const CREATE_ACTION = 'create';

    public const UPDATE_ACTION = 'update';

    public const GET_ALL_SECTION_TYPE = 'all';

    public const GET_SINGLE_SECTION_TYPE = 'single';

    public const GET_SINGLE_FAQ_TYPE = 'single_faq';

    public const GET_OPTIONS_SECTION_TYPE = 'options';

    public const AMAZON_ERROR_CODE_PREFIX = 'ced-amazon-';

    public const DEFAULT_ACTIVE_PAGE = 1;

    public const DEFAULT_GRID_SIZE = 10;

    public const DEFAULT_FAQ_SIZE_FOR_SECTION = 5;

    public const DEFAULT_SEARCH_RESULT_SIZE = 10;

    public const CSV_HEADER_ROW = 1;

    public const CSV_DATA_START_FROM = 2;

    public const SLEEP_COUNT = 100;

    public const SLEEP_TIME_IN_MICROSECOND = 10000;

    private array $sectionsArrayForErrorCode = [
        'errors'
    ];

    /**
     * @param string $type
     * @param array $data
     * @return array
     */
    public function saveSection($type = self::CREATE_ACTION, $data = [])
    {
        try {
            $requiredFields = ['code', 'name'];
            $nonExistFields = $this->checkRequiredField($requiredFields, $data);
            if (count($nonExistFields)) {
                return [
                    'success' => false,
                    'message' => '\'' . implode(', \'', $nonExistFields) . '\' must be set and cannot be empty.'
                ];
            }

            $csvData = $this->getSectionsData();
            if (!isset($data['is_deletable'])) {
                $data['is_deletable'] = 'no';
            } elseif (strtolower(trim((string) $data['is_deletable'])) != 'yes') {
                $data['is_deletable'] = 'no';
            }

            $data = array_map('trim', $data);
            if ($type == self::CREATE_ACTION) {
                if (isset($csvData[$data['code']])) {
                    return [
                        'success' => false,
                        'message' => 'Section with \'' . $data['code'] . '\' code already exist.'
                    ];
                }

                $sectionCodeRegEx = '/^[a-zA-Z]+[a-zA-Z0-9_]+$/';
                if (!preg_match($sectionCodeRegEx, $data['code'])) {
                    return [
                        'success' => false,
                        'message' => 'Please use only letters (a-z or A-Z), numbers (0-9) or underscore (_) in \'code\' parameter, and the first character should be a letter.'
                    ];
                }
            } else {
                if (!isset($csvData[$data['code']])) {
                    return [
                        'success' => false,
                        'message' => '\'' . $data['code'] . '\' section doesn\'t exist.'
                    ];
                }
            }

            $csvData[$data['code']] = [
                'code' => $data['code'],
                'name' => $data['name'],
                'is_deletable' => strtolower($data['is_deletable']),
            ];
            $this->updateSection($csvData);
            return [
                'success' => true,
                'message' => 'Saved successfully.',
                'response' => $csvData[$data['code']]
            ];
        } catch (Exception $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage()
            ];
        }
    }

    /**
     * @param $getType
     * @param $code
     * @return array
     */
    public function getAllSections($getType = self::GET_ALL_SECTION_TYPE, $code = '')
    {
        return $this->getSectionsData($getType);
    }

    /**
     * @param array $params
     * @return array
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function getSectionsForGrid($params = [])
    {
        $pageSize = self::DEFAULT_GRID_SIZE;
        $activePage = self::DEFAULT_ACTIVE_PAGE;
        if (isset($params['pageSize'])) {
            $pageSize = (int)$params['pageSize'];
        }

        if (isset($params['activePage'])) {
            $activePage = (int)$params['activePage'];
        }

        if ($activePage <= 0) {
            $activePage = self::DEFAULT_ACTIVE_PAGE;
        }

        $responseData = [];
        $responseData['rows'] = [];
        $responseData['count'] = 0;
        $responseData['totalPages'] = 0;
        $filePath = self::FAQ_STORAGE_FOLDER . self::SECTION_FILE_NAME;
        if (file_exists($filePath)) {
            $objReader = IOFactory::createReader('Csv');
            $objPHPExcel = $objReader->load($filePath);
            $activeSheet = $objPHPExcel->getActiveSheet();
            $highestRow = $activeSheet->getHighestDataRow();
            $totalCount = $highestRow - 1;
            $responseData['count'] = $totalCount;
            $responseData['totalPages'] = ceil($totalCount / $pageSize);
            $fromAndTo = $this->getFromAndToForPage($totalCount, $activePage, $pageSize);
            if ($fromAndTo && is_array($fromAndTo)) {
                $headers = $this->getCsvHeaders($activeSheet);
                $from = $fromAndTo['from'] + self::CSV_DATA_START_FROM;
                $to = $fromAndTo['to'] + self::CSV_DATA_START_FROM;
                for ($rowIndex = $from; $rowIndex <= $to; $rowIndex++) {
                    $rowData = [];
                    foreach ($headers as $column => $columnIndex) {
                        $rowData[$column] = $activeSheet->getCell($columnIndex . $rowIndex)->getValue();
                    }

                    if (isset($rowData['code']) && isset($rowData['is_deletable'])) {
                        if (file_exists(self::FAQ_STORAGE_FOLDER . $rowData['code'] . self::FILE_EXTENSION)) {
                            $rowData['is_deletable'] = 'no';
                        }
                    }

                    $responseData['rows'][] = $rowData;
                }
            }
        }

        return [
            'success' => true,
            'message' => '',
            'data' => $responseData
        ];
    }

    /**
     * @param $code
     * @return array
     */
    public function deleteSection($code)
    {
        $csvData = $this->getSectionsData();
        if (isset($csvData[$code])) {
            if ($csvData[$code]['is_deletable'] == 'yes') {
                if (file_exists(self::FAQ_STORAGE_FOLDER . $code . self::FILE_EXTENSION)) {
                    return [
                        'success' => false,
                        'message' => 'You are not allowed to delete \'' . $code . '\' because some questions are associated with it.'
                    ];
                }

                unset($csvData[$code]);
                $this->updateSection($csvData);
                $this->deleteFile($code . self::FILE_EXTENSION);
                $sequenceData = $this->getSectionsData();
                if (isset($sequenceData[$code])) {
                    unset($sequenceData[$code]);
                }

                $this->updateSection($sequenceData);
                return [
                    'success' => true,
                    'message' => 'Successfully deleted.'
                ];
            }

            return [
                'success' => false,
                'message' => 'You cannot delete \'' . $code . '\'.'
            ];
        }

        return [
            'success' => false,
            'message' => '\'' . $code . '\' section doesn\'t exist.'
        ];
    }

    /**
     * @param $csvData
     */
    private function updateSection($csvData): void
    {
        $this->writeDataInCsv(
            self::SECTION_FILE_NAME,
            $this->getSectionHeaders(),
            $csvData
        );
    }

    public function getSingleSection($sectionCode)
    {
        $csvData = [];
        $path = self::FAQ_STORAGE_FOLDER . self::SECTION_FILE_NAME;
        if (file_exists($path)) {
            $objReader = IOFactory::createReader('Csv');
            $objPHPExcel = $objReader->load($path);
            $activeSheet = $objPHPExcel->getActiveSheet();
            $sectionData = $activeSheet->toArray(null, true, true, true);
            $header = array_flip($sectionData['1']);
            $codeIndex = $header['code'];
            unset($sectionData['1']);
            $allCodes = array_column($sectionData, $codeIndex);
            $codeSearchIndex = array_search($sectionCode, $allCodes);
            if ($codeSearchIndex !== false && isset($sectionData[$codeSearchIndex + self::CSV_DATA_START_FROM])) {
                foreach ($header as $code => $index) {
                    $csvData[$code] = $sectionData[$codeSearchIndex + self::CSV_DATA_START_FROM][$index];
                }

                if (isset($csvData['code']) && isset($csvData['is_deletable'])) {
                    if (file_exists(self::FAQ_STORAGE_FOLDER . $csvData['code'] . self::FILE_EXTENSION)) {
                        $csvData['is_deletable'] = 'no';
                    }
                }
            }
        }

        return $csvData;
    }

    /**
     * @param string $getType
     */
    private function getSectionsData($getType = self::GET_ALL_SECTION_TYPE): array
    {
        $csvData = [];
        $path = self::FAQ_STORAGE_FOLDER . self::SECTION_FILE_NAME;
        if (file_exists($path)) {
            $myFile = fopen($path, "r");
            $count = 1;
            $csvHeader = [];
            while (($row = fgetcsv($myFile)) !== FALSE) {
                if ($count == 1) {
                    $csvHeader = array_flip($row);
                } else {
                    if ($getType == self::GET_OPTIONS_SECTION_TYPE) {
                        $csvData[$row[$csvHeader['code']]] = $row[$csvHeader['name']];
                    } else {
                        if (isset($row[$csvHeader['code']]) && isset($row[$csvHeader['is_deletable']])) {
                            if (file_exists(self::FAQ_STORAGE_FOLDER . $row[$csvHeader['code']] . self::FILE_EXTENSION)) {
                                $row[$csvHeader['is_deletable']] = 'no';
                            }
                        }

                        $csvData[$row[$csvHeader['code']]] = [
                            'code' => $row[$csvHeader['code']],
                            'name' => $row[$csvHeader['name']],
                            'is_deletable' => $row[$csvHeader['is_deletable']],
                        ];
                    }
                }

                if (($count % self::SLEEP_COUNT) == 0) {
                    usleep(self::SLEEP_TIME_IN_MICROSECOND);
                }

                $count++;
            }
        }

        return $csvData;
    }

    /**
     * @param string $type
     * @param array $data
     * @return array
     */
    public function saveFaq($type = self::CREATE_ACTION, $data = [])
    {
        try {
            if ($type == self::CREATE_ACTION) {
                $requiredFields = [
                    'section_code',
                    'question',
                    'answer',
                    'meta_keyword'
                ];
            } else {
                $requiredFields = [
                    'old_section_code',
                    'section_code',
                    'question',
                    'answer',
                    'meta_keyword',
                    'id'
                ];
            }

            $nonExistFields = $this->checkRequiredField($requiredFields, $data);
            if (count($nonExistFields)) {
                return [
                    'success' => false,
                    'message' => '\'' . implode(', \'', $nonExistFields) . '\' must be set and cannot be empty.'
                ];
            }

            $sectionData = $this->getSectionsData();
            if (!isset($sectionData[$data['section_code']])) {
                return [
                    'success' => false,
                    'message' => '\'' . $data['section_code'] . '\' no longer exist.'
                ];
            }

            $data = array_map('trim', $data);
            $haveMetaKeyword = $this->isMetaKeywordHaveQuestion($data);
            if (!$haveMetaKeyword) {
                return [
                    'success' => false,
                    'message' => '\'meta_keyword\' must contain \'question\'.'
                ];
            }

            $errorCode = '';
            if (in_array($data['section_code'], $this->sectionsArrayForErrorCode)) {
                if (!isset($data['error_code'])) {
                    return [
                        'success' => false,
                        'message' => '\'error_code\' must be set and cannot be empty.'
                    ];
                }

                if (empty($data['error_code'])) {
                    return [
                        'success' => false,
                        'message' => '\'error_code\' cannot be empty.'
                    ];
                }

                $errorCode = $data['error_code'];
            }

            if ($type == self::CREATE_ACTION) {

                $faqData = $this->getFaqBySectionCode($data['section_code'], 'question');
                if (isset($faqData[strtolower($data['question'])])) {
                    return [
                        'success' => false,
                        'message' => 'Same Question already exist.'
                    ];
                }

                $nextQuestionId = $this->getNextQuestionId($data['section_code']);
                if (empty($errorCode)) {
                    $errorCode = $this->getAmazonCustomErrorCode();
                }

                $responseData = [
                    'id' => $nextQuestionId,
                    'question' => $data['question'],
                    'answer' => $data['answer'],
                    'meta_keyword' => $data['meta_keyword'],
                    'images' => $data['images'],
                    'videos' => $data['videos'],
                    'error_code' => $errorCode
                ];
                $faqData[strtolower($data['question'])] = $responseData;
            } else {

                if ($data['old_section_code'] == $data['section_code']) {
                    $faqData = $this->getFaqBySectionCode($data['section_code'], 'id');
                } else {
                    $this->forceDeleteFaq($data['old_section_code'], $data['id']);
                    $faqData = $this->getFaqBySectionCode($data['section_code'], 'id');
                    $faqDataForQuestion = $this->getFaqBySectionCode($data['section_code'], 'question');
                    if (isset($faqDataForQuestion[strtolower($data['question'])])) {
                        $data['id'] = (int)$faqDataForQuestion[strtolower($data['question'])]['id'];
                    } else {
                        $data['id'] = (int)$this->getNextQuestionId($data['section_code']);
                    }
                }

                if (empty($errorCode)) {
                    $errorCode = $this->getAmazonCustomErrorCode();
                }

                $responseData = [
                    'id' => $data['id'],
                    'question' => $data['question'],
                    'answer' => $data['answer'],
                    'meta_keyword' => $data['meta_keyword'],
                    'images' => $data['images'],
                    'videos' => $data['videos'],
                    'error_code' => $errorCode
                ];
                $faqData[$data['id']] = $responseData;
            }

            $this->updateFaq($data['section_code'], $faqData);
            $this->buildSearchIndex();
            return [
                'success' => true,
                'message' => 'Saved successfully.',
                'response' => $responseData
            ];
        } catch (Exception $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage()
            ];
        }
    }

    /**
     * @param $data
     */
    private function isMetaKeywordHaveQuestion($data): bool
    {
        $metaKeyword = strtolower(trim((string) $data['meta_keyword']));
        $question = strtolower(trim((string) $data['question']));
        if (str_contains($metaKeyword, $question)) {
            return true;
        }

        return false;
    }

    /**
     * @param array $data
     * @return array
     */
    public function deleteFaq($data = [])
    {
        try {
            return [
                'success' => false,
                'message' => 'We are not using this feature anymore.'
            ];
            $requiredFields = ['section_code', 'id'];
            $nonExistFields = $this->checkRequiredField($requiredFields, $data);
            if (count($nonExistFields)) {
                return [
                    'success' => false,
                    'message' => '\'' . implode(', \'', $nonExistFields) . '\' must be set and cannot be empty.'
                ];
            }

            $this->forceDeleteFaq($data['section_code'], $data['id']);
            return [
                'success' => true,
                'message' => 'Successfully deleted.'
            ];
        } catch (Exception) {
            return [
                'success' => false,
                'message' => 'This question no longer exist to delete.'
            ];
        }
    }

    /**
     * @param $sectionCode
     * @param $id
     */
    private function forceDeleteFaq($sectionCode, $id): bool
    {
        $faqData = $this->getFaqBySectionCode($sectionCode, 'id');
        if (!isset($faqData[$id])) {
            return false;
        }

        unset($faqData[$id]);
        $this->updateFaq($sectionCode, $faqData);
        return true;
    }

    /**
     * @param array $data
     * @return array
     */
    public function getSingleFaq($data = [])
    {
        try {
            $requiredFields = ['section_code', 'id'];
            $nonExistFields = $this->checkRequiredField($requiredFields, $data);
            if (count($nonExistFields)) {
                return [
                    'success' => false,
                    'message' => '\'' . implode(', \'', $nonExistFields) . '\' must be set and cannot be empty.'
                ];
            }

            $faqData = $this->getSingleFaqBySectionCode($data['section_code'], 'id', $data['id']);

            $response = [
                'success' => false,
                'message' => 'No Faq Available with \'' . $data['id'] . '\' question id.',
                'response' => []
            ];
            if (count($faqData)) {
                $response = [
                    'success' => true,
                    'message' => '',
                    'response' => $faqData
                ];
            }

            return $response;
        } catch (Exception) {
            return [
                'success' => false,
                'message' => 'This question no longer exist to delete.'
            ];
        }
    }

    /**
     * @param array $data
     * @return array
     */
    public function getSingleFaqByQuestion($data = [])
    {
        try {
            $requiredFields = ['section_code', 'question'];
            $nonExistFields = $this->checkRequiredField($requiredFields, $data);
            if (count($nonExistFields)) {
                return [
                    'success' => false,
                    'message' => '\'' . implode(', \'', $nonExistFields) . '\' must be set and cannot be empty.'
                ];
            }

            $faqData = $this->getFaqBySectionCode(
                $data['section_code'],
                'question',
                self::GET_SINGLE_FAQ_TYPE,
                $data['question']
            );
            $response = [
                'success' => false,
                'message' => 'No Faq Available with \'' . $data['question'] . '\' question.',
                'response' => []
            ];
            if (count($data)) {
                $response = [
                    'success' => true,
                    'message' => '',
                    'response' => $faqData
                ];
            }

            return $response;
        } catch (Exception) {
            return [
                'success' => false,
                'message' => 'This question no longer exist to delete.'
            ];
        }
    }

    /**
     * @return array
     */
    public function getAllFaq()
    {
        $response = [];
        $sectionData = $this->getSectionsData();
        foreach ($sectionData as $sectionCode => $sectionData) {
            $faqData = $this->getFaqBySectionCode($sectionCode);
            $count = 1;
            foreach ($faqData as $faqDatum) {
                $response[] = [
                    'id' => $faqDatum['id'],
                    'section_code' => $sectionCode,
                    'section_name' => $sectionData['name'],
                    'question' => $faqDatum['question'],
                    'answer' => $faqDatum['answer'],
                    'meta_keyword' => $faqDatum['meta_keyword'],
                    'error_code' => $faqDatum['error_code']
                ];
                if (($count % self::SLEEP_COUNT)) {
                    usleep(self::SLEEP_TIME_IN_MICROSECOND);
                }
            }
        }

        return [
            'success' => true,
            'message' => '',
            'response' => $response
        ];
    }


    /**
     * @param array $params
     * @return array
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function getFaqForGrid($params = [])
    {
        $pageSize = self::DEFAULT_GRID_SIZE;
        $activePage = self::DEFAULT_ACTIVE_PAGE;
        if (isset($params['pageSize'])) {
            $pageSize = (int)$params['pageSize'];
        }

        if (isset($params['activePage'])) {
            $activePage = (int)$params['activePage'];
        }

        if ($activePage <= 0) {
            $activePage = self::DEFAULT_ACTIVE_PAGE;
        }

        $responseData = [];
        $responseData['rows'] = [];
        $responseData['count'] = 0;
        $responseData['totalPages'] = 0;
        $filePath = self::FAQ_STORAGE_FOLDER . self::SEARCH_FILE_NAME;
        if (file_exists($filePath)) {
            $objReader = IOFactory::createReader('Csv');
            $objPHPExcel = $objReader->load($filePath);
            $activeSheet = $objPHPExcel->getActiveSheet();
            $highestRow = $activeSheet->getHighestDataRow();
            $totalCount = $highestRow - 1;
            $responseData['count'] = $totalCount;
            $responseData['totalPages'] = ceil($totalCount / $pageSize);
            $fromAndTo = $this->getFromAndToForPage($totalCount, $activePage, $pageSize);
            if ($fromAndTo && is_array($fromAndTo)) {
                $headers = $this->getCsvHeaders($activeSheet);
                $from = $fromAndTo['from'] + self::CSV_DATA_START_FROM;
                $to = $fromAndTo['to'] + self::CSV_DATA_START_FROM;
                for ($rowIndex = $from; $rowIndex <= $to; $rowIndex++) {
                    $rowData = [];
                    foreach ($headers as $column => $columnIndex) {
                        $rowData[$column] = $activeSheet->getCell($columnIndex . $rowIndex)->getValue();
                    }

                    $responseData['rows'][] = $rowData;
                }
            }
        }

        return [
            'success' => true,
            'message' => '',
            'data' => $responseData
        ];
    }

    /**
     * @param array $params
     * @return array
     */
    public function getSearchSuggestions($params = [])
    {
        try {
            if (!isset($params['query'])) {
                return [
                    'success' => false,
                    'message' => '\'query\' parameter not set.'
                ];
            }

            $params['query'] = trim((string) $params['query']);
            if (strlen($params['query']) == 0) {
                return [
                    'success' => false,
                    'message' => '\'query\' parameter cannot be empty.'
                ];
            }

            $searchResult = [];
            $count = 0;
            $filePath = self::FAQ_STORAGE_FOLDER . self::SEARCH_FILE_NAME;
            if (file_exists($filePath)) {
                $objReader = IOFactory::createReader('Csv');
                $objPHPExcel = $objReader->load($filePath);
                $activeSheet = $objPHPExcel->getActiveSheet();
                $searchData = $activeSheet->toArray(null, true, true, true);
                $header = array_flip($searchData['1']);
                $metaKeywordIndex = $header['meta_keyword'];
                $questionIndex = $header['question'];
                unset($searchData['1']);
                $searchWords = array_filter(explode(' ', $params['query']));
                $regularExpression = $this->wordRegex($searchWords);
                $matches = array_filter($searchData, fn(array $item): int|false => preg_match($regularExpression, (string) $item[$metaKeywordIndex]));
                $count = count($matches);
                $searchResult = array_column($matches, $questionIndex);
            }

            $successFlag = false;
            if ($searchResult !== []) {
                $successFlag = true;
            }

            return [
                'success' => $successFlag,
                'message' => '',
                'response' => $searchResult,
                'count' => $count
            ];
        } catch (Exception $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param array $params
     * @return array
     */
    public function getSearchResult($params = [])
    {
        try {
            if (!isset($params['query'])) {
                return [
                    'success' => false,
                    'message' => '\'query\' parameter not set.'
                ];
            }

            $params['query'] = trim((string) $params['query']);
            if (strlen($params['query']) == 0) {
                return [
                    'success' => false,
                    'message' => '\'query\' parameter cannot be empty.'
                ];
            }

            $searchResult = [];
            $count = 0;
            $showMore = false;
            $filePath = self::FAQ_STORAGE_FOLDER . self::SEARCH_FILE_NAME;
            if (file_exists($filePath)) {
                $pageSize = self::DEFAULT_SEARCH_RESULT_SIZE;
                $activePage = self::DEFAULT_ACTIVE_PAGE;
                if (isset($params['pageSize'])) {
                    $pageSize = (int)$params['pageSize'];
                }

                if (isset($params['activePage'])) {
                    $activePage = (int)$params['activePage'];
                }

                if ($activePage <= 0) {
                    $activePage = self::DEFAULT_ACTIVE_PAGE;
                }

                $objReader = IOFactory::createReader('Csv');
                $objPHPExcel = $objReader->load($filePath);
                $activeSheet = $objPHPExcel->getActiveSheet();
                $searchData = $activeSheet->toArray(null, true, true, true);
                $header = array_flip($searchData['1']);
                $metaKeywordIndex = $header['meta_keyword'];
                $questionIndex = $header['question'];
                $answerIndex = $header['answer'];
                unset($searchData['1']);
                $searchWords = array_filter(explode(' ', $params['query']));
                $regularExpression = $this->wordRegex($searchWords);
                $matches = array_filter($searchData, fn(array $item): int|false => preg_match($regularExpression, (string) $item[$metaKeywordIndex]));
                $count = count($matches);
                $matches = $this->getSortedMatches($matches, $searchWords, $metaKeywordIndex);
                $matches = array_values($matches);
                $allQuestions = array_map('strtolower', array_map('trim', array_column($matches, $questionIndex)));
                $matchedQuestionKey = array_search(trim(strtolower($params['query'])), $allQuestions);
                if ($matchedQuestionKey !== false) {
                    if (isset($matches[$matchedQuestionKey])) {
                        $matchedQuestionArray = $matches[$matchedQuestionKey];
                        unset($matches[$matchedQuestionKey]);
                        array_unshift($matches, $matchedQuestionArray);
                    }
                }

                $batches = array_chunk($matches, $pageSize, true);
                $batchCount = count($batches);
                $batchIndex = $activePage - 1;
                if (isset($batches[$batchIndex])) {
                    foreach ($batches[$batchIndex] as $batch) {
                        $searchResult[] = [
                            'question' => $batch[$questionIndex],
                            'answer' => $batch[$answerIndex],
                        ];
                    }
                }

                if ($activePage < $batchCount) {
                    $showMore = true;
                }
            }

            $successFlag = false;
            if ($searchResult !== []) {
                $successFlag = true;
            }

            return [
                'success' => $successFlag,
                'message' => '',
                'response' => $searchResult,
                'count' => $count,
                'show_more' => $showMore,
            ];
        } catch (Exception $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param $matches
     * @param $searchWords
     * @param $matchIndex
     */
    private function getSortedMatches($matches, $searchWords, $matchIndex): array
    {
        $searchWords = array_map('strtolower', array_map('trim', $searchWords));
        $matchWordCount = 'match_word_count';
        $batchSize = 20;
        $batches = array_chunk($matches, $batchSize, true);
        $matches = [];
        foreach ($batches as $batch) {
            foreach ($batch as $data) {
                $item = $data;
                $item[$matchWordCount] = 0;
                if (isset($data[$matchIndex])) {
                    $matchedData = array_filter(explode(" ", (string) $data[$matchIndex]));
                    $matchedData = array_map('strtolower', array_map('trim', $matchedData));
                    $result = array_intersect($matchedData, $searchWords); //matched elements
                    $numberOfMatchedWords = count($result);
                    $item[$matchWordCount] = $numberOfMatchedWords;
                }

                $matches[] = $item;
            }
        }

        uasort($matches, fn(array $a, array $b): int|float => $b['match_word_count'] - $a['match_word_count']);
        return $matches;
    }

    /**
     * @param array $data
     * @return array
     */
    public function findAnswers($data = [])
    {
        try {
            if (!isset($data['data'])) {
                return [
                    'success' => false,
                    'message' => '\'data\' parameter must be set and should be an array.'
                ];
            }

            if (!is_array($data['data'])) {
                return [
                    'success' => false,
                    'message' => '\'data\' parameter must contain an array.'
                ];
            }

            foreach ($data['data'] as $key => $datum) {
                if (
                    !isset($datum['errors']) &&
                    !is_array($datum['errors']) &&
                    count($datum['errors']) == 0
                ) {
                    return [
                        'success' => false,
                        'message' => 'Please make sure \'errors\' parameter must be set and should be an array.'
                    ];
                }

                foreach ($datum['errors'] as $errorKey => $error) {
                    if (
                        !isset($error['error_code']) &&
                        !isset($error['question'])
                    ) {
                        return [
                            'success' => false,
                            'message' => '\'errors\' parameter should contain either \'error_code\' or \'question\' parameter.'
                        ];
                    }

                    $data['data'][$key]['errors'][$errorKey]['hasAnswer'] = false;
                }
            }

            $filePath = self::FAQ_STORAGE_FOLDER . self::SEARCH_FILE_NAME;
            if (file_exists($filePath)) {
                $objReader = IOFactory::createReader('Csv');
                $objPHPExcel = $objReader->load($filePath);
                $activeSheet = $objPHPExcel->getActiveSheet();
                $searchData = $activeSheet->toArray(null, true, true, true);
                $header = array_flip($searchData['1']);
                $questionIndex = $header['question'];
                $answerIndex = $header['answer'];
                $questionIdIndex = $header['id'];
                $sectionCodeIndex = $header['section_code'];
                $errorCodeIndex = $header['error_code'];
                unset($searchData['1']);
                $allQuestions = array_map('strtolower', array_map('trim', array_column($searchData, $questionIndex)));
                $allErrorCodes = array_map('strtolower', array_map('trim', array_column($searchData, $errorCodeIndex)));
                $allQuestionIds = array_map('strtolower', array_map('trim', array_column($searchData, $questionIdIndex)));
                $allSectionCodes = array_map('strtolower', array_map('trim', array_column($searchData, $sectionCodeIndex)));
                $allAnswers = array_column($searchData, $answerIndex);
                foreach ($data['data'] as $key => $datum) {
                    foreach ($datum['errors'] as $errorKey => $error) {
                        $searchForQuestion = false;
                        if (isset($error['question']) && $error['question']) {
                            $searchForQuestion = true;
                        }

                        if (isset($error['error_code']) && $error['error_code']) {
                            $answerKeys = array_keys($allErrorCodes, trim(strtolower((string) $error['error_code'])));
                            if ($answerKeys !== []) {
                                $data['data'][$key]['errors'][$errorKey]['hasAnswer'] = true;
                                foreach ($answerKeys as $answerKey) {
                                    $data['data'][$key]['errors'][$errorKey]['query_parameters'][] = [
                                        'question_id' => $allQuestionIds[$answerKey],
                                        'section_code' => $allSectionCodes[$answerKey]
                                    ];
                                    $data['data'][$key]['errors'][$errorKey]['answer'][] = $allAnswers[$answerKey];
                                }

                                $searchForQuestion = false;
                            }
                        }

                        if ($searchForQuestion) {
                            $question = trim(strtolower((string) $error['question']));
                            $answerKey = array_search($question, $allQuestions);
                            if ($answerKey !== false && isset($allAnswers[$answerKey])) {
                                $data['data'][$key]['errors'][$errorKey]['hasAnswer'] = true;
                                $data['data'][$key]['errors'][$errorKey]['query_parameters'][] = [
                                    'question_id' => $allQuestionIds[$answerKey],
                                    'section_code' => $allSectionCodes[$answerKey]
                                ];
                                $data['data'][$key]['errors'][$errorKey]['answer'][] = $allAnswers[$answerKey];
                            }
                        }
                    }
                }
            }

            return [
                "success" => true,
                "message" => "",
                "data" => $data['data']
            ];
        } catch (Exception $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage()
            ];
        }
    }

    /**
     * @param string $question
     * @return bool
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function getAnswerByQuestion($question = '')
    {
        try {
            $question = trim(strtolower($question));
            if ($question) {
                $filePath = self::FAQ_STORAGE_FOLDER . self::SEARCH_FILE_NAME;
                if (file_exists($filePath)) {
                    $objReader = IOFactory::createReader('Csv');
                    $objPHPExcel = $objReader->load($filePath);
                    $activeSheet = $objPHPExcel->getActiveSheet();
                    $searchData = $activeSheet->toArray(null, true, true, true);
                    $header = array_flip($searchData['1']);
                    $questionIndex = $header['question'];
                    $answerIndex = $header['answer'];
                    unset($searchData['1']);
                    $allQuestions = array_map('strtolower', array_map('trim', array_column($searchData, $questionIndex)));
                    $allAnswers = array_column($searchData, $answerIndex);
                    $answerKey = array_search($question, $allQuestions);
                    if ($answerKey !== false && isset($allAnswers[$answerKey])) {
                        return $allAnswers[$answerKey];
                    }
                }
            }
        } catch (Exception $exception) {
            $this->di->getLog()->logContent($exception->getTraceAsString(), Logger::CRITICAL, 'exception.log');
        }

        return false;
    }

    /**
     * Regular expression for partial words match with or condition
     */
    private function partialWordRegex($words): string
    {
        $regex = array_reduce($words, function (string $carry, $word): string {
            if (strrpos($carry, ')') !== false) {
                $carry .= '|';
            }

            return $carry . '(?=.*' .  preg_quote($word) . ')';
        }, '/');
        return $regex . '/i';
    }

    /**
     * Regular expression for words match with or condition
     */
    private function wordRegex($words): string
    {
        $regex = array_reduce($words, function (string $carry, $word): string {
            if (strrpos($carry, ')') !== false) {
                $carry .= '|';
            }

            return $carry . '(?=.*\b' .  preg_quote($word) . '\b)';
        }, '/');

        return $regex . '.*/i';
    }

    /**
     * @param array $params
     * @return array
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    public function getAllFaqForCustomer($params = [])
    {
        $response = [
            'success' => false,
            'message' => '',
            'response' => []
        ];
        try {
            $pageSize = self::DEFAULT_FAQ_SIZE_FOR_SECTION;
            $activePage = self::DEFAULT_ACTIVE_PAGE;
            $responseData = [];
            $sections = $this->getSectionsData();
            if (isset($params['section_code']) && $params['section_code']) {
                $sectionCode = trim((string) $params['section_code']);
                if (isset($sections[$sectionCode])) {
                    if (isset($params['pageSize'])) {
                        $pageSize = (int)$params['pageSize'];
                    }

                    if (isset($params['activePage'])) {
                        $activePage = (int)$params['activePage'];
                    }

                    if ($activePage <= 0) {
                        $activePage = self::DEFAULT_ACTIVE_PAGE;
                    }

                    $filePath = self::FAQ_STORAGE_FOLDER . $sectionCode . self::FILE_EXTENSION;
                    if (file_exists($filePath)) {
                        $responseData[$sectionCode] = $this->getSectionWiseFaqForCustomer(
                            $sections[$sectionCode]['name'],
                            $filePath,
                            $activePage,
                            $pageSize
                        );
                    }
                }
            } else {
                foreach ($sections as $sectionCode => $section) {
                    $filePath = self::FAQ_STORAGE_FOLDER . $sectionCode . self::FILE_EXTENSION;
                    if (file_exists($filePath)) {
                        $responseData[$sectionCode] = $this->getSectionWiseFaqForCustomer(
                            $section['name'],
                            $filePath,
                            $activePage,
                            $pageSize
                        );
                    }
                }
            }

            if ($responseData !== []) {
                $response['success'] = true;
                $response['response'] = $responseData;
            }
        } catch (Exception $exception) {
            $response['success'] = false;
            $response['message'] = $exception->getMessage();
        }

        return $response;
    }

    /**
     * @param $sectionName
     * @param $filePath
     * @param $activePage
     * @param $pageSize
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    private function getSectionWiseFaqForCustomer($sectionName, $filePath, $activePage, $pageSize): array
    {
        $sectionResponse = [];
        $sectionResponse['data'] = [];
        $sectionResponse['count'] = 0;
        $sectionResponse['show_more'] = false;
        $sectionResponse['section_name'] = $sectionName;
        $objReader = IOFactory::createReader('Csv');
        $objPHPExcel = $objReader->load($filePath);
        $activeSheet = $objPHPExcel->getActiveSheet();
        $highestRow = $activeSheet->getHighestDataRow();
        $totalCount = $highestRow - 1;
        $sectionResponse['count'] = $totalCount;
        $fromAndTo = $this->getFromAndToForPage($totalCount, $activePage, $pageSize);
        if ($fromAndTo && is_array($fromAndTo)) {
            $headers = $this->getCsvHeaders($activeSheet);
            $from = $fromAndTo['from'] + self::CSV_DATA_START_FROM;
            $to = $fromAndTo['to'] + self::CSV_DATA_START_FROM;
            for ($rowIndex = $from; $rowIndex <= $to; $rowIndex++) {
                $rowData = [];
                if (isset($headers['question'])) {
                    $rowData['question'] = $activeSheet->getCell($headers['question'] . $rowIndex)->getValue();
                }

                if (isset($headers['answer'])) {
                    $rowData['answer'] = $activeSheet->getCell($headers['answer'] . $rowIndex)->getValue();
                }

                $sectionResponse['data'][] = $rowData;
            }

            if ($to < $highestRow) {
                $sectionResponse['show_more'] = true;
            }
        }

        return $sectionResponse;
    }

    /**
     * @return array
     */
    public function buildSearchIndex()
    {
        try {
            $csvData = [];
            $this->deleteFile(self::SEARCH_FILE_NAME);
            $sectionData = $this->getSectionsData();
            foreach ($sectionData as $sectionCode => $sectionData) {
                $faqData = $this->getFaqBySectionCode($sectionCode);
                $count = 1;
                foreach ($faqData as $faqDatum) {
                    $csvData[] = [
                        'id' => $faqDatum['id'],
                        'section_code' => $sectionCode,
                        'section_name' => $sectionData['name'],
                        'question' => $faqDatum['question'],
                        'answer' => $faqDatum['answer'],
                        'meta_keyword' => $faqDatum['meta_keyword'],
                        'error_code' => $faqDatum['error_code']
                    ];
                    if (($count % self::SLEEP_COUNT) == 0) {
                        usleep(self::SLEEP_TIME_IN_MICROSECOND);
                    }

                    $count++;
                }
            }

            $this->writeDataInCsv(self::SEARCH_FILE_NAME, $this->getSearchHeaders(), $csvData);
            return [
                'success' => true,
                'message' => 'Built successfully.'
            ];
        } catch (Exception $exception) {
            return [
                'success' => false,
                'message' => $exception->getMessage()
            ];
        }
    }

    /**
     * @param $sectionCode
     * @param $csvData
     */
    private function updateFaq($sectionCode, $csvData): void
    {

        $this->writeDataInCsv(
            $sectionCode . self::FILE_EXTENSION,
            $this->getFaqHeaders(),
            $csvData
        );
    }

    /**
     * @param $sectionCode
     * @param string $idOrQuestion
     * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
     */
    private function getSingleFaqBySectionCode($sectionCode, $keyIndex = 'id', $idOrQuestion = ''): array
    {
        $faq = [];
        $path = self::FAQ_STORAGE_FOLDER . $sectionCode . self::FILE_EXTENSION;
        if (file_exists($path)) {
            $objReader = IOFactory::createReader('Csv');
            $objPHPExcel = $objReader->load($path);
            $activeSheet = $objPHPExcel->getActiveSheet();
            $faqData = $activeSheet->toArray(null, true, true, true);
            $header = array_flip($faqData['1']);
            $requestedKeyIndex = $header[$keyIndex];
            unset($faqData['1']);
            $allRequestedData = array_map('strtolower', array_map('trim', array_column($faqData, $requestedKeyIndex)));
            $resultIndex = array_search($idOrQuestion, $allRequestedData);
            if ($resultIndex !== false && isset($faqData[$resultIndex + self::CSV_DATA_START_FROM])) {
                foreach ($header as $code => $index) {
                    $faq[$code] = $faqData[$resultIndex + self::CSV_DATA_START_FROM][$index];
                }

                $faq['section_code'] = $sectionCode;
            }
        }

        return $faq;
    }

    /**
     * @param $sectionCode
     * @param string $id
     */
    private function getFaqBySectionCode($sectionCode, $keyIndex = 'id', $getType = '', $id = ''): array
    {
        $faq = [];
        $path = self::FAQ_STORAGE_FOLDER . $sectionCode . self::FILE_EXTENSION;
        if (file_exists($path)) {
            $myFile = fopen($path, "r");
            $count = 1;
            $csvHeader = [];
            while (($row = fgetcsv($myFile)) !== FALSE) {
                if ($count == 1) {
                    $csvHeader = array_flip($row);
                } else {
                    if ($getType == self::GET_SINGLE_FAQ_TYPE) {
                        if (trim(strtolower((string) $row[$csvHeader[$keyIndex]])) == trim(strtolower($id))) {
                            $faq = [
                                'id' => $row[$csvHeader['id']],
                                'question' => $row[$csvHeader['question']],
                                'answer' => $row[$csvHeader['answer']],
                                'meta_keyword' => $row[$csvHeader['meta_keyword']],
                                'section_code' => $sectionCode,
                                'error_code' => $row[$csvHeader['error_code']] ?? '',
                            ];
                            return $faq;
                        }
                    } else {
                        $faq[strtolower((string) $row[$csvHeader[$keyIndex]])] = [
                            'id' => $row[$csvHeader['id']],
                            'question' => $row[$csvHeader['question']],
                            'answer' => $row[$csvHeader['answer']],
                            'meta_keyword' => $row[$csvHeader['meta_keyword']],
                            'error_code' => $row[$csvHeader['error_code']],
                        ];
                    }
                }

                if (($count % self::SLEEP_COUNT) == 0) {
                    usleep(self::SLEEP_TIME_IN_MICROSECOND);
                }

                $count++;
            }
        }

        return $faq;
    }

    /**
     * @return string
     */
    public function getAmazonCustomErrorCode()
    {
        $sequenceData = $this->getErrorSequenceData();
        $sectionCode = 'ced_amazon';
        if (isset($sequenceData[$sectionCode])) {
            $sequenceData[$sectionCode]['next_error_id'] += 1;
        } else {
            $sequenceData[$sectionCode] = [
                'error_type' => $sectionCode,
                'next_error_id' => 1
            ];
        }

        $this->updateErrorSequenceData($sequenceData);
        return self::AMAZON_ERROR_CODE_PREFIX . $sequenceData[$sectionCode]['next_error_id'];
    }

    /**
     * @param $csvData
     */
    private function updateErrorSequenceData($csvData): void
    {
        $this->writeDataInCsv(
            self::ERROR_SEQUENCE_FILE_NAME,
            $this->getErrorSequenceHeaders(),
            $csvData
        );
    }

    private function getErrorSequenceData(): array
    {
        $sequenceData = [];
        $path = self::FAQ_STORAGE_FOLDER . self::ERROR_SEQUENCE_FILE_NAME;
        if (file_exists($path)) {
            $myFile = fopen($path, "r");
            $count = 1;
            $csvHeader = [];
            while (($row = fgetcsv($myFile)) !== FALSE) {
                if ($count == 1) {
                    $csvHeader = array_flip($row);
                } else {
                    $sequenceData[$row[$csvHeader['error_type']]] = [
                        'error_type' => $row[$csvHeader['error_type']],
                        'next_error_id' => $row[$csvHeader['next_error_id']]
                    ];
                }

                $count++;
            }
        }

        return $sequenceData;
    }

    /**
     * @param $sectionCode
     * @return mixed
     */
    private function getNextQuestionId($sectionCode)
    {
        $sequenceData = $this->getSequenceData();
        if (isset($sequenceData[$sectionCode])) {
            $sequenceData[$sectionCode]['next_question_id'] += 1;
        } else {
            $sequenceData[$sectionCode] = [
                'section_code' => $sectionCode,
                'next_question_id' => 1
            ];
        }

        $this->updateSequenceData($sequenceData);
        return $sequenceData[$sectionCode]['next_question_id'];
    }

    /**
     * @param $csvData
     */
    private function updateSequenceData($csvData): void
    {
        $this->writeDataInCsv(
            self::SEQUENCE_FILE_NAME,
            $this->getSequenceHeaders(),
            $csvData
        );
    }

    private function getSequenceData(): array
    {
        $sequenceData = [];
        $path = self::FAQ_STORAGE_FOLDER . self::SEQUENCE_FILE_NAME;
        if (file_exists($path)) {
            $myFile = fopen($path, "r");
            $count = 1;
            $csvHeader = [];
            while (($row = fgetcsv($myFile)) !== FALSE) {
                if ($count == 1) {
                    $csvHeader = array_flip($row);
                } else {
                    $sequenceData[$row[$csvHeader['section_code']]] = [
                        'section_code' => $row[$csvHeader['section_code']],
                        'next_question_id' => $row[$csvHeader['next_question_id']]
                    ];
                }

                $count++;
            }
        }

        return $sequenceData;
    }

    /**
     * @param $activeSheet
     * @throws \PhpOffice\PhpSpreadsheet\Exception
     */
    private function getCsvHeaders($activeSheet): array
    {
        $headerColumns = [];
        $highestColumnIndex = Coordinate::columnIndexFromString($activeSheet->getHighestDataColumn());
        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $columnName = Coordinate::stringFromColumnIndex($col);
            $cellValue = $activeSheet->getCell($columnName . self::CSV_HEADER_ROW)->getValue();
            if (!empty($cellValue)) {
                $headerColumns[$cellValue] = $columnName;
            }
        }

        return $headerColumns;
    }

    /**
     * @param $totalCount
     * @param $activePage
     * @param $pageSize
     */
    private function getFromAndToForPage($totalCount, $activePage, $pageSize): array|false
    {
        $from = ($activePage - 1) * $pageSize;
        if ($from < $totalCount) {
            $to = min((($from + $pageSize) - 1), ($totalCount - 1));
            return [
                'from' => $from,
                'to' => $to
            ];
        }

        return false;
    }

    private function getSequenceHeaders(): array
    {
        return [
            'section_code',
            'next_question_id'
        ];
    }

    private function getErrorSequenceHeaders(): array
    {
        return [
            'error_type',
            'next_error_id'
        ];
    }

    private function getFaqHeaders(): array
    {
        return [
            'id',
            'question',
            'answer',
            'meta_keyword',
            'images',
            'videos',
            'error_code'
        ];
    }

    private function getSectionHeaders(): array
    {
        return [
            'code',
            'name',
            'is_deletable'
        ];
    }

    private function getSearchHeaders(): array
    {
        return [
            'id',
            'section_code',
            'section_name',
            'question',
            'answer',
            'meta_keyword',
            'error_code'
        ];
    }

    /**
     * @param $requiredFields
     * @param $data
     */
    private function checkRequiredField($requiredFields, $data): array
    {
        $nonExistField = [];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $nonExistField[] = $field;
            }
        }

        return $nonExistField;
    }

    /**
     * @param $fileName
     * @param $headers
     * @param $csvData
     */
    private function writeDataInCsv($fileName, $headers, $csvData): void
    {

        $path = self::FAQ_STORAGE_FOLDER . $fileName;
        if (!is_dir(self::FAQ_STORAGE_FOLDER)) {
            $res = mkdir(self::FAQ_STORAGE_FOLDER, 0777, true);
        }

        $myFile = fopen($path, "w+");

        fputcsv($myFile, $headers);
        $count = 1;
        foreach ($csvData as $rowData) {
            fputcsv($myFile, $rowData);
            if (($count % self::SLEEP_COUNT) == 0) {
                usleep(self::SLEEP_TIME_IN_MICROSECOND);
            }

            $count++;
        }

        fclose($myFile);
    }

    /**
     * @param $fileName
     */
    private function deleteFile($fileName): bool
    {
        if (file_exists(self::FAQ_STORAGE_FOLDER . $fileName)) {
            unlink(self::FAQ_STORAGE_FOLDER . $fileName);
            return true;
        }

        return false;
    }
}
