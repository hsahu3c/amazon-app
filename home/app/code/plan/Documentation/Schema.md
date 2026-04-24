# Plan, Payment and Transaction Schemas

## Plan

> 1. Plan schema

<b>Description: </b> this is the standard schema followed in plan module for plans

<details><summary>Plan Schema</summary>

```json

    {
    "code": "growth",//a unique key to identify the plan code
    "title": "Growth",//title for the plan
    "description": "Up to 2500 Orders",//description for the plan
    "validity": 365,//it identifies the duration of the plan validity
    "type": "plan",//for classifying types of plan like plan, add_on etc.
    "billed_type": "yearly",//to classify the bill duration like monthly, yearly
    "custom_price": 2268,//the actual price of the plan
    "scope": "global",//to identify the scope of the plan
    "sort_order": 5,//for sorting purpose
    "marketplace": "amazon",//supported marketplace
    "source_marketplace": "shopify",//for source
    "target_marketplace": "amazon",//for target
    "discounts": [//to add any discounts
        {
        "name": "Yearly Offer",//name of the discount
        "type": "fixed",//type of the discount like fixed, percentage etc.
        "value": "189"//value of the discount
        },
        {
        "name": "Festive Offer",
        "type": "percentage",
        "value": "10"
        }
    ],
    "badge": "",//to add any badges if needed
    "features": [//to list the features of the plans
        {
        "feature": "Manage 2x order credits",
        "description": "Continue receiving new orders after exhausting credits. $3 charge for every 10 orders synced beyond plan limit.",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
        },
        {
        "feature": "Dedicated Account Manager",
        "description": "",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/email&chat.png"
        }
    ],
    "trial_days_limit": "",
    "trial_credit_limit": "",
    "services_groups": [//for services we offer, services can be a group of different type like order, product etc. which is in the form of array of objects
        {
        "title": "Order Management",//title of the service
        "description": "Order Management",//service description
        "services": [//it is possible that a service group also have multiple type of sub services
            {
            "title": "Order Sync",//title
            "code": "upto_2500_orders",//description
            "type": "order_sync",//this should be unique to identitfy the type of service it is like order_sync, mcf_order_sync, product_import etc.
            "required": 1,//to define the requirement level of service
            "service_charge": "0",//for any service charge
            "expiring_at": "",//to define their expiry
            "discounts": [],//it can also have discounts as above
            "sort_order": 1,
            "trial_days_limit": "",
            "trial_credit_limit": "",
            "prepaid": {//information about the perpaid or actual credits we will offer with plan
                "service_credits": 2500,//this is the actual offered limit we will provide with the plan for usage
                "validity_changes": "Add",//defines the credit replacement type if add means when plan will be upgarded the used credits will be added, if replace the used credits will be replaced with new one 
                "fixed_price": 0,
                "reset_credit_after": 0,
                "expiring_at": "0"
            },
            "postpaid": {//to give a backup to client for the cases when they have no plan  prepaid limits left
                "per_unit_usage_price": "3",//charge of the postpaid used
                "unit_qty": 10,//on the qty of credits used
                "capped_credit": 2500,//max limit of usage
                "validity_changes": "Replace"//same as prepaid
            }
            }
        ]
        }
    ],
    "plan_id": "13",
    "category": "regular",//defines the category of the plan like regular, marketing, business etc.
    "payment_type" : "recurring",//not required field as by default it will be recurring but it can be onetime as well to change the payment_type
    "tag": {//to add any tags if needed like recommended plan etc along with its message
        "type": "recommended",
        "message": "On the basis of order usage in the app"
        }
    }

```
</details>

---

## Payment

> 1. Quote

<b>Description: </b> schema to define quotes in plan

<details><summary>Quote Schema</summary>

