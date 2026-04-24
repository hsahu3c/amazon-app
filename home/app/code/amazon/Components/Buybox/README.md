# Amazon Buybox Status Batch Process

This module provides a batch processing system to call Amazon's `listing-offers-batch` API for all items in the `amazon_listing` collection to identify their buybox status.

## Overview

The batch process:
1. Fetches all active listings from the `amazon_listing` collection for a given shop
2. Processes them in batches of 40 SKUs (Amazon API limit)
3. Calls the `listing-offers-batch` API for each batch
4. Uses `App\Amazon\Components\Buybox\ListingOffersBatch::calculateBuyBoxStatus()` to determine buybox status
5. Stores results in the `amazon_listing_offers` collection
6. Tracks progress in the `queued_tasks` collection

## Components

### 1. ListingOffersBatch Service
**File:** `app/code/amazon/Components/Buybox/ListingOffersBatch.php`

Main service class that handles:
- Batch process initiation
- SQS queue management
- API calls to Amazon
- Progress tracking
- Result processing

### 2. CLI Command
**File:** `app/code/amazon/console/BuyboxBatchCommand.php`

Command to initiate batch process from command line:
```bash
php app/cli amazon:buybox:batch <user_id> <shop_id> [options]
```

### 3. Controller Endpoint
**File:** `app/code/amazon/controllers/BuyboxController.php`

REST API endpoint to initiate batch process:
```
POST /amazon/buybox/initiate-batch-process
```

## Usage

### Via CLI

1. **Initiate batch process:**
   ```bash
   php app/cli amazon:buybox:batch -u 689c6a5ec787a3b5660c77e3 -s 817 -a amazon_sales_channel
   ```

2. **With automatic process initiation:**
   ```bash
   php app/cli amazon:buybox:batch -u 689c6a5ec787a3b5660c77e3 -s 817  -a amazon_sales_channel -p automatic
   ```

### Via API

```bash
curl -X POST "https://your-domain.com/amazon/buybox/initiate-batch-process" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "shop_id": "270950",
    "process_initiation": "manual"
  }'
```

## Queued Task Schema

The batch process creates entries in the `queued_tasks` collection with the following schema:

```json
{
  "_id": {"$oid": "68b13e269f7dfcf1e70bdaf3"},
  "user_id": "66fbeac2a7bf4b1c5e0fc7b3",
  "shop_id": "270950",
  "marketplace": "amazon",
  "appTag": "amazon_sales_channel",
  "process_code": "amazon_buybox_status_sync",
  "progress": {"$numberDouble": "10.0"},
  "created_at": "2025-08-29T05:44:06+00:00",
  "additional_data": {
    "total_items": 1500,
    "total_batches": 38,
    "batch_size": 40,
    "seller_id": "A1234567890",
    "processed_batches": 3,
    "processed_items": 120
  },
  "process_initiation": "manual",
  "message": "Processed batch 3 of 38",
  "tag": "My Amazon Shop",
  "updated_at": "2025-08-29 06:41:56"
}
```

## Progress Tracking

The `progress` field contains the current progress percentage:
- `0.0` = Process started
- `10.0` = 10% complete
- `100.0` = Process completed

The `additional_data` field contains detailed progress information:
- `total_items`: Total number of listings to process
- `total_batches`: Total number of batches needed
- `batch_size`: Number of SKUs per batch (40)
- `seller_id`: Amazon seller ID for the shop
- `processed_batches`: Number of batches completed
- `processed_items`: Number of items processed

## SQS Queue Configuration

The batch process uses AWS SQS with the following configuration:
- **Queue Name:** `amazon_buybox_bulk_status_sync`
- **Worker Class:** `App\Amazon\Components\Buybox\ListingOffersBatch`
- **Method:** `processBatch`
- **Delay Between Batches:** 30 seconds (to avoid rate limiting)

## API Integration

The service integrates with Amazon's `getListingOffersBatch` (`listing-offers-batch` API of Remote Server) API through the existing `App\Amazon\Components\Common\Helper::sendRequestToAmazon()` method.

