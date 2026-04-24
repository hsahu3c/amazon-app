<?php
return [
	'mail_verification' => [
		'API_KEY' => '1a79877d24ec66fdd45712e530ad0c9175e7a293b24cc6c9080d314bf4a2'
	],

	'log_user_activity'=>[
		'/connector/product/searchProduct'               => true,
		'/connector/product/inventorySync'               => true,
		'/connector/product/priceSync'                   => true,
		'/connector/product/imageSync'                   => true,
		'/connector/product/group'                 		 => true,
		'/connector/product/upload'                  	 => true,
		'/connector/product/productSync'                 => true,
		'/connector/profile/savePartialChunk'            => true,
		'/connector/product/deleteFromMarketplace'       => true,
		'/amazon/product/refreshError'                   => true,
		'/amazon/product/markErrorResolved'              => true,
		'/product/deleteFromMarketplace'                 => true,
		'/connector/config/saveConfig'                   => true,
		'/connector/profile/saveProfile/'                 => true,
		'/connector/order/importOrder'                   => true,
		'/request/moveOrderInArchiveCollection'          => true,
		'/frontend/product/checkAndDeleteProduct'        => true,
		'/plan/admin/addAdditionalUserService'           => true,
		'/plan/plan/customPayment'                  	 => true,
		'/plan/admin/waiveOffPendingSettlement'          => true,
		'/connector/product/import'                  	 => true,
	],

	'adminPanel_bda_acl' => ["63c65aac31935c493c0bdf53","6553594b57f4fdbd1e05a8f3","648ff0dbb76f5018de691e68"],
	'events' => [
        'application:afterCreateSubscriptionForOrderSync' => [
            'trigger_onboarding_completed' => App\Frontend\Components\AmazonMulti\CrmHelper::class
		],
		'application:beforeHandleRequest' => [
			'log_user_activity' => App\Frontend\Components\Helper::class
		]
    ],
];