```json
    {
  "_id": "1498",
  "type": "quote",//type is the payment_details model is used to diffrentiate among different type of docs, for quote its value will be "quote"  
  "user_id": "6531414651e4bdebdf04932c",
  "plan_id": "9",//identifies for what plan it is being created, it can ve settlement id as well
  "source_marketplace": "shopify",
  "target_marketplace": "amazon",
  "status": "approved",//status can be active, waiting_for_payment, approved, rejected
  "created_at": "2023-11-15 11:09:36",
  "updated_at": "2023-11-15 11:09:36",
  "expire_at": "2023-11-18 11:09:36",//it defines the duration till the quote can be used
  "domain_name": "mannshoppingstore.myshopify.com",
  "plan_details": {//details of the plan or settlement
    "_id": {
      "$oid": "6530e43e4f7e3db9e77dde83"
    },
    "code": "free",
    "title": "Free",
    "description": "Up to 50 Orders",
    "validity": 365,
    "type": "plan",
    "billed_type": "yearly",
    "custom_price": 0,
    "scope": "global",
    "sort_order": 1,
    "marketplace": "amazon",
    "source_marketplace": "shopify",
    "target_marketplace": "amazon",
    "discounts": [],
    "badge": "",
    "trial_days_limit": "",
    "trial_credit_limit": "",
    "features": [
      {
        "feature": "No Extra order credits",
        "description": "New orders will no longer be accepted after plan credits expire.",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
      },
      {
        "feature": "Bulk Upload (Upto 50 products)",
        "description": "In the free plan, you can upload up to 50 products at a time.",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/unlimitedListing.png"
      },
      {
        "feature": "24/7 Email Support",
        "description": "",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/email&chat.png"
      },
      {
        "feature": "Connect multiple Amazon accounts globally",
        "description": "Connect multiple Global Amazon accounts excluding Egypt, Saudi Arabia and Turkey.",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/multipleAcc.png"
      },
      {
        "feature": "Unlimited Listings",
        "description": "",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/unlimitedListing.png"
      },
      {
        "feature": "Refund Order Syncing",
        "description": "Sync refund orders from Shopify to Amazon",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
      },
      {
        "feature": "Cancelled Order Syncing",
        "description": "Sync refund orders from Shopify to Amazon",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
      },
      {
        "feature": "Inventory Control",
        "description": "Control or customize your inventory as per your need.",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/inventory.png"
      },
      {
        "feature": "Currency Control",
        "description": "Manage your currency if selling in different regions",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/currency.png"
      },
      {
        "feature": "Link Existing Listings",
        "description": "Link Shopify products with existing Amazon listings without hassle.",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
      }
    ],
    "services_groups": [
      {
        "title": "Order Management",
        "description": "Order Management",
        "services": [
          {
            "title": "Order Sync",
            "code": "upto_50_orders",
            "type": "order_sync",
            "required": 1,
            "service_charge": "0",
            "expiring_at": "",
            "discounts": [],
            "sort_order": 1,
            "trial_days_limit": "",
            "trial_credit_limit": "",
            "prepaid": {
              "service_credits": 50,
              "validity_changes": "Add",
              "fixed_price": 0,
              "reset_credit_after": 0,
              "expiring_at": "0"
            },
            "postpaid": {
              "per_unit_usage_price": "3",
              "unit_qty": 10,
              "capped_credit": 10,
              "validity_changes": "Replace"
            }
          }
        ]
      }
    ],
    "plan_id": "9",
    "category": "regular",
    "offered_price": 0//this is added with plan to present what is the price on which client is purchasing the plan
  },
  "plan_type": "plan",//the type of plan like plan, settlement, add_on etc.
  "app_tag": "amazon_sales_channel",
  "source_id": "164",
  "test_user": true
}
```

</details>

---

> 2. Active Plan

<b>Description: </b> schema to define active_plan in plan

<details><summary>Active Plan Schema</summary>