### API Request Format
```php
$requestData = [
    'shop_id' => $shop['remote_shop_id'],
    'skus' => $skus // Array of up to 40 SKUs
];
```

### API Response Processing
The service processes the API response and uses `App\Amazon\Components\Buybox\Buybox::calculateBuyBoxStatus()` to determine if the seller wins the buybox for each SKU.

## Result Storage

Results are stored in the `amazon_pricing_health` collection with the following structure:

# Sample#1
```json
{
  "_id": {
    "$oid": "69a02d5337612b5b60f54214"
  },
  "shop_id": "883",
  "sku": "12370",
  "user_id": "691469c9112c21f8170693aa",
  "asin": "B086RJK27C",
  "buybox": "loss", // or "win"
  "created_at": "2026-02-26T11:24:03+00:00",
  "fulfillment_channel": "DEFAULT",
  "image": "https://m.media-amazon.com/images/I/51MESrdm3XS._SL75_.jpg",
  "item_condition": "11",
  "listing_id": "1120F7QBS1E",
  "marketplace_id": "A1F83G8C2ARO7P",
  "offer_status": "NoBuyableOffers",
  "offers": [],
  "price": "84.99",
  "seller_id": "A28BTR28JQXR3S",
  "summary": {
    "TotalOfferCount": 0
  },
  "title": "VEVOR Moonshine Still Distiller Stainless Steel Water Distiller Copper Tube with Circulating Pump Home Brewing Kit Build-in Thermometer for DIY Whisky Wine Brandy Spirits (3GAL/12L)",
  "updated_at": "2026-02-26T11:24:03+00:00"
}
```

