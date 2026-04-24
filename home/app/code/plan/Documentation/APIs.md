# Plan APIs

## User APIs

> ### plan/plan/get

|   method   | headers | params |
| -------------- | -------------- | -------------- | 
| GET | Ced-Target-Id | type(optional) |
|  | Ced-Target-Name | category(optional) |
|  | Ced-Source-Id | plan_id(optional)|
|  | Ced-Source-Name | |
|  | appTag | |

<b>Description:</b> Use to fetch plans or a single plan or other types like add_on etc.

---

> ### plan/plan/choose

|   method   | headers | params |
| -------------- | -------------- | -------------- | 
| POST | Ced-Target-Id | plan_id(required) |
|  | Ced-Target-Name | |
|  | Ced-Source-Id | |
|  | Ced-Source-Name | |
|  | appTag | |


<b>Description:</b> Use to activate plan for the client.

---

> ### plan/plan/settleServices

|   method   | headers | params |
| -------------- | -------------- | -------------- | 
| POST | Ced-Target-Id | type(required) |
|  | Ced-Target-Name | amount(required) |
|  | Ced-Source-Id | |
|  | Ced-Source-Name | |
|  | appTag | |

<b>Description:</b> Use to settle services or to pay excess usage charges in the app.

---

> ### plan/plan/getActive

|   method   | headers |
| -------------- | -------------- | 
| GET | Ced-Target-Id | 
|  | Ced-Target-Name |
|  | Ced-Source-Id |
|  | Ced-Source-Name |
|  | appTag |


<b>Description:</b> to get the details of the active plan and all teh related plan data for current state

<details><summary>getActive response</summary>