```json
   {
  "_id": "1507",
  "user_id": "6531414651e4bdebdf04932c",
  "type": "active_plan",//doc type - active_plan represents the client's active plan's info
  "marketplace": "amazon",
  "source_marketplace": "shopify",
  "target_marketplace": "amazon",
  "status": "active",//values can be active, inactive - defines the status of plan
  "capped_amount": 0.01,//it is the minimun amount we keep with the plan
  "created_at": "2023-11-15 11:44:20",
  "updated_at": "2023-11-15 11:44:20",
  "plan_details": {//plan details
    "_id": {
      "$oid": "6530e43e4f7e3db9e77dde84"
    },
    "code": "beginner",
    "title": "Beginner",
    "description": "Up to 100 Orders",
    "validity": 30,
    "type": "plan",
    "billed_type": "monthly",
    "custom_price": 15,
    "scope": "global",
    "sort_order": 2,
    "marketplace": "amazon",
    "source_marketplace": "shopify",
    "target_marketplace": "amazon",
    "discounts": [],
    "badge": "",
    "features": [
      {
        "feature": "Manage 2x order credits",
        "description": "Continue receiving new orders after exhausting credits. $3 charge for every 10 orders synced beyond plan limit.",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
      },
      {
        "feature": "Unlimited Bulk Upload",
        "description": "In the free plan, you can only upload up to 50 products at a time; however, in this plan, you can upload as many products as you need.",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/unlimitedListing.png"
      },
      {
        "feature": "24/7 Email & Chat Support",
        "description": "",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/email&chat.png"
      },
      {
        "feature": "Connect multiple Amazon accounts globally",
        "description": "Connect multiple Global Amazon accounts excluding Egypt, Saudi Arabia and Turkey.",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/multipleAcc.png"
      },
      {
        "feature": "Inventory Control",
        "description": "Control or customize your inventory as per your need.",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/inventory.png"
      },
      {
        "feature": "Link Existing Listings",
        "description": "Link Shopify products with existing Amazon listings without hassle.",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
      },
      {
        "feature": "Currency Control",
        "description": "Manage your currency if selling in different regions",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/currency.png"
      },
      {
        "feature": "Unlimited Listings",
        "description": "",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/unlimitedListing.png"
      },
      {
        "feature": "Refund Order Syncing",
        "description": "Sync refund orders from Shopify to Amazon",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
      },
      {
        "feature": "Cancelled Order Syncing",
        "description": "Sync refund orders from Shopify to Amazon",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
      },
      {
        "feature": "Dedicated Account Manager",
        "description": "",
        "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/email&chat.png"
      }
    ],
    "services_groups": [
      {
        "title": "Order Management",
        "description": "Order Management",
        "services": [
          {
            "title": "Order Sync",
            "code": "upto_100_orders",
            "type": "order_sync",
            "required": 1,
            "service_charge": "0",
            "expiring_at": "",
            "discounts": [],
            "sort_order": 1,
            "trial_days_limit": "",
            "trial_credit_limit": "",
            "prepaid": {
              "service_credits": 100,
              "validity_changes": "Add",
              "fixed_price": 0,
              "reset_credit_after": 0,
              "expiring_at": "0"
            },
            "postpaid": {
              "per_unit_usage_price": "3",
              "unit_qty": 10,
              "capped_credit": 100,
              "validity_changes": "Replace"
            }
          }
        ]
      }
    ],
    "plan_id": "2",
    "category": "regular",
    "offered_price": 15
  },
  "source_id": "164",
  "app_tag": "amazon_sales_channel",
  "test_user": true
}
```

</details>

---

> 3. Payment

<b>Description: </b> schema to define payment doc in plan

<details><summary>Payment Schema</summary>

```json
   {
  "_id": "1506",
  "type": "payment",//represents the payment doc for any quote
  "user_id": "6531414651e4bdebdf04932c",
  "status": "approved",//status can be pending, cancelled, approved, refund
  "plan_id": "2",
  "quote_id": "1505",//it identifies the quote for which payment has been done
  "source_marketplace": "shopify",
  "target_marketplace": "amazon",
  "method": "shopify",// represents the payment method
  "marketplace_data": {
    //contains the payment data recieved from marketplace
  },
  "created_at": "2023-11-15 11:44:20",
  "updated_at": "2023-11-15 11:44:20",
  "source_id": "164",
  "app_tag": "amazon_sales_channel",
  "test_user": true,//this key exist in the docs to represent that this transaction is of a test user
  "plan_status": "active"//this key only exist for those payments which are related to plan for representing the active_plan's payment
}
```

