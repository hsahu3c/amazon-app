<?php

namespace App\Frontend\Controllers;

use App\Core\Controllers\BaseController;
use App\Frontend\Components\Knowledgebase;

class KnowledgebaseController extends BaseController
{

    public function getDetailsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(Knowledgebase::class);
        return $this->prepareResponse($helper->getDetails($rawBody));
    }

    public function saveDetailsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(Knowledgebase::class);
        return $this->prepareResponse($helper->saveDetails($rawBody));
    }

    public function getTagsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(Knowledgebase::class);
        return $this->prepareResponse($helper->getTags());
    }

    public function getSectionsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(Knowledgebase::class);
        return $this->prepareResponse($helper->getSections());
    }

    public function deleteKnowledgeBaseAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(Knowledgebase::class);
        return $this->prepareResponse($helper->deleteKnowledgeBase($rawBody));
    }

    public function getSuggestionsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(Knowledgebase::class);
        return $this->prepareResponse($helper->getSuggestions($rawBody));
    }

    public function getKnowledgeBasesAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (str_contains((string) $contentType, 'application/json')) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $helper = $this->di->getObjectManager()->get(Knowledgebase::class);
        return $this->prepareResponse($helper->getKnowledgeBases($rawBody));
    }
}