```json getActive response
{
        "success": true,
        "message": "",
        "data": {
            "active_plan": {
                "_id": "1444",
                "user_id": "6531414651e4bdebdf04932c",
                "type": "active_plan",
                "marketplace": "amazon",
                "source_marketplace": "shopify",
                "target_marketplace": "amazon",
                "status": "active",
                "capped_amount": 0.01,
                "created_at": "2023-11-06 12:36:27",
                "updated_at": "2023-11-06 12:36:27",
                "plan_details": {
                    "_id": {
                        "$oid": "6530e43e4f7e3db9e77dde93"
                    },
                    "code": "enterprise",
                    "title": "Enterprise",
                    "description": "Up to 1000000 Orders",
                    "validity": 365,
                    "type": "plan",
                    "billed_type": "yearly",
                    "custom_price": 11868,
                    "scope": "global",
                    "sort_order": 9,
                    "marketplace": "amazon",
                    "source_marketplace": "shopify",
                    "target_marketplace": "amazon",
                    "discounts": [
                        {
                            "name": "Yearly Offer",
                            "type": "fixed",
                            "value": "989"
                        },
                        {
                            "name": "Festive Offer",
                            "type": "percentage",
                            "value": "20"
                        }
                    ],
                    "features": [
                        {
                            "feature": "Manage 2x order credits",
                            "description": "Continue receiving new orders after exhausting credits. $3 charge for every 10 orders synced beyond plan limit.",
                            "image": "https:\/\/amazon-mail-images.s3.ap-northeast-3.amazonaws.com\/Images\/setting.png"
                        },
                        {
                            "feature": "Unlimited Bulk Upload",
                            "description": "In the free plan, you can only upload up to 50 products at a time; however, in this plan, you can upload as many products as you need.",
                            "image": "https:\/\/amazon-mail-images.s3.ap-northeast-3.amazonaws.com\/Images\/unlimitedListing.png"
                        },
                        {
                            "feature": "24\/7 Email & Chat Support",
                            "description": "",
                            "image": "https:\/\/amazon-mail-images.s3.ap-northeast-3.amazonaws.com\/Images\/email&chat.png"
                        },
                        {
                            "feature": "Connect multiple Amazon accounts globally",
                            "description": "Connect multiple Global Amazon accounts excluding Egypt, Saudi Arabia and Turkey.",
                            "image": "https:\/\/amazon-mail-images.s3.ap-northeast-3.amazonaws.com\/Images\/multipleAcc.png"
                        },
                        {
                            "feature": "Inventory Control",
                            "description": "Control or customize your inventory as per your need.",
                            "image": "https:\/\/amazon-mail-images.s3.ap-northeast-3.amazonaws.com\/Images\/inventory.png"
                        },
                        {
                            "feature": "Link Existing Listings",
                            "description": "Link Shopify products with existing Amazon listings without hassle.",
                            "image": "https:\/\/amazon-mail-images.s3.ap-northeast-3.amazonaws.com\/Images\/setting.png"
                        },
                        {
                            "feature": "Currency Control",
                            "description": "Manage your currency if selling in different regions",
                            "image": "https:\/\/amazon-mail-images.s3.ap-northeast-3.amazonaws.com\/Images\/currency.png"
                        },
                        {
                            "feature": "Unlimited Listings",
                            "description": "",
                            "image": "https:\/\/amazon-mail-images.s3.ap-northeast-3.amazonaws.com\/Images\/unlimitedListing.png"
                        },
                        {
                            "feature": "Refund Order Syncing",
                            "description": "Sync refund orders from Shopify to Amazon",
                            "image": "https:\/\/amazon-mail-images.s3.ap-northeast-3.amazonaws.com\/Images\/setting.png"
                        },
                        {
                            "feature": "Cancelled Order Syncing",
                            "description": "Sync refund orders from Shopify to Amazon",
                            "image": "https:\/\/amazon-mail-images.s3.ap-northeast-3.amazonaws.com\/Images\/setting.png"
                        },
                        {
                            "feature": "Dedicated Account Manager",
                            "description": "",
                            "image": "https:\/\/amazon-mail-images.s3.ap-northeast-3.amazonaws.com\/Images\/email&chat.png"
                        }
                    ],
                    "badge": "",
                    "trial_days_limit": "",
                    "trial_credit_limit": "",
                    "services_groups": [
                        {
                            "title": "Order Management",
                            "description": "Order Management",
                            "services": [
                                {
                                    "title": "Order Sync",
                                    "code": "upto_1000000_orders",
                                    "type": "order_sync",
                                    "required": 1,
                                    "service_charge": "0",
                                    "expiring_at": "",
                                    "discounts": [],
                                    "sort_order": 1,
                                    "trial_days_limit": "",
                                    "trial_credit_limit": "",
                                    "prepaid": {
                                        "service_credits": 1000000,
                                        "validity_changes": "Add",
                                        "fixed_price": 0,
                                        "reset_credit_after": 0,
                                        "expiring_at": "0"
                                    },
                                    "postpaid": {
                                        "per_unit_usage_price": "3",
                                        "unit_qty": 10,
                                        "capped_credit": 1000000,
                                        "validity_changes": "Replace"
                                    }
                                }
                            ]
                        }
                    ],
                    "plan_id": "18",
                    "category": "regular",
                    "offered_price": 8703.2
                },
                "source_id": "164",
                "app_tag": "amazon_sales_channel",
                "test_user": true
            },
            "user_service": {
                "_id": "1270",
                "type": "user_service",
                "service_type": "order_sync",
                "marketplace": "amazon",
                "source_marketplace": "shopify",
                "target_marketplace": "amazon",
                "user_id": "6531414651e4bdebdf04932c",
                "created_at": "2023-10-31 04:39:59",
                "updated_at": "2023-11-06 12:36:27",
                "activated_on": "2023-11-06",
                "expired_at": "2023-11-30",
                "prepaid": {
                    "service_credits": 1000000,
                    "available_credits": 999000,
                    "total_used_credits": 1000
                },
                "postpaid": {
                    "per_unit_usage_price": 3,
                    "unit_qty": 10,
                    "capped_credit": 1000000,
                    "available_credits": 1000000,
                    "total_used_credits": 0
                },
                "source_id": "164",
                "app_tag": "amazon_sales_channel",
                "test_user": true
            },
            "sync_activated": true,
            "payment_info": {
                "_id": "1443",
                "type": "payment",
                "user_id": "6531414651e4bdebdf04932c",
                "status": "approved",
                "plan_id": "18",
                "quote_id": "1436",
                "source_marketplace": "shopify",
                "target_marketplace": "amazon",
                "method": "shopify",
                "marketplace_data": {
                    "id": 26118062232,
                    "name": "Enterprise",
                    "price": "8703.20",
                    "billing_on": "2023-11-06",
                    "status": "active",
                    "created_at": "2023-11-06T18:06:17+05:30",
                    "updated_at": "2023-11-06T18:06:23+05:30",
                    "activated_on": "2023-11-06",
                    "return_url": "https:\/\/staging-amazon-sales-channel-app-backend.cifapps.com\/plan\/plan\/check?quote_id=1436&type=recurring&shop=mannshoppingstore.myshopify.com&state=6531414651e4bdebdf04932c",
                    "test": true,
                    "cancelled_on": null,
                    "trial_days": 0,
                    "trial_ends_on": "2023-11-06",
                    "api_client_id": 27787165697,
                    "decorated_return_url": "https:\/\/staging-amazon-sales-channel-app-backend.cifapps.com\/plan\/plan\/check?charge_id=26118062232&quote_id=1436&shop=mannshoppingstore.myshopify.com&state=6531414651e4bdebdf04932c&type=recurring",
                    "currency": "USD"
                },
                "created_at": "2023-11-06 12:36:27",
                "updated_at": "2023-11-06 12:36:27",
                "source_id": "164",
                "app_tag": "amazon_sales_channel",
                "test_user": true,
                "plan_status": "active"
            },
            "next_recommended_plan": [],
            "next_recommended_plan_msg": ""
        },
        "ip": "103.97.184.122",
        "time_taken": "0.074"
    }
```
</details>