</details>

---

> 4. User service

<b>Description: </b> schema to define user_service doc in plan

<details><summary>User Service Schema</summary>

```json
{
  "_id": "1501",
  "type": "user_service",//doc type user_service represents the services for the plan offered - for each service it is only the one
  "service_type": "order_sync",// the type of service
  "marketplace": "amazon",
  "source_marketplace": "shopify",
  "target_marketplace": "amazon",
  "user_id": "6531414651e4bdebdf04932c",
  "created_at": "2023-11-15 11:09:36",
  "updated_at": "2023-11-15 11:44:20",
  "activated_on": "2023-11-15",//date on which it is actually activated
  "expired_at": "2023-11-30",//represents the expiry date when the plan will be renewed
  "prepaid": {//represents the prepaid credits and their usage
    "service_credits": 100,
    "available_credits": 25,
    "total_used_credits": 75
  },
  "postpaid": {//represents the postpaid credits and their usage
    "per_unit_usage_price": 3,
    "unit_qty": 10,
    "capped_credit": 100,
    "available_credits": 100,
    "total_used_credits": 0
  },
  "source_id": "164",
  "app_tag": "amazon_sales_channel",
  "test_user": true
}
```

</details>

> 5. Settlement Invoice

<b>Description: </b> schema to define settlement_invoice doc in plan

<details><summary>Settlement Invoice Schema</summary>

```json
{
  "_id": "1508",
  "type": "settlement_invoice",//represents the invoice for settlement
  "user_id": "6531414651e4bdebdf04932c",
  "status": "pending",//status can be pending, approved, waived_off etc.
  "settlement_amount": 3,//the amount that client needs to pay
  "credits_used": 1,//the additional credits used
  "source_marketplace": "shopify",
  "target_marketplace": "amazon",
  "method": "shopify",//payment method
  "created_at": "2023-11-15 12:32:23",
  "updated_at": "2023-11-15 12:32:23",
  "generated_at": "2023-11-15",//date on which invoice is generated
  "last_payment_date": "2023-11-30",//the last date of payment
  "prepaid": {
    "service_credits": 100,
    "available_credits": 0,
    "total_used_credits": 100
  },
  "postpaid": {
    "per_unit_usage_price": 3,
    "unit_qty": 10,
    "capped_credit": 100,
    "available_credits": 99,
    "total_used_credits": 1
  },
  "quote_id": "1509",//the quote id representing the invoice
  "test_user": true
}
```

</details>

> 6. Monthly Usage

<b>Description: </b> schema to define monthly_usage doc in plan

<details><summary>Monthly Usage Schema</summary>

