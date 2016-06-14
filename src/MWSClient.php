<?php 
namespace MCS;

use DateTime;
use Exception;
use DateTimeZone;
use League\Csv\Reader;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Spatie\ArrayToXml\ArrayToXml;
use MCS\MWSEndPoint as EndPoint;

class MWSClient{
    
    const SIGNATURE_METHOD = 'HmacSHA256';
    const SIGNATURE_VERSION = '2';
    const DATE_FORMAT = "Y-m-d\TH:i:s.\\0\\0\\0\\Z";
    const APPLICATION_NAME = 'MCS/MwsClient';
    
    private $config = [
        'Seller_Id' => null,
        'Marketplace_Id' => null,
        'Access_Key_ID' => null,
        'Secret_Access_Key' => null,
        'Application_Version' => '0.0.*'
    ];  
    
    private $MarketplaceIds = [
        'A2EUQ1WTGCTBG2' => 'mws.amazonservices.ca',
        'ATVPDKIKX0DER'  => 'mws.amazonservices.com',
        'A1AM78C64UM0Y8' => 'mws.amazonservices.com.mx',
        'A1PA6795UKMFR9' => 'mws-eu.amazonservices.com',
        'A1RKKUPIHCS9HS' => 'mws-eu.amazonservices.com',
        'A13V1IB3VIYZZH' => 'mws-eu.amazonservices.com',
        'A21TJRUUN4KGV'  => 'mws.amazonservices.in',
        'APJ6JRA9NG5V4'  => 'mws-eu.amazonservices.com',
        'A1F83G8C2ARO7P' => 'mws-eu.amazonservices.com',
        'A1VC38T7YXB528' => 'mws.amazonservices.jp',
        'AAHKV2X7AFYLW'  => 'mws.amazonservices.com.cn',
    ];
    
    public function __construct(array $config)
    {   
        foreach($config as $key => $value) {
            $this->config[$key] = $value;
        }
        
        foreach($this->config as $key => $value) {
            if(is_null($value)) {
                throw new Exception('Required field ' . $key . ' is not set');    
            }
        } 
        
        if (!isset($this->MarketplaceIds[$this->config['Marketplace_Id']])) {
            throw new Exception('Invalid Marketplace Id');    
        }
        
        $this->config['Application_Name'] = self::APPLICATION_NAME;
        $this->config['Region_Host'] = $this->MarketplaceIds[$this->config['Marketplace_Id']];
        $this->config['Region_Url'] = 'https://' . $this->config['Region_Host'];
        
    }
    
    /**
     * A method to quickly check if the supplied credentials are valid
     * @return boolean
     */
    public function validateCredentials()
    {
        try{
            $this->ListOrderItems('validate');  
        } catch(Exception $e) {
            if ($e->getMessage() == 'Invalid AmazonOrderId: validate') {
                return true;
            } else {
                return false;    
            }
        }
    }
    
