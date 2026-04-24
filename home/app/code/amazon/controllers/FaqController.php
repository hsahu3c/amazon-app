<?php

namespace App\Amazon\Controllers;

use Exception;
use App\Core\Controllers\BaseController;
use App\Amazon\Components\FaqHelper;

/**
 * Class FaqController
 * @package App\Amazon\Controllers
 */
class FaqController extends BaseController
{
    /**
     * @return mixed
     */
    public function getAllSectionsAction() {
        $response = [
            'success' => true,
            'message' => '',
            'response' => $this->getFaqHelper()->getAllSections()
        ];
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function getSectionsForGridAction() {
        $response = $this->getFaqHelper()->getSectionsForGrid($this->getRawBody());
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function createSectionAction() {
        $response = $this->getFaqHelper()->saveSection(
            FaqHelper::CREATE_ACTION,
            $this->getRawBody()
        );
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function updateSectionAction() {
        $response = $this->getFaqHelper()->saveSection(
            FaqHelper::UPDATE_ACTION,
            $this->getRawBody()
        );
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function deleteSectionAction() {
        $rowBody = $this->getRawBody();
        if (!isset($rowBody['code'])) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'message' => '\'code\' parameter not set.'
                ]
            );
        }

        $rowBody['code'] = trim((string) $rowBody['code']);
        if (!$rowBody['code']) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'message' => '\'code\' cannot be empty.'
                ]
            );
        }

        $response = $this->getFaqHelper()->deleteSection($rowBody['code']);
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function getSectionAsOptionsAction() {
        $response = [
            'success' => true,
            'message' => '',
            'response' => $this->getFaqHelper()->getAllSections(FaqHelper::GET_OPTIONS_SECTION_TYPE)
        ];
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function getSectionByCodeAction() {
        $rowBody = $this->getRawBody();
        if (!isset($rowBody['code'])) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'message' => '\'code\' parameter not set.'
                ]
            );
        }

        $rowBody['code'] = trim((string) $rowBody['code']);
        if (!$rowBody['code']) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'message' => '\'code\' cannot be empty.'
                ]
            );
        }

        $data = $this->getFaqHelper()->getSingleSection($rowBody['code']);
        $response = [
            'success' => false,
            'message' => 'No Section Available with \'' . $rowBody['code'] . '\'',
            'response' => []
        ];
        if (count($data)) {
            $response = [
                'success' => true,
                'message' => '',
                'response' => $data
            ];
        }

        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function findAnswersAction() {
        $rowBody = $this->getRawBody();
        $response = $this->getFaqHelper()->findAnswers($rowBody);
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function syncExistingFaqsAction() {
        try{
            $rowBody = $this->getRawBody();
            if (!isset($rowBody['force_sync'])) {
                return $this->prepareResponse(
                    [
                        'success' => false,
                        'message' => '\'force_sync\' parameter not set.'
                    ]
                );
            }

            if (trim(strtolower((string) $rowBody['force_sync'])) != 'yes') {
                return $this->prepareResponse(
                    [
                        'success' => false,
                        'message' => '\'force_sync\' must be \'yes\' for syncing.'
                    ]
                );
            }

            $path = BP . DS . 'app' . DS . 'code' . DS . 'amazon' . DS . 'utility' . DS . 'faq.json';
            $faqJson = file_get_contents($path);
            $faqs = json_decode($faqJson, true);
            $faqHelper = $this->getFaqHelper();
            foreach ($faqs as $sectionName => $data) {
                $sectionData = [
                    'code' => str_replace(' ', '_', trim(strtolower((string) $sectionName))),
                    'name' => $sectionName,
                    'is_deletable' => 'no'
                ];
                $faqHelper->saveSection(FaqHelper::CREATE_ACTION, $sectionData);
                $errorCounter = 1;
                foreach ($data as $datum) {
                    $getParams = [
                        'question' => $datum['question'],
                        'section_code' => $sectionData['code']
                    ];
                    $singleFaq = $faqHelper->getSingleFaqByQuestion($getParams);
                    if (isset($singleFaq['id'])) {
                        //update
                        $faqData = [
                            'id' => $singleFaq['id'],
                            'question' => $datum['question'],
                            'answer' => $datum['answer'],
                            'meta_keyword' => $datum['question'] . ', ' . $datum['answer'],
                            'section_code' => $sectionData['code']
                        ];
                        $faqHelper->saveFaq(FaqHelper::UPDATE_ACTION, $faqData);
                    } else {
                        // create
                        $faqData = [
                            'question' => $datum['question'],
                            'answer' => $datum['answer'],
                            'meta_keyword' => $datum['question'] . ', ' . $datum['answer'],
                            'section_code' => $sectionData['code']
                        ];
                        if ($sectionData['code'] == 'errors') {
                            $faqData['error_code'] = 'custom-error-code-' . $errorCounter;
                            if (isset($datum['error_code']) && $datum['error_code']) {
                                $faqData['error_code'] = $datum['error_code'];
                            }

                            $errorCounter++;
                        }

                        $faqHelper->saveFaq(FaqHelper::CREATE_ACTION, $faqData);
                    }
                }
            }

            $faqHelper->buildSearchIndex();
            return $this->prepareResponse(
                [
                    'success' => true,
                    'message' => "Successfully synced."
                ]
            );
        } catch (Exception $exception) {
            return $this->prepareResponse(
                [
                    'success' => false,
                    'message' => $exception->getMessage()
                ]
            );
        }
    }

    /**
     * @return mixed
     */
    public function getAllFaqForCustomerAction() {
        $response = $this->getFaqHelper()->getAllFaqForCustomer($this->getRawBody());
        return $this->prepareResponse($response);
    }


    /**
     * @return mixed
     */
    public function getSearchSuggestionsAction() {
        $response = $this->getFaqHelper()->getSearchSuggestions($this->getRawBody());
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function searchAction() {
        $response = $this->getFaqHelper()->getSearchResult($this->getRawBody());
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function getAllFaqAction() {
        $response = $this->getFaqHelper()->getAllFaq();
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function getFaqForGridAction() {
        $response = $this->getFaqHelper()->getFaqForGrid($this->getRawBody());
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function addFaqAction() {
        $response = $this->getFaqHelper()->saveFaq(
            FaqHelper::CREATE_ACTION,
            $this->getRawBody()
        );
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function updateFaqAction() {
        $response = $this->getFaqHelper()->saveFaq(
            FaqHelper::UPDATE_ACTION,
            $this->getRawBody()
        );
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function deleteFaqAction() {
        $response = $this->getFaqHelper()->deleteFaq($this->getRawBody());
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function getSingleFaqAction() {
        $response = $this->getFaqHelper()->getSingleFaq($this->getRawBody());
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function buildSearchIndexAction() {
        $response = $this->getFaqHelper()->buildSearchIndex();
        return $this->prepareResponse($response);
    }

    /**
     * @return mixed
     */
    public function getFaqAction() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        try{
            $path = BP.DS.'app'.DS.'code'.DS.'amazon'.DS.'utility'.DS.'faq.json';
            $faqJson = file_get_contents($path);
            $faqs = json_decode($faqJson, true);
            $result = [
                'success' => true,
                'response' => $faqs,
                'message' => "FAQ fetched"
            ];
        }catch(Exception $e){
            $result = [
                'success' => false,
                'response' => [],
                'message' => $e->getMessage()
            ];
        }

        return $this->prepareResponse($result);
    }

    /**
     * @return mixed
     */
    private function getFaqHelper() {
        return $this->di->getObjectManager()->get(FaqHelper::class);
    }

    /**
     * @return mixed
     */
    private function getRawBody() {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        return $rawBody;
    }
}