```json
{
  "_id": {
    "$oid": "6541cac955290320d4f55b86"
  },
  "marketplace": "amazon",
  "month": 10,
  "source_marketplace": "shopify",
  "target_marketplace": "amazon",
  "type": "monthly_usage",
  "user_id": "6539f81651e4bdebdf049572",
  "year": 2023,
  "created_at": "2023-11-01 03:49:29",
  "plan": {
    "capped_amount": 0.01,
    "details": {
      "_id": {
        "$oid": "6530e43e4f7e3db9e77dde82"
      },
      "code": "free",
      "title": "Free",
      "description": "Up to 50 Orders",
      "validity": 30,
      "type": "plan",
      "billed_type": "monthly",
      "custom_price": 0,
      "scope": "global",
      "sort_order": 1,
      "marketplace": "amazon",
      "source_marketplace": "shopify",
      "target_marketplace": "amazon",
      "discounts": [],
      "badge": "",
      "trial_days_limit": "",
      "trial_credit_limit": "",
      "features": [
        {
          "feature": "No Extra order credits",
          "description": "New orders will no longer be accepted after plan credits expire.",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
        },
        {
          "feature": "Bulk Upload (Upto 50 products)",
          "description": "In the free plan, you can upload up to 50 products at a time.",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/unlimitedListing.png"
        },
        {
          "feature": "24/7 Email Support",
          "description": "",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/email&chat.png"
        },
        {
          "feature": "Connect multiple Amazon accounts globally",
          "description": "Connect multiple Global Amazon accounts excluding Egypt, Saudi Arabia and Turkey.",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/multipleAcc.png"
        },
        {
          "feature": "Unlimited Listings",
          "description": "",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/unlimitedListing.png"
        },
        {
          "feature": "Refund Order Syncing",
          "description": "Sync refund orders from Shopify to Amazon",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
        },
        {
          "feature": "Cancelled Order Syncing",
          "description": "Sync refund orders from Shopify to Amazon",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
        },
        {
          "feature": "Inventory Control",
          "description": "Control or customize your inventory as per your need.",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/inventory.png"
        },
        {
          "feature": "Currency Control",
          "description": "Manage your currency if selling in different regions",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/currency.png"
        },
        {
          "feature": "Link Existing Listings",
          "description": "Link Shopify products with existing Amazon listings without hassle.",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
        }
      ],
      "services_groups": [
        {
          "title": "Order Management",
          "description": "Order Management",
          "services": [
            {
              "title": "Order Sync",
              "code": "upto_50_orders",
              "type": "order_sync",
              "required": 1,
              "service_charge": "0",
              "expiring_at": "",
              "discounts": [],
              "sort_order": 1,
              "trial_days_limit": "",
              "trial_credit_limit": "",
              "prepaid": {
                "service_credits": 50,
                "validity_changes": "Add",
                "fixed_price": 0,
                "reset_credit_after": 0,
                "expiring_at": "0"
              },
              "postpaid": {
                "per_unit_usage_price": "3",
                "unit_qty": 10,
                "capped_credit": 10,
                "validity_changes": "Replace"
              }
            }
          ]
        }
      ],
      "plan_id": "1",
      "category": "regular",
      "offered_price": 0
    }
  },
  "used_services": [
    {
      "service_type": "order_sync",
      "prepaid": {
        "service_credits": 50,
        "available_credits": 50,
        "total_used_credits": 0
      },
      "postpaid": {
        "per_unit_usage_price": 3,
        "unit_qty": 10,
        "capped_credit": 10,
        "available_credits": 10,
        "total_used_credits": 0
      }
    }
  ]
}
```

</details>

---

## Transaction


<b>Description: </b> this is the overall history of the transaction history made by clients in a month, it is a complete record for the clients to see their transaction history records in the future

<details><summary>Transaction Schema</summary>