    /**
     * Returns the current competitive price of a product, based on ASIN.
     * @param array [$asin_array = []]
     * @return array
     */
    public function GetCompetitivePricingForASIN($asin_array = [])
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of ASIN\'s for this call is 20');    
        }
        
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        
        foreach($asin_array as $key){
            $query['ASINList.ASIN.' . $counter] = $key; 
            $counter++;
        }
        
        $response = $this->request(
            'GetCompetitivePricingForASIN',
            $query
        );
        
        if (isset($response['GetCompetitivePricingForASINResult'])) {
            $response = $response['GetCompetitivePricingForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];    
        }
        
        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'])) {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = $product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'];
            }
        }
        return $array;
        
    }
    
    /**
     * Returns lowest priced offers for a single product, based on ASIN.
     * @param string $asin                    
     * @param string [$ItemCondition = 'New'] Should be one in: New, Used, Collectible, Refurbished, Club
     * @return array  
     */
    public function GetLowestPricedOffersForASIN($asin, $ItemCondition = 'New')
    {
        
        $query = [
            'ASIN' => $asin,
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'ItemCondition' => $ItemCondition
        ];
        
        return $this->request(
            'GetLowestPricedOffersForASIN',
            $query
        );
        
    }
    
    /**
     * Returns pricing information for your own offer listings, based on SKU.
     * @param array  [$sku_array = []]       
     * @param string [$ItemCondition = null] 
     * @return array  
     */
    public function GetMyPriceForSKU($sku_array = [], $ItemCondition = null)
    {
        if (count($sku_array) > 20) {
            throw new Exception('Maximum amount of SKU\'s for this call is 20');    
        }
        
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        
        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }
        
        foreach($sku_array as $key){
            $query['SellerSKUList.SellerSKU.' . $counter] = $key; 
            $counter++;
        }
        
        $response = $this->request(
            'GetMyPriceForSKU',
            $query
        );
        
        if (isset($response['GetMyPriceForSKUResult'])) {
            $response = $response['GetMyPriceForSKUResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];    
        }
        
        $array = [];
        foreach ($response as $product) {
            if (isset($product['@attributes']['status']) && $product['@attributes']['status'] == 'Success') {
                $array[$product['@attributes']['SellerSKU']] = $product['Product']['Offers']['Offer'];
            } else {
                $array[$product['@attributes']['SellerSKU']] = false;
            }
        }
        return $array;
        
    }
    
    /**
     * Returns pricing information for your own offer listings, based on ASIN.
     * @param array [$asin_array = []]
     * @param string [$ItemCondition = null] 
     * @return array
     */
    public function GetMyPriceForASIN($asin_array = [], $ItemCondition = null)
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of SKU\'s for this call is 20');    
        }
        
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        
        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }
        
        foreach($asin_array as $key){
            $query['ASINList.ASIN.' . $counter] = $key; 
            $counter++;
        }
        
        $response = $this->request(
            'GetMyPriceForASIN',
            $query
        );
        
        if (isset($response['GetMyPriceForASINResult'])) {
            $response = $response['GetMyPriceForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];    
        }
        
        $array = [];
        foreach ($response as $product) {
            if (isset($product['@attributes']['status']) && $product['@attributes']['status'] == 'Success') {
                $array[$product['@attributes']['ASIN']] = $product['Product']['Offers']['Offer'];
            } else {
                $array[$product['@attributes']['ASIN']] = false;
            }
        }
        return $array;
        
    }
    
    /**
     * Returns pricing information for the lowest-price active offer listings for up to 20 products, based on ASIN.
     * @param array [$asin_array = []] array of ASIN values
     * @param array [$ItemCondition = null] Should be one in: New, Used, Collectible, Refurbished, Club. Default: All
     * @return array 
     */
    public function GetLowestOfferListingsForASIN($asin_array = [], $ItemCondition = null)
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of ASIN\'s for this call is 20');    
        }
        
        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];
        
        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }
        
        foreach($asin_array as $key){
            $query['ASINList.ASIN.' . $counter] = $key; 
            $counter++;
        }
        
        $response = $this->request(
            'GetLowestOfferListingsForASIN',
            $query
        );
        
        if (isset($response['GetLowestOfferListingsForASINResult'])) {
            $response = $response['GetLowestOfferListingsForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];    
        }
        
        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['LowestOfferListings']['LowestOfferListing'])) {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = $product['Product']['LowestOfferListings']['LowestOfferListing'];
            } else {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = false;
            }
        }
        return $array;
        
    }
    
    /**
     * Returns orders created or updated during a time frame that you specify.
     * @param object DateTime $from 
     * @return array
     */
    public function ListOrders(DateTime $from)
    {
        $query = [
            'CreatedAfter' => gmdate(self::DATE_FORMAT, $from->getTimestamp()),
            'OrderStatus.Status.1' => 'Unshipped',
            'OrderStatus.Status.2' => 'PartiallyShipped',
            'FulfillmentChannel.Channel.1' => 'MFN'
        ];
        
        $response = $this->request(
            'ListOrders',
            $query
        );
        
        if (isset($response['ListOrdersResult']['Orders']['Order'])) {
            $response = $response['ListOrdersResult']['Orders']['Order'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                return [$response];
            }
            return $response;
        } else {
            return [];    
        }   
    }
    
    /**
     * Returns an order based on the AmazonOrderId values that you specify.
     * @param string $AmazonOrderId
     * @return array if the order is found, false if not
     */
    public function GetOrder($AmazonOrderId)
    { 
        $response = $this->request('GetOrder', [
            'AmazonOrderId.Id.1' => $AmazonOrderId
        ]); 
        
        if (isset($response['GetOrderResult']['Orders']['Order'])) {
            return $response['GetOrderResult']['Orders']['Order'];
        } else {
            return false;    
        }
    }
    
    /**
     * Returns order items based on the AmazonOrderId that you specify.
     * @param string $AmazonOrderId
     * @return array  
     */
    public function ListOrderItems($AmazonOrderId)
    {
        $response = $this->request('ListOrderItems', [
            'AmazonOrderId' => $AmazonOrderId
        ]);
        
        return array_values($response['ListOrderItemsResult']['OrderItems']);   
    }
    
    /**
     * Returns the parent product categories that a product belongs to, based on SellerSKU.
     * @param string $SellerSKU
     * @return array if found, false if not found
     */
    public function GetProductCategoriesForSKU($SellerSKU)
    {
        $result = $this->request('GetProductCategoriesForSKU', [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'SellerSKU' => $SellerSKU
        ]);
        
        if (isset($result['GetProductCategoriesForSKUResult']['Self'])) {
            return $result['GetProductCategoriesForSKUResult']['Self'];    
        } else {
            return false;    
        }
    }
    
    /**
     * Returns the parent product categories that a product belongs to, based on ASIN.
     * @param string $ASIN
     * @return array if found, false if not found
     */
    public function GetProductCategoriesForASIN($ASIN)
    {
        $result = $this->request('GetProductCategoriesForASIN', [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'ASIN' => $ASIN
        ]);
        
        if (isset($result['GetProductCategoriesForASINResult']['Self'])) {
            return $result['GetProductCategoriesForASINResult']['Self'];    
        } else {
            return false;    
        }
    }
    
    /**
     * Returns a list of products and their attributes, based on a list of ASIN, GCID, SellerSKU, UPC, EAN, ISBN, and JAN values.
     * @param array  $list A list of id's
     * @param string [$type = 'ASIN']  the identifier name
     * @return array
     */
    public function GetMatchingProductForId(array $list, $type = 'ASIN')
    { 
        $list = array_unique($list);
        
        if(count($list) > 5) {
            throw new Exception('Maximum number of id\'s = 5');    
        }
        
        $counter = 1;
        $array = [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'IdType' => $type
        ];
        
        foreach($list as $key){
            $array['IdList.Id.' . $counter] = $key; 
            $counter++;
        }
        
        $response = $this->request(
            'GetMatchingProductForId',
            $array,
            null,
            true
        ); 
        
        $languages = [
            'de-DE', 'en-EN', 'es-ES', 'fr-FR', 'it-IT', 'en-US'
        ];
        
        $replace = [
            '</ns2:ItemAttributes>' => '</ItemAttributes>'
        ];
        
        foreach($languages as $language) {
            $replace['<ns2:ItemAttributes xml:lang="' . $language . '">'] = '<ItemAttributes><Language>' . $language . '</Language>';
        }
        
        $replace['ns2:'] = '';
        
        $response = $this->xmlToArray(strtr($response, $replace));
        
        if (isset($response['GetMatchingProductForIdResult']['@attributes'])) {
            $response['GetMatchingProductForIdResult'] = [
                0 => $response['GetMatchingProductForIdResult']
            ];    
        }
    
        $found = [];
        $not_found = [];
        
        if (isset($response['GetMatchingProductForIdResult']) && is_array($response['GetMatchingProductForIdResult'])) {
            $array = [];
            foreach ($response['GetMatchingProductForIdResult'] as $product) {
                $asin = $product['@attributes']['Id'];
                if ($product['@attributes']['status'] != 'Success') {
                    $not_found[] = $asin;    
                } else {
                    $array = [];
                    if (!isset($product['Products']['Product']['AttributeSets'])) {
                        $product['Products']['Product'] = $product['Products']['Product'][0];    
                    }
                    foreach ($product['Products']['Product']['AttributeSets']['ItemAttributes'] as $key => $value) {
                        if (is_string($key) && is_string($value)) {
                            $array[$key] = $value;    
                        }
                    }
                    if (isset($product['Products']['Product']['AttributeSets']['ItemAttributes']['SmallImage'])) {
                        $image = $product['Products']['Product']['AttributeSets']['ItemAttributes']['SmallImage']['URL'];
                        $array['medium_image'] = $image;
                        $array['small_image'] = str_replace('._SL75_', '._SL50_', $image);
                        $array['large_image'] = str_replace('._SL75_', '', $image);;
                    }
                    $found[$asin] = $array;
                }
            }
        }
        
        return [
            'found' => $found,
            'not_found' => $not_found
        ];
    
    }
    
    /**
     * Returns a list of reports that were created in the previous 90 days.
     * @return array
     */
    public function GetReportList()
    {
        return $this->request('GetReportList');   
    }
    
    /**
     * Update a product's stock quantity
     * @param array $array array containing sku as key and quantity as value
     * @return array feed submission result
     */
    public function updateStock(array $array)
    {   
        $feed = [
            'MessageType' => 'Inventory',
            'Message' => []
        ];
        
        foreach ($array as $sku => $quantity) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'OperationType' => 'Update',
                'Inventory' => [
                    'SKU' => $sku,
                    'Quantity' => (int) $quantity
                ]
            ];  
        }
        
        return $this->SubmitFeed('_POST_INVENTORY_AVAILABILITY_DATA_', $feed);
        
    }
    
    /**
     * Update a product's price
     * @param array $array an array containing sku as key and price as value
     * Price has to be formatted as XSD Numeric Data Type (http://www.w3schools.com/xml/schema_dtypes_numeric.asp)
     * @return array feed submission result
     */
    public function updatePrice(array $array)
    {   
        
        $feed = [
            'MessageType' => 'Price',
            'Message' => []
        ];
        
        foreach ($array as $sku => $price) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'Price' => [
                    'SKU' => $sku,
                    'StandardPrice' => [
                        '_value' => $price,
                        '_attributes' => [
                            'currency' => 'DEFAULT'
                        ]
                    ]
                ]
            ];  
        }
        
        return $this->SubmitFeed('_POST_PRODUCT_PRICING_DATA_', $feed);
        
    }
    
    /**
     * Get a feed's submission status
     * @param string $FeedSubmissionId
     * @return array
     */
    public function GetFeedSubmissionResult($FeedSubmissionId)
    {
        $result = $this->request('GetFeedSubmissionResult', [
            'FeedSubmissionId' => $FeedSubmissionId
        ]); 
        
        if (isset($result['Message']['ProcessingReport'])) {
            return $result['Message']['ProcessingReport'];    
        } else {
            return $result;    
        }
    }
    
    /**
     * Submit a feed to MWS. 
     * @param string $FeedType (http://docs.developer.amazonservices.com/en_US/feeds/Feeds_FeedType.html)
     * @param mixed $feedContent Array will be converted to xml using https://github.com/spatie/array-to-xml. Strings will not be modified.
     * @param boolean $debug Return the generated xml and don't send it to amazon
     * @return array
     */
    public function SubmitFeed($FeedType, $feedContent, $debug = false)
    {
        
        if (is_array($feedContent)) {
            $feedContent = $this->arrayToXml(
                array_merge([
                    'Header' => [
                        'DocumentVersion' => 1.01,
                        'MerchantIdentifier' => $this->config['Seller_Id']
                    ]
                ], $feedContent)
            );
        }
        
        if ($debug === true) {
            return $feedContent;    
        }
        
        $query = [
            'FeedType' => $FeedType,
            'PurgeAndReplace' => 'false',
            'Merchant' => $this->config['Seller_Id'],
            'MarketplaceId.Id.1' => false,
            'SellerId' => false,
        ];
        
        if ($FeedType === '_POST_PRODUCT_PRICING_DATA_') {
            $query['MarketplaceIdList.Id.1'] = $this->config['Marketplace_Id'];        
        }
        
        $response = $this->request(
            'SubmitFeed',
            $query,
            $feedContent
        );
        
        return $response['SubmitFeedResult']['FeedSubmissionInfo'];
    }
    
    /**
     * Convert an array to xml
     * @param $array array to convert
     * @param $customRoot [$customRoot = 'AmazonEnvelope']
     * @return sting
     */
    private function arrayToXml(array $array, $customRoot = 'AmazonEnvelope')
    {
        return ArrayToXml::convert($array, $customRoot);
    }
    
    /**
     * Convert an xml string to an array
     * @param string $xmlstring 
     * @return array
     */
    private function xmlToArray($xmlstring)
    {
        return json_decode(json_encode(simplexml_load_string($xmlstring)), true);
    }
    
    /**
     * Request a report
     * @param string $report (http://docs.developer.amazonservices.com/en_US/reports/Reports_ReportType.html)
     * @param DateTime [$StartDate = null]
     * @param EndDate [$EndDate = null]
     * @return string ReportRequestId
     */
    public function RequestReport($report, $StartDate = null, $EndDate = null)
    {
        $query = [
            'ReportType' => $report
        ];
        
        if (!is_null($StartDate)) {
            if (!is_a($StartDate, 'DateTime')) {
                throw new Exception('StartDate should be a DateTime object');       
            } else {
                $query['StartDate'] = gmdate(self::DATE_FORMAT, $StartDate->getTimestamp());
            }
        }
        
        if (!is_null($EndDate)) {
            if (!is_a($EndDate, 'DateTime')) {
                throw new Exception('EndDate should be a DateTime object');       
            } else {
                $query['EndDate'] = gmdate(self::DATE_FORMAT, $EndDate->getTimestamp());
            }
        }
    
        $result = $this->request(
            'RequestReport',
            $query
        );
        
        if (isset($result['RequestReportResult']['ReportRequestInfo']['ReportRequestId'])) {
            return $result['RequestReportResult']['ReportRequestInfo']['ReportRequestId'];
        } else {
            throw new Exception('Error trying to request report');    
        }
    }
    
    /**
     * Get a report's contents
     * @param string $ReportId
     * @return array on succes
     */
    public function GetReport($ReportId)
    {
        $status = $this->GetReportRequestStatus($ReportId);
        
        if ($status !== false && $status['ReportProcessingStatus'] === '_DONE_NO_DATA_') {
            return [];
        } else if ($status !== false && $status['ReportProcessingStatus'] === '_DONE_') {
            
            $result = $this->request('GetReport', [
                'ReportId' => $status['GeneratedReportId']
            ]);
            
            if (is_string($result)) {
                $csv = Reader::createFromString($result);
                $csv->setDelimiter("\t");
                $headers = $csv->fetchOne();
                $result = [];
                foreach ($csv->setOffset(1)->fetchAll() as $row) {
                    $result[] = array_combine($headers, $row);    
                }
            }
            
            return $result;
            
        } else {
            return false;    
        }
    }
    
    /**
     * Get a report's processing status
     * @param string  $ReportId
     * @return array if the report is found
     */
    public function GetReportRequestStatus($ReportId)
    {
        $result = $this->request('GetReportRequestList', [
            'ReportRequestIdList.Id.1' => $ReportId    
        ]);
          
        if (isset($result['GetReportRequestListResult']['ReportRequestInfo'])) {
            return $result['GetReportRequestListResult']['ReportRequestInfo'];
        } 
        
        return false;
        
    }
    
    /**
     * Request MWS
     */
    private function request($endPoint, array $query = [], $body = null, $raw = false)
    {
    
        $endPoint = MWSEndPoint::get($endPoint);
        
        $query = array_merge([
            'Timestamp' => gmdate(self::DATE_FORMAT, time()),
            'AWSAccessKeyId' => $this->config['Access_Key_ID'],
            'Action' => $endPoint['action'],
            'MarketplaceId.Id.1' => $this->config['Marketplace_Id'],
            'SellerId' => $this->config['Seller_Id'],
            'SignatureMethod' => self::SIGNATURE_METHOD,
            'SignatureVersion' => self::SIGNATURE_VERSION,
            'Version' => $endPoint['date'],
        ], $query);
        
        if (isset($query['MarketplaceId'])) {
            unset($query['MarketplaceId.Id.1']);
        }
        
        try{
            
            $headers = [
                'Accept' => 'application/xml',
                'x-amazon-user-agent' => $this->config['Application_Name'] . '/' . $this->config['Application_Version']
            ];
            
            if ($endPoint['action'] === 'SubmitFeed') {
                $headers['Content-MD5'] = base64_encode(md5($body, true));
                $headers['Content-Type'] = 'text/xml; charset=iso-8859-1';
                $headers['Host'] = $this->config['Region_Host'];
                
                unset(
                    $query['MarketplaceId.Id.1'],
                    $query['SellerId']
                );  
            }
            
            $requestOptions = [
                'headers' => $headers,
                'body' => $body
            ];
            
            ksort($query);
            
            $query['Signature'] = base64_encode(
                hash_hmac(
                    'sha256', 
                    $endPoint['method']
                    . PHP_EOL 
                    . $this->config['Region_Host']
                    . PHP_EOL 
                    . $endPoint['path'] 
                    . PHP_EOL 
                    . http_build_query($query), 
                    $this->config['Secret_Access_Key'], 
                    true
                )
            );
            
            $requestOptions['query'] = $query;
            
            $client = new Client();
            
            $response = $client->request(
                $endPoint['method'], 
                $this->config['Region_Url'] . $endPoint['path'], 
                $requestOptions
            );
            
            $body = (string) $response->getBody();
            
            if ($raw) {
                return $body;    
            } else if (strpos(strtolower($response->getHeader('Content-Type')[0]), 'xml') !== false) {
                return $this->xmlToArray($body);          
            } else {
                return $body;
            }
           
        } catch(BadResponseException $e) {
            if ($e->hasResponse()) {
                $message = $e->getResponse();
                $message = $message->getBody();
                if (strpos($message, '<ErrorResponse') !== false) {
                    $error = simplexml_load_string($message);
                    $message = $error->Error->Message;
                }
            } else {
                $message = 'An error occured';    
            }
            throw new Exception($message);
        }  
    }
}