---

> ### plan/plan/isPlanActive

|   method   | headers |
| -------------- | -------------- | 
| GET | Ced-Target-Id | 
|  | Ced-Target-Name |
|  | Ced-Source-Id |
|  | Ced-Source-Name |
|  | appTag |

<b>Description:</b> to check if plan is active or not



---------------------------------------------------------------------

## Admin APIs

> ### plan/plan/cancelRecurryingCharge

<b>Authentication:</b>

admin token

<b>Required params:</b>

user_id

<b>Description:</b> used to cancel recurring of the current plan for a user

---

> ### plan/plan/forcefullyFreePlanPurchase

<b>Authentication:</b>

admin token

<b>Required params:</b>

user_id

<b>Description:</b> to activate a free plan forcefully for a client

---

> ### plan/plan/downgradeToFree

<b>Authentication:</b>

admin token

<b>Required params:</b>

user_id

<b>Description:</b> used to downgrade a client to free plan if client is on a paid plan

---

> ### plan/plan/refundApplicationCredits

<b>Required params:</b>

user_id | description | amount

<b>Description:</b> used to refund credits to the clients

---


> ### plan/plan/customPayment

<b>Description:</b> to make custom payments, custom plan activation and combo pack plan activation

<b>Payload</b>:

For making custom payments
```json
{
     "type": "custom",//representing the type of customization values can be custom, plan, combo - means we can have custom payment, custom plan and combo plan support
     "username": "mannshoppingstore.myshopify.com",//for user details user_id can also be used - required feild any one of them
     "title": "test-custom-payment-6",//title for the custom payment
     "custom_price": 5,//price being charged
     "plan_id": "test-plan-custom-payment-5"//a customized plan id for custom payments
}
```
For custom plan and their activation

```json
{
    "type": "plan",
    "username": "mannshoppingstore.myshopify.com",
    "title": "test-custom-plan-1",
    "custom_price": 18,
    "plan_id": "abc",
    "payment_type": "onetime",//can be recurring or onetime
    //"billed_type": "monthly",
    "validity": 45,//for onetime it is required field
    "base_plan_id": "2",//base plan's id
     "services": [//for chaging the credits
        {
            "type": "order_sync",
            "prepaid": 300,
            "postpaid": 100
        }
    ]
}
```



---

## Tester APIs

> ### plan/plan/creditUpdateTest

<b>Description:</b> can be used by test users to update their usage credits, expiry dates, active_plan created_at date to test their common cases

There are different cases:

<b>Method: </b> GET

```json
    {
        "type" : "<type of doc you want to fetch>"// values can be user_service, active_plan, payment, settlement_invoice
    }
```


<b>Method: </b> POST

> to update user_service

```json
        {
            "type" : "user_service",
            "prepaid": { //when you want to change the prepaid credits you can use this
                "total_used_credits": 100,//this is the total used
                "available_credits": 0 //this is the available credits
            },
            "postpaid": { //when you want to change the postpaid credits you can use this key
                "total_used_credits": 0, //the total credits used
                "available_credits": 10, // the available credits
                "capped_credit": 10 // the total credits offered
            },
            "expired_at": "2023-11-02", // this can be passed if you want to change the expiry
            "deactivate_on": "2023-11-02" // this can be passed if you want to change the deaactivation date if exist
    }
```

> to update active_plan created_at date

```json
    {
    "type" : "active_plan",
    "created_at": "2023-10-31 00:00:00"//this is the date you want to change
    }

```

> to update last payment date in settlement invoice

```json
    {
    "type" : "settlement_invoice",
    "last_payment_date": "2023-10-31"
    }
```


---