```json
{
  "_id": {
    "$oid": "6541df5055290320d479449a"
  },
  "month": "11",
  "user_id": "6513a495b65c1a7eac06a7d2",
  "year": "2023",
  "active_plan_details": {
    "_id": "1497",
    "user_id": "6513a495b65c1a7eac06a7d2",
    "type": "active_plan",
    "marketplace": "amazon",
    "source_marketplace": "shopify",
    "target_marketplace": "amazon",
    "status": "active",
    "capped_amount": 0.01,
    "created_at": "2023-11-15 10:19:26",
    "updated_at": "2023-11-15 10:19:26",
    "plan_details": {
      "_id": {
        "$oid": "6530e43e4f7e3db9e77dde84"
      },
      "code": "beginner",
      "title": "custom-beginner-monthly",
      "description": "Up to 220 orders",
      "validity": 10,
      "type": "plan",
      "billed_type": "",
      "custom_price": 20,
      "scope": "global",
      "sort_order": 2,
      "marketplace": "amazon",
      "source_marketplace": "shopify",
      "target_marketplace": "amazon",
      "discounts": [],
      "badge": "",
      "features": [
        {
          "feature": "Manage 2x order credits",
          "description": "Continue receiving new orders after exhausting credits. $3 charge for every 10 orders synced beyond plan limit.",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
        },
        {
          "feature": "Unlimited Bulk Upload",
          "description": "In the free plan, you can only upload up to 50 products at a time; however, in this plan, you can upload as many products as you need.",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/unlimitedListing.png"
        },
        {
          "feature": "24/7 Email & Chat Support",
          "description": "",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/email&chat.png"
        },
        {
          "feature": "Connect multiple Amazon accounts globally",
          "description": "Connect multiple Global Amazon accounts excluding Egypt, Saudi Arabia and Turkey.",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/multipleAcc.png"
        },
        {
          "feature": "Inventory Control",
          "description": "Control or customize your inventory as per your need.",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/inventory.png"
        },
        {
          "feature": "Link Existing Listings",
          "description": "Link Shopify products with existing Amazon listings without hassle.",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
        },
        {
          "feature": "Currency Control",
          "description": "Manage your currency if selling in different regions",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/currency.png"
        },
        {
          "feature": "Unlimited Listings",
          "description": "",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/unlimitedListing.png"
        },
        {
          "feature": "Refund Order Syncing",
          "description": "Sync refund orders from Shopify to Amazon",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
        },
        {
          "feature": "Cancelled Order Syncing",
          "description": "Sync refund orders from Shopify to Amazon",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/setting.png"
        },
        {
          "feature": "Dedicated Account Manager",
          "description": "",
          "image": "https://amazon-mail-images.s3.ap-northeast-3.amazonaws.com/Images/email&chat.png"
        }
      ],
      "services_groups": [
        {
          "title": "Order Management",
          "description": "Order Management",
          "services": [
            {
              "title": "Order Sync",
              "code": "upto_100_orders",
              "type": "order_sync",
              "required": 1,
              "service_charge": "0",
              "expiring_at": "",
              "discounts": [],
              "sort_order": 1,
              "trial_days_limit": "",
              "trial_credit_limit": "",
              "prepaid": {
                "service_credits": "220",
                "validity_changes": "Replace",
                "fixed_price": 0,
                "reset_credit_after": 0,
                "expiring_at": "0"
              },
              "postpaid": {
                "per_unit_usage_price": "3",
                "unit_qty": 10,
                "capped_credit": "220",
                "validity_changes": "Replace"
              }
            }
          ]
        }
      ],
      "plan_id": "custom-27th-september-staging-beginner-20",
      "category": "regular",
      "payment_type": "onetime",
      "custom_plan": true,
      "offered_price": 20
    },
    "deactivate_on": "2023-11-25",
    "source_id": "150",
    "app_tag": "amazon_sales_channel",
    "test_user": true
  },
  "active_settlement": [],
  "plan_status": "active",
  "settlement_history": [
    {
      "invoice_id": "1378",
      "credits_used": 10,
      "settlement_amount": 3,
      "quote_id": "1379",
      "service_credits_info": {
        "prepaid": {
          "service_credits": 2500,
          "available_credits": 0,
          "total_used_credits": 2500
        },
        "postpaid": {
          "per_unit_usage_price": 3,
          "unit_qty": 10,
          "capped_credit": 10,
          "available_credits": 0,
          "total_used_credits": 10
        }
      },
      "status": "approved",
      "generated_on": "2023-11-01 05:17:04",
      "payment_details": {
        "id": "1381",
        "charge_id": {
          "$numberLong": "3100148010"
        },
        "price": "3.00",
        "paid_on": "2023-11-01T05:18:58+00:00",
        "status": "approved"
      }
    },
    {
      "invoice_id": "1382",
      "credits_used": 10,
      "settlement_amount": 3,
      "quote_id": "1383",
      "service_credits_info": {
        "prepaid": {
          "service_credits": 2500,
          "available_credits": 0,
          "total_used_credits": 2500
        },
        "postpaid": {
          "per_unit_usage_price": 3,
          "unit_qty": 10,
          "capped_credit": 10,
          "available_credits": 0,
          "total_used_credits": 10
        }
      },
      "status": "approved",
      "generated_on": "2023-11-01 05:21:10",
      "payment_details": {
        "id": "1384",
        "charge_id": {
          "$numberLong": "3100180778"
        },
        "price": "3.00",
        "paid_on": "2023-11-01T05:25:25+00:00",
        "status": "approved"
      }
    }
  ],
  "sync_activated": true,
  "type": "payment_transaction",
  "updated_at": "2023-11-15T10:19:33+00:00",
  "user_status": "active",
  "last_access_revoke_date": "2023-11-01T05:24:23+00:00",
  "last_access_activate_date": "2023-11-15T10:19:33+00:00",
  "app_tag": "amazon_sales_channel",
  "created_at": "2023-11-15T10:19:26+00:00",
  "plan_history": [
    {
      "active_plan_id": "1337",
      "plan_details": {
        "title": "Growth",
        "plan_id": "13",
        "billed_type": "yearly",
        "custom_price": 2268,
        "services_groups": [
          {
            "title": "Order Management",
            "description": "Order Management",
            "services": [
              {
                "title": "Order Sync",
                "code": "upto_2500_orders",
                "type": "order_sync",
                "required": 1,
                "service_charge": "0",
                "expiring_at": "",
                "discounts": [],
                "sort_order": 1,
                "trial_days_limit": "",
                "trial_credit_limit": "",
                "prepaid": {
                  "service_credits": 2500,
                  "validity_changes": "Add",
                  "fixed_price": 0,
                  "reset_credit_after": 0,
                  "expiring_at": "0"
                },
                "postpaid": {
                  "per_unit_usage_price": "3",
                  "unit_qty": 10,
                  "capped_credit": 2500,
                  "validity_changes": "Replace"
                }
              }
            ]
          }
        ]
      },
      "status": "inactive",
      "activated_on": "2023-10-20 12:54:56",
      "deactivated_on": "2023-11-01T05:25:55+00:00"
    },
    {
      "active_plan_id": "1337",
      "plan_details": {
        "title": "Growth",
        "plan_id": "13",
        "billed_type": "yearly",
        "custom_price": 2268,
        "services_groups": [
          {
            "title": "Order Management",
            "description": "Order Management",
            "services": [
              {
                "title": "Order Sync",
                "code": "upto_2500_orders",
                "type": "order_sync",
                "required": 1,
                "service_charge": "0",
                "expiring_at": "",
                "discounts": [],
                "sort_order": 1,
                "trial_days_limit": "",
                "trial_credit_limit": "",
                "prepaid": {
                  "service_credits": 2500,
                  "validity_changes": "Add",
                  "fixed_price": 0,
                  "reset_credit_after": 0,
                  "expiring_at": "0"
                },
                "postpaid": {
                  "per_unit_usage_price": "3",
                  "unit_qty": 10,
                  "capped_credit": 2500,
                  "validity_changes": "Replace"
                }
              }
            ]
          }
        ]
      },
      "status": "inactive",
      "activated_on": "2023-10-20 12:54:56",
      "deactivated_on": "2023-11-01T05:25:55+00:00"
    },
    {
      "active_plan_id": "1337",
      "plan_details": {
        "title": "Growth",
        "plan_id": "13",
        "billed_type": "yearly",
        "custom_price": 2268,
        "services_groups": [
          {
            "title": "Order Management",
            "description": "Order Management",
            "services": [
              {
                "title": "Order Sync",
                "code": "upto_2500_orders",
                "type": "order_sync",
                "required": 1,
                "service_charge": "0",
                "expiring_at": "",
                "discounts": [],
                "sort_order": 1,
                "trial_days_limit": "",
                "trial_credit_limit": "",
                "prepaid": {
                  "service_credits": 2500,
                  "validity_changes": "Add",
                  "fixed_price": 0,
                  "reset_credit_after": 0,
                  "expiring_at": "0"
                },
                "postpaid": {
                  "per_unit_usage_price": "3",
                  "unit_qty": 10,
                  "capped_credit": 2500,
                  "validity_changes": "Replace"
                }
              }
            ]
          }
        ]
      },
      "status": "inactive",
      "activated_on": "2023-10-20 12:54:56",
      "deactivated_on": "2023-11-01T05:25:55+00:00"
    }
  ]
}
```

</details>
