<?php

namespace App\Connector\Controllers;

class CartController extends \App\Core\Controllers\BaseController
{

    public function getCartsAction()
    {

        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $cartsModel = new \App\Connector\Models\Cart;
        $responseData = $cartsModel->getCarts($rawBody);
        return $this->prepareResponse($responseData);
    }

    public function createCartAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }

        $cartData = $this->di->getObjectManager()->get('\App\Connector\Components\CartHelper')->getCartInRightFormat($this->di->getUser()->id, $rawBody);
        $cartModel = $this->di->getObjectManager()->create('\App\Connector\Models\Cart');
        return $this->prepareResponse($cartModel->createCart($cartData));
    }

    public function getCartAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $cartsModel = new \App\Connector\Models\CartContainer;
        $responseData = $cartsModel->getCartByID($rawBody);
        return $this->prepareResponse($responseData);
    }

    public function getAllCartsAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        $rawBody = [];
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        $cartsModel = new \App\Connector\Models\CartContainer;
        $responseData = $cartsModel->getCarts($rawBody);
        return $this->prepareResponse($responseData);
    }

    public function getCartByIdAction()
    {
        $cartsModel = new \App\Connector\Models\Cart();
        $responseData = $cartsModel->getCartById($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function uploadAction()
    {
        $cartsModel = new \App\Connector\Models\Cart();
        $responseData = $cartsModel->uploadCart($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function updateCartStatusAction()
    {
        $cartsModel = new \App\Connector\Models\Cart();
        $responseData = $cartsModel->updateCartStatus($this->request->get());
        return $this->prepareResponse($responseData);
    }

    public function syncAction(){
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        } else {
            $rawBody = $this->request->get();
        }

        return $this->prepareResponse($this->di->getObjectManager()->get('App\Connector\Components\CartHelper')->initiateCartSync($rawBody));
    }

    /**
     * Create Cart Action
     * @return string
     */
    public function importAction()
    {
        $contentType = $this->request->getHeader('Content-Type');
        if (strpos($contentType, 'application/json') !== false) {
            $rawBody = $this->request->getJsonRawBody(true);
        }else{
            $rawBody = $this->request->get();
        }

        $cart = $this->di->getObjectManager()->create('\App\Connector\Models\CartContainer');
        return $this->prepareResponse($cart->importCarts($rawBody));
    }

}
