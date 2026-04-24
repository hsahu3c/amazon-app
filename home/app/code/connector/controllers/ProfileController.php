<?php

namespace App\Connector\Controllers;

use Phalcon\Di;
use App\Core\Controllers\BaseController;
use App\Connector\Models\ProductContainer;

class ProfileController extends BaseController
{
    public function createProfileAction()
    {
        $connectors = $this->di->getObjectManager()->get('App\Connector\Components\Connectors')->getAllConnectors($this->di->getUser()->getId());
        return $this->prepareResponse(['success' => true, 'code' => '', 'message' => '', 'data' => $connectors]);
    }

    public function getAllTemplatesAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $templates = $this->di->getConfig()->get('templates')->toArray();
        if (isset($rawBody['marketplace'])) {
            $templates = [
                $rawBody['marketplace'] => $templates[$rawBody['marketplace']]
            ];
        }

        return $this->prepareResponse([
            'success' => true,
            'marketplace' => $templates
        ]);
    }

    public function saveTemplateAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!isset($rawBody['marketplace'])) {
            return $this->prepareResponse(["success" => false, "message" => "Required param `marketplace` missing "]);
        }

        $product = $this->di->getObjectManager()->create('\App\Connector\Models\Profile');
        return $this->prepareResponse($product->getTemplate($rawBody));
    }


    public function getAllProfilesAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $this->di->getUser()->id;
        $profileModel = new \App\Connector\Models\Profile();
        $response = $profileModel->getAllProfiles($rawBody);
        return $this->prepareResponse($response);
    }

    public function getSourceAttributeTypeAction()
    {
        $profileDetails = $this->di->getRequest()->get();
        $profileModel = new \App\Connector\Models\Profile();
        return $this->prepareResponse($profileModel->getSourceAttributeType($profileDetails));
    }

    /* This controller action is not used in shopify, it will be used in future just for cases where we need to send another request to get attributes */
    public function getSourceAttributesAction()
    {
        $filePath = BP . DS . 'var' . DS . 'upload' . DS . $this->di->getUser()->id . DS . $this->di->getRequest()->get('filename');
        if (!file_exists($filePath)) {
            return ['success' => false, 'code' => 'enter_profile_details_first', 'message' => 'Enter profile details first'];
        }
        $attributes = [
            'basic_attributes' => [
                0 => [
                    'code' => 'name',
                    'mapped' => false
                ]
            ],
            'required_attributes' => [
                0 => [
                    'code' => 'sku',
                    'mapped' => false
                ]
            ],
            'optional_attributes' => [
                0 => [
                    'code' => 'size',
                    'mapped' => false,
                    'options' => [
                        [
                            'value' => 'Small',
                            'mapped' => false
                        ],
                        [
                            'value' => 'Medium',
                            'mapped' => false
                        ],
                        [
                            'value' => 'Large',
                            'mapped' => false
                        ]
                    ]
                ]
            ]
        ];

        return $this->prepareResponse(['success' => true, 'data' => $attributes]);
    }

    public function getUploadOptionsAction()
    {
        $profileModel = new \App\Connector\Models\Profile();
        return $this->prepareResponse($profileModel->getUploadOptions());
    }

    public function saveAttributeMappingAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $profileModel = new \App\Connector\Models\Profile();
        return $this->prepareResponse($profileModel->saveAttributeMapping($rawBody));
    }

    public function assignProductsToProfileAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $profileModel = new \App\Connector\Models\Profile();
        return $this->prepareResponse($profileModel->assignProductsToProfile($rawBody));
    }

    public function uploadProductsAction()
    {
        $profileId = $this->di->getRequest()->get('id');
        $profileModel = new \App\Connector\Models\Profile();
        return $this->prepareResponse($profileModel->uploadProducts($profileId));
    }

    public function uploadProductChunkAction()
    {
        $productChunkDetails = $this->di->getRequest()->get();
        $profileModel = new \App\Connector\Models\Profile();
        return $this->prepareResponse($profileModel->uploadProductChunk($productChunkDetails));
    }


    public function setAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $profile = new \App\Connector\Models\Profile;
        return $this->prepareResponse($profile->setProfile($rawBody));
    }

    public function getMatchingProfilesAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }
        //Added this check for user authentication
        if(isset($rawBody['user_id'])) {
            $rawBody['user_id'] = $this->di->getUser()->id;
        }
        $profile = new \App\Connector\Models\Profile;
        return $this->prepareResponse($profile->getMatchingProfiles($rawBody));
    }

    public function getProfileAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $profile = new \App\Connector\Models\Profile;
        return $this->prepareResponse($profile->getProfileById($rawBody));
    }


    /**
     * @desc get category attributes
     * @param get parameter - marketplace
     * @return array
     */

    public function getCategoryAttributeAction()
    {
        $marketPlaceDetails = $this->di->getRequest()->get();
        $catAttrModel = new \App\Connector\Models\CategoryAttribute;
        return $this->prepareResponse($catAttrModel->getAllAttribute($marketPlaceDetails));
    }


    public function createUpdateCategoryAttributeAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            $catAttrModel = new \App\Connector\Models\CategoryAttribute;
            $res = $catAttrModel->createCategoryAttribute($rawBody);
            $returnData = $res;
        } else {
            $returnData = ['success' => false, 'code' => 'header_missing', 'message' => 'Header missing'];
        }

        return $this->prepareResponse($returnData);
    }

    public function deleteCategoryAttributeAction()
    {
        $rawBody = $this->request->getJsonRawBody(true);
        $catAttrModel = new \App\Connector\Models\CategoryAttribute;
        return $this->prepareResponse($catAttrModel->deleteAttribute($rawBody));
    }


    public function getRootCategoryAction()
    {
        $marketPlaceDetails = $this->di->getRequest()->get();
        $catModel = new \App\Connector\Models\Category;
        return $this->prepareResponse($catModel->getRootCategory($marketPlaceDetails));
    }

    public function getCatrgoryNextLevelAction()
    {
        $marketPlaceDetails = $this->di->getRequest()->get();
        $catModel = new \App\Connector\Models\Category;
        return $this->prepareResponse($catModel->getChildCategory($marketPlaceDetails));
    }

    public function searchCategoryAction()
    {
        $filters = $this->di->getRequest()->get();
        if (!empty($filters)) {
            $catModel = new \App\Connector\Models\Category;
            $returnData = $catModel->searchCategory($filters);
        } else {
            $returnData = ['success' => false, 'code' => 'filter_missing', 'message' => 'Filter missing'];
        }

        return $this->prepareResponse($returnData);
    }


    public function getCategoryAction()
    {
        $marketPlaceDetails = $this->di->getRequest()->get();
        $catModel = new \App\Connector\Models\Category;
        return $this->prepareResponse($catModel->getCategory($marketPlaceDetails));
    }

    public function createUpdateCategoryChildAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);

            $catAttrModel = new \App\Connector\Models\Category;
            $res = $catAttrModel->addChildren($rawBody);
            $returnData = $res;
        } else {
            $returnData = ['success' => false, 'code' => 'header_missing', 'message' => 'Header missing'];
        }

        return $this->prepareResponse($returnData);
    }



    public function createUpdateCategoryAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            if (empty($rawBody)) {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            } else {
                $catModel = new \App\Connector\Models\Category;
                $res = $catModel->createCategory($rawBody);
                $returnData = $res;
            }
        } else {
            $returnData = ['success' => false, 'code' => 'header_missing', 'message' => 'Header missing'];
        }

        return $this->prepareResponse($returnData);
    }

    public function deleteCategoryAction()
    {
        $rawBody = $this->request->getJsonRawBody(true);
        $catModel = new \App\Connector\Models\Category;
        return $this->prepareResponse($catModel->deleteCategory($rawBody));
    }

    /**
     * @desc create profile
     * @param content-type - json
     * @return array - ["success"=>true/false,"message"=>"","code","data"=>[]]
     */

    public function createUpdateAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $this->di->getUser()->getId();
        $getParams  = $this->di->getRequest()->get();

        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            if (!empty($rawBody)) {
                $profileModel = new \App\Connector\Models\Profile\Model;
                $overWriteExistingProducts = true;
                if (isset($getParams['overWriteExistingProducts'])) {
                    $overWriteExistingProducts = (int) $getParams['overWriteExistingProducts'];
                }

                $res = $profileModel->createUpdateProfile($rawBody, $overWriteExistingProducts);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $returnData = ['success' => false, 'code' => 'header_missing', 'message' => 'Header missing'];
        }

        return $this->prepareResponse($returnData);
    }

    public function saveProfileAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $this->di->getUser()->getId();

        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            if (!empty($rawBody)) {
                $profileModel = new \App\Connector\Components\Profile\ProfileHelper;
                $res = $profileModel->saveProfile($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $returnData = ['success' => false, 'code' => 'header_missing', 'message' => 'Header missing'];
        }

        return $this->prepareResponse($returnData);
    }

    public function getProfileDataAction()
    {
        $rawBody = $this->di->getRequest()->get();
        $profileModel = new \App\Connector\Components\Profile\ProfileHelper;
        $returnArr = $profileModel->getProfileData($rawBody);
        return $this->prepareResponse($returnArr);
    }

    public function deleteProfileAction()
    {
        $rawBody = $this->di->getRequest()->get();
        //Added this check for user authentication
        if(isset($rawBody['user_id'])) {
            $rawBody['user_id'] = $this->di->getUser()->id;
        }
        $profileModel = new \App\Connector\Components\Profile\ProfileHelper;
        $returnArr = $profileModel->deleteProfile($rawBody);
        return $this->prepareResponse($returnArr);
    }

    public function validateProfieNameAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $this->di->getUser()->getId();

        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            if (!empty($rawBody)) {
                $profileModel = new \App\Connector\Components\Profile\Validation;
                $res = $profileModel->validateProfieName($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $returnData = ['success' => false, 'code' => 'header_missing', 'message' => 'Header missing'];
        }

        return $this->prepareResponse($returnData);
    }

    public function getProfileProductsCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $this->di->getUser()->getId();
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            if (!empty($rawBody)) {
                $profileModel = new \App\Connector\Components\Profile\QueryProducts;
                $res = $profileModel->getProfileProductsCount($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $rawBody = $this->di->getRequest()->get();
            $profileModel = new \App\Connector\Components\Profile\QueryProducts;
            $returnData = $profileModel->getProfileProductsCount($rawBody);
        }

        return $this->prepareResponse($returnData);
    }


    public function getproductsByProfileIdAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $this->di->getUser()->getId();
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            if (!empty($rawBody)) {
                $profileModel = new \App\Connector\Components\Profile\GetProductProfileMerge;
                $res = $profileModel->getproductsByProfile($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $rawBody = $this->di->getRequest()->get();
            $profileModel = new \App\Connector\Components\Profile\GetProductProfileMerge;
            $returnData = $profileModel->getproductsByProfile($rawBody);
        }

        return $this->prepareResponse($returnData);
    }

    public function getproductsByProductIdsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $this->di->getUser()->getId();
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            if (!empty($rawBody)) {
                $profileModel = new \App\Connector\Components\Profile\GetProductProfileMerge;
                $res = $profileModel->getproductsByProductIds($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $rawBody = $this->di->getRequest()->get();
            $profileModel = new \App\Connector\Components\Profile\GetProductProfileMerge;
            $returnData = $profileModel->getproductsByProductIds($rawBody);
        }

        return $this->prepareResponse($returnData);
    }

    public function getQueryProductsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $this->di->getUser()->getId();
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            if (!empty($rawBody)) {
                $profileModel = new \App\Connector\Components\Profile\QueryProducts;
                $res = $profileModel->getQueryProducts($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $rawBody = $this->di->getRequest()->get();
            $profileModel = new \App\Connector\Components\Profile\QueryProducts;
            $returnData = $profileModel->getQueryProducts($rawBody);
        }

        return $this->prepareResponse($returnData);
    }


    public function getQueryProductsCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $this->di->getUser()->getId();
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            if (!empty($rawBody)) {
                $profileModel = new \App\Connector\Components\Profile\QueryProducts;
                $res = $profileModel->getQueryProductsCount($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $rawBody = $this->di->getRequest()->get();
            $profileModel = new \App\Connector\Components\Profile\QueryProducts;
            $returnData = $profileModel->getQueryProductsCount($rawBody);
        }

        return $this->prepareResponse($returnData);
    }

    public function saveDefaultSettingsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $this->di->getUser()->getId();
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            if (!empty($rawBody)) {
                $profileModel = new \App\Connector\Components\Profile\DefaultSettings;
                $res = $profileModel->saveDefaultSettings($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $rawBody = $this->di->getRequest()->get();
            $profileModel = new \App\Connector\Components\Profile\DefaultSettings;
            $returnData = $profileModel->saveDefaultSettings($rawBody);
        }

        return $this->prepareResponse($returnData);
    }

    public function getDefaultSettingsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $this->di->getUser()->getId();
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            if (!empty($rawBody)) {
                $profileModel = new \App\Connector\Components\Profile\DefaultSettings;
                $res = $profileModel->getDefaultSettings($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $rawBody = $this->di->getRequest()->get();
            $profileModel = new \App\Connector\Components\Profile\DefaultSettings;
            $returnData = $profileModel->getDefaultSettings($rawBody);
        }

        return $this->prepareResponse($returnData);
    }

    /**
     * @desc get profile
     * @return json
     */

    public function getAction()
    {
        $rawBody = $this->di->getRequest()->get();
        $profileModel = new \App\Connector\Models\Profile\Model;
        $returnArr = $profileModel->getProfile($rawBody);
        return $this->prepareResponse($returnArr);
    }

    public function deleteAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->di->getRequest()->get();
        }

        $profile = new \App\Connector\Models\Profile\Model;
        if (!empty($rawBody)) {
            $returnData = $profile->deleteProfile($rawBody);
        } else {
            $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
        }

        return $this->prepareResponse($returnData);
    }




    public function createUpdateTemplateAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $this->di->getUser()->getId();

        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            if (!empty($rawBody)) {
                $templateModel = new \App\Connector\Models\Profile\Template;
                $res = $templateModel->createUpdateTemplate($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $returnData = ['success' => false, 'code' => 'header_missing', 'message' => 'Header missing'];
        }

        return $this->prepareResponse($returnData);
    }

    public function deleteTemplateAction()
    {
        $templateModel = new \App\Connector\Models\Profile\Template;
        $rawBody = $this->request->getJsonRawBody(true);
        if (!empty($rawBody)) {
            $returnData = $templateModel->deleteTemplates($rawBody);
        } else {
            $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
        }

        return $this->prepareResponse($returnData);
    }


    public function searchTemplateAction()
    {
        $filters = $this->di->getRequest()->get();
        if (!empty($filters)) {
            $templateModel = new \App\Connector\Models\Profile\Template;
            $returnData = $templateModel->searchTemplates($filters);
        } else {
            $returnData = ['success' => false, 'code' => 'filter_missing', 'message' => 'Filter missing'];
        }

        return $this->prepareResponse($returnData);
    }

    public function getAllCategoryAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if ($code = $rawBody['target']['marketplace']) {
            if ($model = $this->di->getConfig()->connectors->get($code)->get('source_model')) {
                $data = $this->di->getObjectManager()->get($model)->getCategory($rawBody);
                if (count($data['data']) > 0) {
                    return $this->prepareResponse(['success' => true, 'data' => $data['data']]);
                }
                return $this->prepareResponse(['success' => false, 'data' => $data['data']]);
            }
        } else {
            return $this->prepareResponse(['success' => false, 'code' => 'missing_required_params', 'message' => 'Missing Code.']);
        }
    }

    public function getProfileProduct()
    {
        $filters = $this->di->getRequest()->get();
        $profileHelperObj = new \App\Connector\Models\Profile\Helper();
        $profileHelperObj->process_data = $filters;
        $returnData = $profileHelperObj->getProducts();
        return $this->prepareResponse($returnData);
    }

    public function getCategoryAttributesAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if ($code = $rawBody['target']['marketplace']) {
            if ($model = $this->di->getConfig()->connectors->get($code)->get('source_model')) {
                $data = $this->di->getObjectManager()->get($model)->getCategoryAttributes($rawBody);
                if (isset($data['data']) && is_array($data['data']) && count($data['data']) > 0) {
                    return $this->prepareResponse(['success' => true, 'data' => $data['data']]);
                } else {
                    return $this->prepareResponse(['success' => false, 'data' => $data['data'] ?? [], 'message' => $data['message'] ?? '']);
                }
                return $this->prepareResponse(['success' => false, 'data' => $data['data']]);
            }
        } else {
            return $this->prepareResponse(['success' => false, 'code' => 'missing_required_params', 'message' => 'Missing Code.']);
        }
    }

    public function categorySearchAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if ($code = $rawBody['target']['marketplace']) {
            if ($model = $this->di->getConfig()->connectors->get($code)->get('source_model')) {
                $data = $this->di->getObjectManager()->get($model)->categorySearch($rawBody);
                if (count($data['data']) > 0) {
                    return $this->prepareResponse(['success' => true, 'data' => $data['data']]);
                }
                return $this->prepareResponse(['success' => false, 'data' => $data['data'] ?? null]);
            }
        } else {
            return $this->prepareResponse(['success' => false, 'code' => 'missing_required_params', 'message' => 'Missing Code.']);
        }
    }


    public function validateProfileAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $this->di->getUser()->getId();

        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            if (!empty($rawBody)) {
                $profileModel = new \App\Connector\Components\Profile\Validation;
                $res = $profileModel->validateProfile($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $returnData = ['success' => false, 'code' => 'header_missing', 'message' => 'Header missing'];
        }

        return $this->prepareResponse($returnData);
    }

    public function getProfileDataCountAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
            if (!empty($rawBody)) {
                $profileModel = new \App\Connector\Components\Profile\ProfileHelper;
                $res = $profileModel->getProfileDataCount($rawBody);
                $returnData = $res;
            } else {
                $returnData = ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
            }
        } else {
            $rawBody = $this->di->getRequest()->get();
            $profileModel = new \App\Connector\Components\Profile\ProfileHelper;
            $returnData = $profileModel->getProfileDataCount($rawBody);
        }

        return $this->prepareResponse($returnData);
    }

    public function getProfiledataByProjectionAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!empty($rawBody)) {
            $profileModel = new \App\Connector\Components\Profile\ProfileHelper;
            $res = $profileModel->getProfiledataByProjection($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }


    public function savePartialChunkAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!empty($rawBody)) {
            $profileModel = new \App\Connector\Components\Profile\PartialChunkHelper;
            $res = $profileModel->savePartialChunk($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }

    public function getProfileChunkAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        if (!empty($rawBody)) {
            $profileModel = new \App\Connector\Components\Profile\PartialChunkHelper;
            $res = $profileModel->getProfileChunk($rawBody);
            return $this->prepareResponse($res);
        }

        return ['success' => false, 'code' => 'body_missing', 'message' => 'Body missing'];
    }
}