# Sample#2
```json
{
  "_id": {
    "$oid": "698999deae49297d13f20eb6"
  },
  "user_id": "615ab9edbb19c142033e8a91",
  "sku": "180BLADEST",
  "shop_id": "229380",
  "asin": "B0006GJMD4",
  "buybox": "loss",
  "created_at": "2026-02-09T08:25:02+00:00",
  "fulfillment_channel": "DEFAULT",
  "image": "https://m.media-amazon.com/images/I/41xfHY-sNTL._SL75_.jpg",
  "item_condition": "11",
  "listing_id": "1014YCNS5D7",
  "marketplace_id": "ATVPDKIKX0DER",
  "offer_status": "Success",
  "offers": [
    {
      "ShippingTime": {
        "minimumHours": 0,
        "maximumHours": 0,
        "availabilityType": "NOW"
      },
      "MyOffer": true,
      "IsFulfilledByAmazon": false,
      "ListingPrice": {
        "CurrencyCode": "USD",
        "Amount": 38
      },
      "IsBuyBoxWinner": false,
      "SellerId": "A6Y0R5B75S7E0",
      "Shipping": {
        "CurrencyCode": "USD",
        "Amount": 0
      },
      "ShipsFrom": {
        "Country": "US",
        "State": "IA"
      },
      "SubCondition": "new",
      "IsFeaturedMerchant": true,
      "SellerFeedbackRating": {
        "FeedbackCount": 205,
        "SellerPositiveFeedbackRating": 93
      },
      "PrimeInformation": {
        "IsPrime": false,
        "IsNationalPrime": false
      }
    },
    {
      "ShippingTime": {
        "minimumHours": 0,
        "maximumHours": 0,
        "availabilityType": "NOW"
      },
      "MyOffer": false,
      "IsFulfilledByAmazon": true,
      "ListingPrice": {
        "CurrencyCode": "USD",
        "Amount": 38
      },
      "IsBuyBoxWinner": true,
      "SellerId": "A2R2RITDJNW1Q6",
      "Shipping": {
        "CurrencyCode": "USD",
        "Amount": 0
      },
      "SubCondition": "new",
      "IsFeaturedMerchant": true,
      "SellerFeedbackRating": {
        "FeedbackCount": 384,
        "SellerPositiveFeedbackRating": 91
      },
      "PrimeInformation": {
        "IsPrime": true,
        "IsNationalPrime": true
      }
    },
    {
      "ShippingTime": {
        "minimumHours": 24,
        "maximumHours": 24,
        "availabilityType": "NOW"
      },
      "MyOffer": false,
      "IsFulfilledByAmazon": false,
      "ListingPrice": {
        "CurrencyCode": "USD",
        "Amount": 35
      },
      "IsBuyBoxWinner": false,
      "SellerId": "A1BTXUW4EX22JM",
      "Shipping": {
        "CurrencyCode": "USD",
        "Amount": 6.3
      },
      "ShipsFrom": {
        "Country": "US",
        "State": "TX"
      },
      "SubCondition": "new",
      "IsFeaturedMerchant": true,
      "SellerFeedbackRating": {
        "FeedbackCount": 21,
        "SellerPositiveFeedbackRating": 81
      },
      "PrimeInformation": {
        "IsPrime": false,
        "IsNationalPrime": false
      }
    },
    {
      "ShippingTime": {
        "minimumHours": 24,
        "maximumHours": 24,
        "availabilityType": "NOW"
      },
      "MyOffer": false,
      "IsFulfilledByAmazon": false,
      "ListingPrice": {
        "CurrencyCode": "USD",
        "Amount": 54.99
      },
      "IsBuyBoxWinner": false,
      "SellerId": "A1F0I4W1SSL1G2",
      "Shipping": {
        "CurrencyCode": "USD",
        "Amount": 0
      },
      "ShipsFrom": {
        "Country": "US",
        "State": "AZ"
      },
      "SubCondition": "new",
      "IsFeaturedMerchant": true,
      "SellerFeedbackRating": {
        "FeedbackCount": 45,
        "SellerPositiveFeedbackRating": 90
      },
      "PrimeInformation": {
        "IsPrime": false,
        "IsNationalPrime": false
      }
    },
    {
      "ShippingTime": {
        "minimumHours": 24,
        "maximumHours": 48,
        "availabilityType": "NOW"
      },
      "MyOffer": false,
      "IsFulfilledByAmazon": false,
      "ListingPrice": {
        "CurrencyCode": "USD",
        "Amount": 57.64
      },
      "IsBuyBoxWinner": false,
      "SellerId": "A2KTDJV6EUITJE",
      "Shipping": {
        "CurrencyCode": "USD",
        "Amount": 0
      },
      "ShipsFrom": {
        "Country": "US",
        "State": "IL"
      },
      "SubCondition": "new",
      "IsFeaturedMerchant": true,
      "SellerFeedbackRating": {
        "FeedbackCount": 5470,
        "SellerPositiveFeedbackRating": 60
      },
      "PrimeInformation": {
        "IsPrime": false,
        "IsNationalPrime": false
      }
    },
    {
      "ShippingTime": {
        "minimumHours": 24,
        "maximumHours": 48,
        "availabilityType": "NOW"
      },
      "MyOffer": false,
      "IsFulfilledByAmazon": false,
      "ListingPrice": {
        "CurrencyCode": "USD",
        "Amount": 57.65
      },
      "IsBuyBoxWinner": false,
      "SellerId": "ARCAN5FZQ0C99",
      "Shipping": {
        "CurrencyCode": "USD",
        "Amount": 0
      },
      "ShipsFrom": {
        "Country": "US",
        "State": "GA"
      },
      "SubCondition": "new",
      "IsFeaturedMerchant": true,
      "SellerFeedbackRating": {
        "FeedbackCount": 1322,
        "SellerPositiveFeedbackRating": 66
      },
      "PrimeInformation": {
        "IsPrime": false,
        "IsNationalPrime": false
      }
    },
    {
      "ShippingTime": {
        "minimumHours": 24,
        "maximumHours": 48,
        "availabilityType": "NOW"
      },
      "MyOffer": false,
      "IsFulfilledByAmazon": false,
      "ListingPrice": {
        "CurrencyCode": "USD",
        "Amount": 56.19
      },
      "IsBuyBoxWinner": false,
      "SellerId": "A2Z5T0WALCU6KF",
      "Shipping": {
        "CurrencyCode": "USD",
        "Amount": 2.94
      },
      "ShipsFrom": {
        "Country": "US",
        "State": "NY"
      },
      "SubCondition": "new",
      "IsFeaturedMerchant": true,
      "SellerFeedbackRating": {
        "FeedbackCount": 554,
        "SellerPositiveFeedbackRating": 68
      },
      "PrimeInformation": {
        "IsPrime": false,
        "IsNationalPrime": false
      }
    }
  ],
  "price": "38",
  "seller_id": "A6Y0R5B75S7E0",
  "summary": {
    "BuyBoxEligibleOffers": [
      {
        "condition": "new",
        "fulfillmentChannel": "Amazon"
      },
      {
        "condition": "new",
        "fulfillmentChannel": "Merchant"
      }
    ],
    "LowestPrices": [
      {
        "condition": "new",
        "fulfillmentChannel": "Amazon",
        "LandedPrice": {
          "CurrencyCode": "USD",
          "Amount": 38
        },
        "Shipping": {
          "CurrencyCode": "USD",
          "Amount": 0
        },
        "ListingPrice": {
          "CurrencyCode": "USD",
          "Amount": 38
        }
      },
      {
        "condition": "new",
        "fulfillmentChannel": "Merchant",
        "LandedPrice": {
          "CurrencyCode": "USD",
          "Amount": 38
        },
        "Shipping": {
          "CurrencyCode": "USD",
          "Amount": 0
        },
        "ListingPrice": {
          "CurrencyCode": "USD",
          "Amount": 38
        }
      }
    ],
    "BuyBoxPrices": [
      {
        "condition": "New",
        "LandedPrice": {
          "CurrencyCode": "USD",
          "Amount": 38
        },
        "Shipping": {
          "CurrencyCode": "USD",
          "Amount": 0
        },
        "ListingPrice": {
          "CurrencyCode": "USD",
          "Amount": 38
        }
      }
    ],
    "CompetitivePriceThreshold": {
      "CurrencyCode": "USD",
      "Amount": 44.49
    },
    "NumberOfOffers": [
      {
        "condition": "new",
        "fulfillmentChannel": "Amazon",
        "OfferCount": 1
      },
      {
        "condition": "new",
        "fulfillmentChannel": "Merchant",
        "OfferCount": 6
      }
    ],
    "ListPrice": {
      "CurrencyCode": "USD",
      "Amount": 38
    },
    "TotalOfferCount": 7,
    "SalesRankings": [
      {
        "Rank": 3502,
        "ProductCategoryId": "home_improvement_display_on_website"
      },
      {
        "Rank": 2,
        "ProductCategoryId": "552292"
      }
    ]
  },
  "title": "Evolution Power Tools 180BLADEST Steel Cutting Saw Blade, 7-Inch x 36-Tooth, Silver",
  "updated_at": "2026-02-09T08:25:02+00:00"
}
```

## Error Handling

The batch process includes comprehensive error handling:
- API call failures are logged but don't stop the entire process
- Individual SKU processing errors are logged but don't affect other SKUs
- SQS queue failures are handled gracefully
- Progress updates continue even if some batches fail

## Monitoring

Monitor the batch process through:
1. **Queued Tasks:** Check progress in the `queued_tasks` collection
2. **Logs:** Check application logs for detailed error information
3. **SQS Console:** Monitor the SQS queue for message processing
4. **Results:** Check the `amazon_listing_offers` collection for processed results

## Rate Limiting

The batch process includes built-in rate limiting:
- 30-second delay between batches
- Respects Amazon API rate limits
- Handles API throttling gracefully

## Dependencies

- `App\Core\Models\BaseMongo` - MongoDB operations
- `App\Amazon\Components\Common\Helper` - Amazon API integration
- `App\Connector\Components\Profile\SQSWorker` - SQS queue management
- `App\Amazon\Components\Buybox\ListingOffersBatch` - Buybox status calculation
- `App\Core\Models\User\Details` - User and shop management
