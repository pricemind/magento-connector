# Pricemind Magento Module

A Magento 2 module that automatically tracks product price changes and synchronizes them with the Pricemind API for competitive price monitoring and analysis.

## Features

- **Real-time Price Tracking**: Automatically detects and sends product price changes to Pricemind
- **Special Price Support**: Tracks special prices and their date ranges
- **Custom Fields Integration**: Syncs special price start/end dates as custom fields
- **Multi-store Support**: Website-level configuration for different stores
- **Error Handling**: Failed API requests are logged and stored for retry
- **Non-blocking**: Uses short timeouts to avoid disrupting store operations
- **Secure Configuration**: API keys are encrypted in the database

## Requirements

- Magento 2.3+
- PHP 7.4+
- Active Pricemind account with API access

## Installation

### Via Composer (Recommended)

```bash
composer require pricemind/magento-connector
php bin/magento module:enable Stellion_Pricemind
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

### Manual Installation

1. Download the module files
2. Extract to `app/code/Stellion/Pricemind/`
3. Run the following commands:

```bash
php bin/magento module:enable Stellion_Pricemind
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

## Configuration

### Admin Configuration

1. Navigate to **Stores → Configuration → Pricemind → API**
2. Configure the following settings:

#### API Settings

- **Base URL**: Pricemind API endpoint (default: `https://api.pricemind.io/`)
- **API Access Key**: Your Pricemind API key in format `<key_id>.<version>.<secret>`
- **Channel**: Select your Pricemind channel (populated after saving API key)

#### Source Detection

The module automatically detects and displays:
- **Detected Source is Magento**: Shows if the channel source is identified as Magento
- **Source Type**: The detected source type
- **Source Title**: The source title from Pricemind

### Multi-store Configuration

The module supports website-level configuration:

1. Set **Configuration Scope** to your desired website
2. Configure API settings for each website independently
3. Each website can use different Pricemind channels

## How It Works

### Price Change Detection

The module listens for the `catalog_product_save_commit_after` event and detects changes in:

- **Regular Price** (`price`)
- **Special Price** (`special_price`)
- **Special Price From Date** (`special_from_date`)
- **Special Price To Date** (`special_to_date`)

### API Integration

When a price change is detected, the module:

1. **Sends Price Data** to `POST /v1/channels/{channelId}/prices`:
   ```json
   {
     "product_sku": "V92516",
     "price": "100.00",
     "currency": "USD",
     "special_price": "90.00",
     "includes_tax": true
   }
   ```

2. **Sends Special Price Dates** to `PUT /v1/custom-fields`:
   ```json
   {
     "channel_id": 123,
     "machine_name": "special_price_start_date",
     "product_sku": "V92516",
     "value": "2024-01-01"
   }
   ```

### Custom Fields Mapping

Special price date ranges are mapped to custom fields:

- `special_from_date` → `special_price_start_date`
- `special_to_date` → `special_price_end_date`

### Error Handling

Failed API requests are stored in the `stellion_pricemind_failed_request` table with:

- Request details (endpoint, method, headers, payload)
- Error message and status
- Retry count and next attempt timestamp
- Created/updated timestamps

## Database Schema

The module creates one table:

### `stellion_pricemind_failed_request`

Stores failed API requests for potential retry processing:

| Column | Type | Description |
|--------|------|-------------|
| `entity_id` | INT | Primary key |
| `endpoint` | VARCHAR(255) | Target API endpoint |
| `method` | VARCHAR(16) | HTTP method |
| `headers` | TEXT | Request headers (JSON) |
| `payload` | TEXT | Request payload (JSON) |
| `error` | TEXT | Last error message |
| `retry_count` | INT | Number of retry attempts |
| `status` | SMALLINT | Status (0=pending, 1=retrying, 2=abandoned, 3=sent) |
| `next_attempt_at` | TIMESTAMP | Next retry timestamp |
| `created_at` | TIMESTAMP | Created timestamp |
| `updated_at` | TIMESTAMP | Updated timestamp |

## API Endpoints Used

The module integrates with these Pricemind API endpoints:

- `GET /v1/channels` - Fetch available channels
- `GET /v1/channels/{id}/sources/active` - Get active channel source
- `GET /v1/channels/{id}/product-domain?sku={sku}` - Lookup product domain
- `POST /v1/channels/{id}/prices` - Create/update price
- `PUT /v1/custom-fields` - Update custom field values

## Security

- **API Key Encryption**: API keys are encrypted using Magento's encryption service
- **Masked Logging**: API keys are masked in error logs as `***`
- **Short Timeouts**: 1-2 second timeouts prevent long-running requests
- **ACL Permissions**: Admin configuration requires `Stellion_Pricemind::config` permission

## Performance Considerations

- **Non-blocking**: Short timeouts (1s connect, 2s total) prevent blocking store operations
- **Async Processing**: Price updates are sent asynchronously during product saves
- **Error Tolerance**: Failed requests don't disrupt normal store functionality
- **Selective Updates**: Only sends data when prices actually change

## Troubleshooting

### Common Issues

1. **No price updates sent**:
   - Verify API key is configured and valid
   - Check channel ID is selected
   - Ensure product prices are actually changing

2. **API connection errors**:
   - Verify Base URL is correct
   - Check firewall/network connectivity
   - Review error logs in `var/log/system.log`

3. **Channel not loading**:
   - Save API key configuration first
   - Check API key format: `<key_id>.<version>.<secret>`
   - Verify API key has channel access

### Logging

The module logs events with the prefix `[Stellion_Pricemind]`:

- **Warnings**: Non-2xx API responses, send failures
- **Errors**: API key decryption failures, channel fetch errors

Check logs in:
- `var/log/system.log`
- `var/log/exception.log`

### Failed Requests

Monitor failed requests in the database:

```sql
SELECT * FROM stellion_pricemind_failed_request 
WHERE status = 0 
ORDER BY created_at DESC;
```

## Development

### Module Structure

```
app/code/Stellion/Pricemind/
├── Block/Adminhtml/System/Config/Form/Field/
│   └── Channel.php              # Channel selection field
├── Model/
│   ├── Api/Client.php           # Pricemind API client
│   ├── Config/
│   │   ├── Backend/Channel.php  # Channel config backend
│   │   └── Source/Channels.php  # Channel options source
│   ├── FailedRequest.php        # Failed request model
│   ├── ResourceModel/           # Database resources
│   └── Sender.php               # HTTP request sender
├── Observer/
│   └── ProductPriceChangeObserver.php  # Main price change handler
├── etc/
│   ├── acl.xml                  # Admin permissions
│   ├── adminhtml/system.xml     # Admin configuration
│   ├── config.xml               # Default configuration
│   ├── db_schema.xml            # Database schema
│   ├── events.xml               # Event observers
│   └── module.xml               # Module declaration
└── registration.php             # Module registration
```

### Extending the Module

To extend functionality:

1. **Add Custom Fields**: Modify the observer to send additional product attributes
2. **Retry Logic**: Implement cron job to retry failed requests
3. **Bulk Updates**: Add CLI command for bulk price synchronization
4. **Webhooks**: Add webhook endpoint for bidirectional sync

## Support

For technical support or feature requests:

- Create an issue in the project repository
- Contact Pricemind support team
- Review the [Pricemind API documentation](https://api.pricemind.io/docs)

## License

This module is released under the MIT License. See LICENSE file for details.

## Changelog

### Version 1.0.0
- Initial release
- Real-time price change tracking
- Special price and date range support
- Multi-store configuration
- Error handling and logging
- Failed request storage
