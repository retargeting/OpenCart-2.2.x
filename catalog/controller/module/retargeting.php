<?php
/**
 * Retargeting Module for OpenCart 2.2.x
 *
 * catalog/controller/module/retargeting.php
 */

include_once 'Retargeting_REST_API_Client.php';

class ControllerModuleRetargeting extends Controller {
    
    protected function getPath($parent_id, $current_path = '') {
		$category_info = $this->model_catalog_category->getCategory($parent_id);

		if ($category_info) {
			if (!$current_path) {
				$new_path = $category_info['category_id'];
			} else {
				$new_path = $category_info['category_id'] . '_' . $current_path;
			}	

			$path = $this->getPath($category_info['parent_id'], $new_path);

			if ($path) {
				return $path;
			} else {
				return $new_path;
			}
		}
	}

    private $checkHTTP = null;

    public function fixURL($url)
    {
        $url = str_replace("&amp;","&",$url);
        
        if (!filter_var($url, FILTER_VALIDATE_URL) && !strpos($url, "%20")) {
            $new_URL = explode("?", $url, 2);
            $newURL = explode("/",$new_URL[0]);
    
            if ($this->checkHTTP === null) {
                $this->checkHTTP = !empty(array_intersect(["https:","http:"], $newURL));
            } 
            
            foreach ($newURL as $k=>$v ){
                if (!$this->checkHTTP || $this->checkHTTP && $k > 2) {
                    $newURL[$k] = rawurlencode($v);
                }
            }
    
            if (isset($new_URL[1])) {
                $new_URL[0] = implode("/",$newURL);
                return implode("?", $new_URL);
            } else {
                return implode("/",$newURL);
            }
        }
        return $url;
    }

    public function index() {

        /* ---------------------------------------------------------------------------------------------------------------------
         * Setup the protocol
         * ---------------------------------------------------------------------------------------------------------------------
         */
        if (isset($this->request->server['HTTPS']) && (($this->request->server['HTTPS'] == 'on') || ($this->request->server['HTTPS'] == '1'))) {
            $data['shop_url'] = $this->config->get('config_ssl');
        } else {
            $data['shop_url'] = $this->config->get('config_url');
        }

        /* ---------------------------------------------------------------------------------------------------------------------
         * Load the module's language file
         * ---------------------------------------------------------------------------------------------------------------------
         */
        $this->language->load('module/retargeting');

        /* ---------------------------------------------------------------------------------------------------------------------
         * Load models that we might need access to
         * ---------------------------------------------------------------------------------------------------------------------
         */
        $this->load->model('checkout/order');
        $this->load->model('setting/setting');
        $this->load->model('design/layout');
        $this->load->model('catalog/category');
        $this->load->model('catalog/manufacturer');
        $this->load->model('catalog/product');
        $this->load->model('catalog/information');
       // $this->load->model('marketing/coupon'); /* Available only in the admin/ area */
//         $this->load->model('total/coupon');

        /* ---------------------------------------------------------------------------------------------------------------------
         * Get the saved values from the admin area
         * ---------------------------------------------------------------------------------------------------------------------
         */
        $data['api_key_field'] = $this->config->get('retargeting_apikey');
        $data['api_secret_field'] = $this->config->get('retargeting_token');

        $data['retargeting_setEmail'] = htmlspecialchars_decode($this->config->get('retargeting_setEmail'));
        $data['retargeting_addToCart'] = htmlspecialchars_decode($this->config->get('retargeting_addToCart'));
        $data['retargeting_clickImage'] = htmlspecialchars_decode($this->config->get('retargeting_clickImage'));
        $data['retargeting_commentOnProduct'] = htmlspecialchars_decode($this->config->get('retargeting_commentOnProduct'));
        $data['retargeting_setVariation'] = htmlspecialchars_decode($this->config->get('retargeting_setVariation'));
/*
        if (isset($this->session->data['order_id'])) {
            setcookie("retargeting_save_order", $this->session->data['order_id'], time()+3600);
        }
*/
        if (isset($_GET['csv']) && $_GET['csv'] === 'retargeting') {

            /* Modify the header */
            header('Expires: Sun, 01 Jan 2014 00:00:00 GMT');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('Cache-Control: post-check=0, pre-check=0', FALSE);
            header('Pragma: no-cache');
            header("Content-Disposition: attachment; filename=retargeting.csv; charset=utf-8");
            header("Content-type: text/csv; charset=utf-8");

            $outstream = fopen('php://output', 'w');

            $get = array(
                'product_id'=>'product id',
                'name'=>'product name',
                'url'=>'product url',
                'image'=>'image url',
                'quantity'=>'stock',
                'price'=>'price',
                'special'=>'sale price',
                'brand'=>'brand',
                'getProductCategories'=>'category',
                'extra'=>'extra data'
            );

            fputcsv($outstream, $get, ',', '"');

            /* Pull ALL products from the database */
            $products = $this->model_catalog_product->getProducts();
            $retargetingFeed = array();
            $extrastring = [];
            $add = true;
            
            foreach ($products as $product) {

                $NewList = array();
                $extrastring = [];

                foreach($get as $key=>$val){

                    switch($val) {
                        case 'image url':
                            if($product['image']==''){
                                $add = false;
                                break;
                            }
                            if (isset($this->request->server['HTTPS']) && (($this->request->server['HTTPS'] == 'on') || ($this->request->server['HTTPS'] == '1'))) {
                                $NewList[$val] = HTTPS_SERVER . 'image/' . $product['image'];
                            } else {
                                $NewList[$val] = HTTP_SERVER . 'image/' . $product['image'];
                            }
                            $NewList[$val] = $this->fixURL($NewList[$val]);
                        break;
                        case 'product url':
                            $NewList[$val] = $this->url->link('product/product', 'product_id=' . $product['product_id']);
                            $NewList[$val] = $this->fixURL($NewList[$val]);
                        break;

                        case 'stock':
                            $NewList[$val] = ((int) $product[$key] > 0) ? 1 : 0;
                        break;

                        case 'price':
                            if ((int) $product['price'] === 0) {
                                $add = false;
                                break;
                            }
                            $NewList[$val] = round(
                                $this->tax->calculate(
                                  $product['price'],
                                  $product['tax_class_id'],
                                  $this->config->get('config_tax')
                                ), 2);
                        break;

                        case 'sale price':
                            $NewList[$val] = empty($product[$key]) ? $NewList['price'] :  round (
                                $this->tax->calculate($product[$key], $product['tax_class_id'], $this->config->get('config_tax'))
                            );

                            if ((float) $product[$key] > (float) $product['price']) {
                                $NewList[$val] = $NewList['price'];
                            }

                        break;

                        case 'brand':
                            $NewList[$val] =  $product['manufacturer'];
                        break;

                        case 'category':
                            $categories = $this->model_catalog_product->getCategories($product['product_id']);
                            /* $categories = $this->model_catalog_category->getCategory($product['product_id']); */

                            foreach ($categories as $category) {
                                $path = $this->getPath($category['category_id']);

                                if ($path) {
                                    $name = '';

                                    foreach (explode('_', $path) as $path_id) {
                                        $category_info = $this->model_catalog_category->getCategory($path_id);

                                        if ($category_info) {
                                            if ($name=='') {
                                                $name = $category_info['name'];
                                                $id = $category_info['category_id'];
                                            } else {
                                                $extrastring[$category_info['category_id']] = $category_info['name'];
                                            }
                                        }
                                    }
                                }
                            }
                            $NewList[$val] = $name;
                            $extrastring[$id] = $name;
                        break;

                        case 'extra data':
                            $NewList[$val] = json_encode([
                                'categories' => $extrastring
                            ], JSON_UNESCAPED_SLASHES);
                        break;

                        default:
                            $NewList[$val] = $product[$key]; 
                    }
                }
                if($add) {
                    fputcsv($outstream, $NewList, ',', '"');
                }

                $add = true;
            }
            
            fclose($outstream);
            die();
        }
        /**
         * --------------------------------------
         *             Products feed
         * --------------------------------------
         **/
        /* JSON Request intercepted, kill everything else and output */
        if (isset($_GET['json']) && $_GET['json'] === 'retargeting') {

            /* Modify the header */
            header('Content-Type: application/json');

            /* Pull ALL products from the database */
            $products = $this->model_catalog_product->getProducts();
            $retargetingFeed = array();
            foreach ($products as $product) {
              $retargetingFeed[] = array (
                'id'=> $product['product_id'],
                'price' => round(
                  $this->tax->calculate(
                    $product['price'],
                    $product['tax_class_id'],
                    $this->config->get('config_tax')
                  ), 2),
                'promo' => (
                  isset($product['special']) ? round (
                    $this->tax->calculate(
                      $product['special'],
                      $product['tax_class_id'],
                      $this->config->get('config_tax')
                    ), 2)
                    : 0),
                'promo_price_end_date' => null,
                'inventory' => array(
                  'variations' => false,
                  'stock' => (($product['quantity'] > 0) ? 1 : 0)
                ),
                'user_groups' => false,
                'product_availability' => null                  
              );
            }
            
            echo json_encode($retargetingFeed);
            die();
          }
            
        /* --- END PRODUCTS FEED  --- */



        /**
         * ---------------------------------------------------------------------------------------------------------------------
         *
         * API poach && Discount codes generator
         *
         * ---------------------------------------------------------------------------------------------------------------------
         *
         *
         * ********
         * REQUEST:
         * ********
         * POST : key​=your_retargeting_key
         * GET : type​=0​&value​=30​&count​=3
         * * type => (Integer) 0​: Fixed; 1​: Percentage; 2​: Free Delivery;
         * * value => (Float) actual value of discount
         * * count => (Integer) number of discounts codes to be generated
         *
         *
         * *********
         * RESPONDS:
         * *********
         * json with the discount codes
         * * ['code1', 'code2', ... 'codeN']
         *
         *
         * STEP 1: check $_POST
         * STEP 2: add the discount codes to the local database
         * STEP 3: expose the codes to Retargeting
         * STEP 4: kill the script
         */
        if (isset($_GET) && isset($_GET['key']) && ($_GET['key'] === $data['api_key_field'])) {

            /* -------------------------------------------------------------
             * STEP 1: check $_POST and validate the API Key
             * -------------------------------------------------------------
             */

            /*
            include_once 'Retargeting_REST_API_Client.php';
            $client = new Retargeting_REST_API_Client($data['api_key_field'], $data['api_secret_field']);
            $client->setResponseFormat("json");
            $client->setDecoding(false);
            $client->setApiVersion('1.0');
            $client->setApiUri('https://retargeting.ro/api');
            */

            /* Check and adjust the incoming values */
            $discount_type = (isset($_GET['type'])) ? (filter_var($_GET['type'], FILTER_SANITIZE_NUMBER_INT)) : 'Received other than int';
            $discount_value = (isset($_GET['value'])) ? (filter_var($_GET['value'], FILTER_SANITIZE_NUMBER_FLOAT)) : 'Received other than float';
            $discount_codes = (isset($_GET['count'])) ? (filter_var($_GET['count'], FILTER_SANITIZE_NUMBER_INT)) : 'Received other than int';

            /* -------------------------------------------------------------
             * STEP 2: Generate and add to local database the discount codes
             * -------------------------------------------------------------
             */
            $generate_code = function() {
                return substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 1) . substr(str_shuffle('AaBbCcDdEeFfGgHhIiJjKkLlMmNnOoPpQqRrSsTtUuVvWwXxYyZz'), 0, 9);
            };

            $datetime = new DateTime();
            $start_date = $datetime->format('Y-m-d');
            $datetime->modify('+12 months');
            $expiration_date = $datetime->format('Y-m-d');

            for ($i = $discount_codes; $i > 0; $i--) {

                $code = $generate_code();
                $discount_codes_collection[] = $code;

                /* Discount type: Fixed value */
                if ($discount_type == 0) {

                    $this->db->query("
                                INSERT INTO " . DB_PREFIX . "coupon
                                SET name = 'Discount Code: RTG_FX',
                                    code = '{$code}',
                                    discount = '{$discount_value}',
                                    type = 'F',
                                    total = '0',
                                    logged = '0',
                                    shipping = '0',
                                    date_start = '{$start_date}',
                                    date_end = '',
                                    uses_total = '1',
                                    uses_customer = '1',
                                    status = '1',
                                    date_added = NOW()
                                ");

                    /* Discount type: Percentage */
                } elseif ($discount_type == 1) {

                    $this->db->query("
                                INSERT INTO " . DB_PREFIX . "coupon
                                SET name = 'Discount Code: RTG_PRCNT',
                                    code = '{$code}',
                                    discount = '{$discount_value}',
                                    type = 'P',
                                    total = '0',
                                    logged = '0',
                                    shipping = '0',
                                    date_start = '{$start_date}',
                                    date_end = '',
                                    uses_total = '1',
                                    uses_customer = '1',
                                    status = '1',
                                    date_added = NOW()
                                ");

                    /* Discount type: Free delivery */
                } elseif ($discount_type == 2) {

                    $this->db->query("
                                INSERT INTO " . DB_PREFIX . "coupon
                                SET name = 'Discount Code: RTG_SHIP',
                                    code = '{$code}',
                                    discount = '0',
                                    type = 'F',
                                    total = '0',
                                    logged = '0',
                                    shipping = '1',
                                    date_start = '{$start_date}',
                                    date_end = '',
                                    uses_total = '1',
                                    uses_customer = '1',
                                    status = '1',
                                    date_added = NOW()
                                ");
                }

            } // End generating discount codes


            /* -------------------------------------------------------------
             * STEP 3: Return the newly generated codes
             * -------------------------------------------------------------
             */
            if (isset($discount_codes_collection) && !empty($discount_codes_collection)) {

                /* Modify the header */
                header('Content-Type: application/json');

                /* Output the json */
                echo json_encode($discount_codes_collection);

            }


            /* -------------------------------------------------------------
             * STEP 4: Kill the script
             * -------------------------------------------------------------
             */
            die();

        } // End $_GET processing
        /* --- END API URL & DISCOUNT CODES GENERATOR  --- */



        /* ---------------------------------------------------------------------------------------------------------------------
         *
         * Start implementing Retargeting JS functions
         *
         * --------------------------------------------------------------------------------------------------------------------*/

        /* Small helpers [pre-data processing] */
        $data['cart_products'] = isset($this->session->data['cart']) ? $this->session->data['cart'] : false;
        $data['wishlist'] = !empty($this->session->data['wishlist']) ? $this->session->data['wishlist'] : false;
        $data['current_page'] = isset($this->request->get['route']) ? $this->request->get['route'] : false;
        $data['current_category'] = isset($this->request->get['path']) ? explode('_', $this->request->get['path']) : '';
        $data['count_categories'] = is_array($data['current_category']) && (count($data['current_category']) > 0) ? (count($data['current_category'])) : 0;
        $data['js_output'] = "/* --- START Retargeting --- */\n\n";
        /* --- END pre-data processing  --- */



        /*
         * setEmail
         */
        /* User is logged in, pull data from DB */
        if (isset($this->session->data['customer_id']) && !empty($this->session->data['customer_id'])) {
            $full_name = $this->customer->getFirstName() . $this->customer->getLastName();
            $email_address = $this->customer->getEmail();
            $phone_number = $this->customer->getTelephone();

            $data['js_output'] .= "
                                        var _ra = _ra || {};
                                        _ra.setEmailInfo = {
                                            'email': '{$email_address}',
                                            'name': '{$full_name}',
                                            'phone': '{$phone_number}'
                                        };
                                        
                                        if (_ra.ready !== undefined) {
                                            _ra.setEmail(_ra.setEmailInfo)
                                        }
                                    ";
        } else {
            /* Listen on entire site for input data & validate it */
            $data['setEmail'] = "
                                        /* -- setEmail -- */
                                        function checkEmail(email) {
                                            var regex = /^([a-zA-Z0-9_.+-])+\@(([a-zA-Z0-9-])+\.)+([a-zA-Z0-9]{2,9})+$/;
                                            return regex.test(email);
                                        };

                                        jQuery(document).ready(function($){
                                            $(\"{$data['retargeting_setEmail']}\").blur(function(){
                                                if ( checkEmail($(this).val()) ) {
                                                    _ra.setEmail({ 'email': $(this).val()});
                                                    console.log('setEmail fired!');
                                                }
                                            });
                                        });
                                        ";

            $data['js_output'] .= $data['setEmail'];           
        }
        /* --- END setEmail  --- */



        /*
         * sendCategory ✓
         */
        if ($data['current_page'] === 'product/category') {

            $category_id_parent = $data['current_category'][0];
            $category_info_parent = $this->model_catalog_category->getCategory($category_id_parent);

            $data['sendCategory'] = '
                        /* -- sendCategory -- */
                                            ';
            $data['sendCategory'] = 'var _ra = _ra || {}; ';
            $data['sendCategory'] .= '_ra.sendCategoryInfo = {';

            /* We have a nested category */
            if (is_array($data['current_category']) && count($data['current_category']) > 1) {

                for ($i = count($data['current_category']) - 1; $i > 0; $i--) {
                    $category_id = $data['current_category'][$i];
                    $category_info = $this->model_catalog_category->getCategory($category_id);
                    $encoded_category_info_name = htmlspecialchars($category_info['name']);
                    $data['sendCategory'] .= "
                            'id': {$category_id},
                            'name': '{$encoded_category_info_name}',
                            'parent': {$category_id_parent},
                            'breadcrumb': [
                            ";
                    break;
                }

                array_pop($data['current_category']);

                for ($i = count($data['current_category']) - 1; $i >= 0; $i--) {
                    $category_id = $data['current_category'][$i];
                    $category_info = $this->model_catalog_category->getCategory($category_id);

                    if ($i === 0) {
                        $encoded_category_info_parent_name = htmlspecialchars($category_info_parent['name']);
                        $data['sendCategory'] .= "{
                                                        'id': {$category_id_parent},
                                                        'name': '{$encoded_category_info_parent_name}',
                                                        'parent': false
                                                        }
                                                        ";
                        break;
                    }

                    $data['sendCategory'] .= "{
                                                    'id': {$category_id},
                                                    'name': '{$encoded_category_info_name}',
                                                    'parent': {$category_id_parent}
                                                    },
                                                    ";
                }

                $data['sendCategory'] .= "]";

                /* We have a single category */
            } else {

                $data['category_id'] = $data['current_category'][0];
                $data['category_info'] = $this->model_catalog_category->getCategory($data['category_id']);
                $encoded_data_category_name = htmlspecialchars($data['category_info']['name']);
                $data['sendCategory'] .= "
                                                'id': {$data['category_id']},
                                                'name': '{$encoded_data_category_name}',
                                                'parent': false,
                                                'breadcrumb': []
                                                ";
            }

            //reset($data['current_category']);

            $data['sendCategory'] .= '};';
            $data['sendCategory'] .= "
                                            if (_ra.ready !== undefined) {
                                                _ra.sendCategory(_ra.sendCategoryInfo);
                                            };
                                            ";

            /* Send to output */
            $data['js_output'] .= $data['sendCategory'];
        }
        /* --- END sendCategory  --- */



        /*
         * sendBrand ✓
         */
        if ($data['current_page'] === 'product/manufacturer/info') {

            /* Check if the current product is part of a brand */
            if (isset($this->request->get['manufacturer_id']) && !empty($this->request->get['manufacturer_id'])) {
                $data['brand_id'] = $this->request->get['manufacturer_id'];
                $data['brand_name'] = $this->model_catalog_manufacturer->getManufacturer($this->request->get['manufacturer_id']);
                $encoded_data_brand_name = htmlspecialchars($data['brand_name']['name']);
                $data['sendBrand'] = "var _ra = _ra || {};
                                            _ra.sendBrandInfo = {
                                                                'id': {$data['brand_id']},
                                                                'name': '{$encoded_data_brand_name}'
                                                                };

                                                                if (_ra.ready !== undefined) {
                                                                    _ra.sendBrand(_ra.sendBrandInfo);
                                                                };
                                            ";

                /* Send to output */
                $data['js_output'] .= $data['sendBrand'];
            }
        }
        /* --- END sendBrand  --- */

        

        /*
         * sendProduct
         * likeFacebook
         */
        if ($data['current_page'] === 'product/product') {

            $product_id = strtok($this->request->get['product_id'], '?');
            $product_url = $this->url->link('product/product', 'product_id=' . $product_id);
            $product_details = $this->model_catalog_product->getProduct($product_id);
            $product_categories = $this->db->query("SELECT * FROM " . DB_PREFIX . "product_to_category WHERE product_id = '" . (int)$product_id . "'");
            $product_categories = $product_categories->rows; // Get all the subcategories for this product. Reorder its numerical indexes to ease the breadcrumb logic
            $decoded_product_name = htmlspecialchars_decode($product_details['name']);
            $decoded_product_url = htmlspecialchars_decode($product_url);
            $rootCat = array(array(
               'id' => 'Root',
               'name' => 'Root',
               'parent' => false,
               'breadcrumb' => array()
            ));
            /* Send the base info */
            $data['sendProduct'] = "
                                    var _ra = _ra || {};
                                    _ra.sendProductInfo = {
                                    ";
            $data['sendProduct'] .= "
                                    'id': $product_id,
                                    'name': '{$decoded_product_name}',
                                    'url': '{$decoded_product_url}',
                                    'img': '{$data['shop_url']}image/{$product_details['image']}',
                                    'price': '".round(
                                      $this->tax->calculate(
                                        $product_details['price'],
                                        $product_details['tax_class_id'], 
                                        $this->config->get('config_tax')
                                      ),2)."',
                                    'promo': '". (isset
                                    ($product_details['special']) ? round(
                                      $this->tax->calculate(
                                        $product_details['special'],
                                        $product_details['tax_class_id'],
                                         $this->config->get('config_tax')
                                       ),2) : 0) ."',
                                    'inventory': {
                                        'variations': false,
                                        'stock' : ".(($product_details['quantity'] > 0) ? 1 : 0)."
                                    },
                                    ";

            /* Check if the product has a brand assigned */
            if (isset($product_details['manufacturer_id'])) {
                $encoded_product_brand_name = htmlspecialchars($product_details['manufacturer']);
                $data['sendProduct'] .= "
                                        'brand': {'id': {$product_details['manufacturer_id']}, 'name': '{$encoded_product_brand_name}'},
                                        ";
            } else {
                $data['sendProduct'] .= "
                                        'brand': false,
                                        ";
            }

            /* Check if the product has a category assigned */
            if (isset($product_categories) && !empty($product_categories)) {
 
                $product_cat = $this->model_catalog_product->getCategories($product_id);

                $catDetails = array();
                foreach ($product_cat as $pcatid) {
                    $categoryDetails = $this->model_catalog_category->getCategory($pcatid['category_id']);

                    if(isset($categoryDetails['status']) && $categoryDetails['status'] == 1) {
                        $catDetails[] = $categoryDetails;
                    }
                }

                $preCat = array();
                foreach ($catDetails as $productCategory) {
                    if (isset($productCategory['parent_id']) && ($productCategory['parent_id'] == 0)) {
                        $preCat = array(array(
                            'id' => $productCategory['category_id'],
                            'name' => htmlspecialchars($productCategory['name']),
                            'parent' => false,
                            'breadcrumb' => array()
                        ));

                    } else {

                        $breadcrumbDetails =  $this->model_catalog_category->getCategory($productCategory['parent_id']);
                        $preCat = array(array(
                            'id' => (int)$productCategory['category_id'],
                            'name' => htmlspecialchars($productCategory['name']),
                            'parent' => 'Root',
                            // 'parent' => (int)$productCategory['parent_id'],
                            'breadcrumb' => array(array(
                                'id' => 'Root',
                                'name' => 'Root',
                                'parent' => false    
                            ))
                        ));
                        
                    }
                }
                if ( !empty($preCat) ) {
                  $data['sendProduct'] .= "'" . 'category' . "':" . json_encode($preCat);
                } else {
                    $data['sendProduct'] .= "'" . 'category' . "':" . json_encode($rootCat);
                }

            } else {

                $data['sendProduct'] .= "'" . 'category' . "':" . json_encode($rootCat);

             }// Close check if product has categories assigned

            $data['sendProduct'] .= "};"; // Close _ra.sendProductInfo
            $data['sendProduct'] .= "
                                            if (_ra.ready !== undefined) {
                                                _ra.sendProduct(_ra.sendProductInfo);
                                            };
                                            ";
            $data['js_output'] .= $data['sendProduct'];



            $data['likeFacebook'] = "
                                        if (typeof FB != 'undefined') {
                                            FB.Event.subscribe('edge.create', function () {
                                                _ra.likeFacebook({$product_id});
                                            });
                                        };
                                    ";
            $data['js_output'] .= $data['likeFacebook'];
        }
        /* --- END sendProduct  --- */
        
        /*
         * clickImage
         */
        if ($data['current_page'] === 'product/product') {
            $clickImage_product_id = strtok($this->request->get['product_id'], '?');
            $clickImage_product_info = $this->model_catalog_product->getProduct($clickImage_product_id);
            $data['clickImage'] = "
                                                /* -- clickImage -- */
                                                jQuery(document).ready(function(){
                                                        /* -- clickImage -- */
                                                        jQuery(\"{$data['retargeting_clickImage']}\").click(function(){
                                                            _ra.clickImage({$clickImage_product_id});
                                                        });
                                                });
                                                ";
            $data['js_output'] .= $data['clickImage'];
        }
        

        /*
         * addToWishList ✓
         */
        if (($data['wishlist'])) {

            /* Prevent notices */
            $this->session->data['retargeting_wishlist_product_id'] = (isset($this->session->data['retargeting_wishlist_product_id']) && !empty($this->session->data['retargeting_wishlist_product_id'])) ? $this->session->data['retargeting_wishlist_product_id'] : '';

            /* While pushing out an item from the WishList with a lower array index, OpenCart won't reset the numerical indexes, thus generating a notice. This fixes it */
            $data['wishlist'] = array_values($data['wishlist']);

            if ($this->session->data['retargeting_wishlist_product_id'] != ($data['wishlist'][count($data['wishlist']) - 1])) {
                /* Get the total number of products in WishList; push the last added product into Retargeting */
                for ($i = count($data['wishlist']) - 1; $i >= 0; $i--) {
                    $product_id_in_wishlist = $data['wishlist'][$i] ;
                    break;
                }

                $data['addToWishlist'] = "
                                            var _ra = _ra || {};
                                            _ra.addToWishlistInfo = {
                                                                    'product_id': {$product_id_in_wishlist}
                                                                    };

                                            if (_ra.ready !== undefined) {
                                                _ra.addToWishlist(_ra.addToWishlistInfo.product_id);
                                            };
                                            ";

                /* We need to send the addToWishList event one time only. */
                $this->session->data['retargeting_wishlist_product_id'] = $product_id_in_wishlist;

                $data['js_output'] .= $data['addToWishlist'];
            }
        }
        /* --- END addToWishList  --- */



        /*
         * commentOnProduct ✓
         */
        if ($data['current_page'] === 'product/product') {
            $commentOnProduct_product_info = strtok($this->request->get['product_id'], '?');
            $data['commentOnProduct'] = "
                                        /* -- commentOnProduct -- */
                                        jQuery(document).ready(function($){
                                            if ($(\"{$data['retargeting_commentOnProduct']}\").length > 0) {
                                                $(\"{$data['retargeting_commentOnProduct']}\").click(function(){
                                                    _ra.commentOnProduct({$commentOnProduct_product_info}, function() {console.log('commentOnProduct FIRED')});
                                                });
                                            }
                                        });
                                        ";

            $data['js_output'] .= $data['commentOnProduct'];
        }
        /* --- END commentOnProduct  --- */

/*
         * addToCart [v1 - class/id listener] ✓
         */
        if ($data['current_page'] === 'product/product') {
            $mouseOverAddToCart_product_id = strtok($this->request->get['product_id'], '?');
            $mouseOverAddToCart_product_info = $this->model_catalog_product->getProduct($mouseOverAddToCart_product_id);
            $mouseOverAddToCart_product_promo = isset($mouseOverAddToCart_product_info['promo']) ? : 0;
            $data['mouseOverAddToCart'] = "
                                                /* -- addToCart -- */
                                                jQuery(document).ready(function($){
                                                    if ($(\"{$data['retargeting_addToCart']}\").length > 0) {  
                                                        /* -- addToCart -- */
                                                        $(\"{$data['retargeting_addToCart']}\").click(function(){
                                                            _ra.addToCart({$mouseOverAddToCart_product_id}, ".(($product_details['quantity'] > 0) ? 1 : 0).", false, function(){console.log('addToCart FIRED!')});
                                                        });
                                                    }
                                                });
                                                ";
            $data['js_output'] .= $data['mouseOverAddToCart'];
        }
        /* --- END mouseOverAddToCart & addToCart[v1]  --- */


        /*
         * visitHelpPage ✓
         */
        if ($data['current_page'] === 'information/information') {
            $data['visitHelpPage'] = "
                                        /* -- visitHelpPage -- */
                                        var _ra = _ra || {};
                                        _ra.visitHelpPageInfo = {'visit' : true};
                                        if (_ra.ready !== undefined) {
                                            _ra.visitHelpPage();
                                        };
                                    ";
            $data['js_output'] .= $data['visitHelpPage'];
        }
        /* --- END visitHelpPage  --- */



        /*
         * checkoutIds ✓
         */

        $checkout_modules = array('checkout/checkout', 'checkout/simplecheckout', 'checkout/ajaxquickcheckout', 'checkout/ajaxcheckout', 'checkout/quickcheckout', 'checkout/onepagecheckout', 'checkout/cart', 'quickcheckout/cart', 'quickcheckout/checkout');
        if(in_array($data['current_page'], $checkout_modules) && $this->cart->hasProducts() > 0) {
            $cart_products = $this->cart->getProducts(); // Use this instead of session
            $data['checkoutIds'] = "
                                        /* -- checkoutIds -- */
                                        var _ra = _ra || {};
                                        _ra.checkoutIdsInfo = [
                                    ";

            $i_products = count($cart_products);
            foreach ($cart_products as $item => $detail) {
                $i_products--;
                $data['checkoutIds'] .= ($i_products > 0) ? $detail['product_id'] . "," : $detail['product_id'];
            }

            $data['checkoutIds'] .= "
                                            ];
                                            ";
            $data['checkoutIds'] .= "
                                            if (_ra.ready !== undefined) {
                                                _ra.checkoutIds(_ra.checkoutIdsInfo);
                                            };
                                            ";

            $data['js_output'] .= $data['checkoutIds'];
        }
        /* --- END checkoutIds  --- */



        /*
         * saveOrder ✓
         * 
         * via pre.order.add event
         */

        if ($data['current_page'] === 'checkout/success') {
            /*if (empty($this->session->data['retargeting_post_order_add'])) {
                if(isset($_COOKIE['retargeting_save_order'])) {
                    $this->session->data['retargeting_post_order_add'] = $_COOKIE['retargeting_save_order'];
                    setcookie('retargeting_save_order', null, time()-3600);
                }
            }*/

          if ( (isset($this->session->data['retargeting_post_order_add']) && !empty($this->session->data['retargeting_post_order_add']) ) ) {
              
              $data['order_id'] = $this->session->data['retargeting_post_order_add'];
              $data['order_data'] = $this->model_checkout_order->getOrder($data['order_id']);

              $order_no = $data['order_data']['order_id'];
              $lastname = $data['order_data']['lastname'];
              $firstname = $data['order_data']['firstname'];
              $email = $data['order_data']['email'];
              $phone = $data['order_data']['telephone'];
              $state = $data['order_data']['shipping_country'];
              $city = $data['order_data']['shipping_city'];
              $address = $data['order_data']['shipping_address_1'];

              $discount_code = isset($this->session->data['retargeting_discount_code']) ? $this->session->data['retargeting_discount_code'] : 0;
              $total_discount_value = 0;
              $shipping_value = 0;
              $total_order_value = $this->currency->format(
                  $data['order_data']['total'],
                  $data['order_data']['currency_code'],
                  $data['order_data']['currency_value'],
                  false
              );

              // Based on order id, grab the ordered products
              $order_product_query = $this->db->query("SELECT * FROM " . DB_PREFIX . "order_product WHERE order_id = '" . (int)$data['order_id'] . "'");
              $data['order_product_query'] = $order_product_query;

              $data['saveOrder'] = "
                                          var _ra = _ra || {};
                                          _ra.saveOrderInfo = {
                                              'order_no': {$order_no},
                                              'lastname': '{$lastname}',
                                              'firstname': '{$firstname}',
                                              'email': '{$email}',
                                              'phone': '{$phone}',
                                              'state': '{$state}',
                                              'city': '{$city}',
                                              'address': '{$address}',
                                              'discount_code': '{$discount_code}',
                                              'discount': {$total_discount_value},
                                              'shipping': {$shipping_value},
                                              'total': {$total_order_value}
                                          };
                                          ";

              /* -------------------------------------- */
              $data['saveOrder'] .= "_ra.saveOrderProducts = [";
              for ($i = count($order_product_query->rows) - 1; $i >= 0; $i--) {
                $product_price = $this->currency->format(
                    $order_product_query->rows[$i]['price'] + (isset($order_product_query->rows[$i]['tax']) ? $order_product_query->rows[$i]['tax'] : 0),
                    $data['order_data']['currency_code'],
                    $data['order_data']['currency_value'],
                    false
                );
                  if ($i == 0) {
                      $data['saveOrder'] .= "{
                                                  'id': {$order_product_query->rows[$i]['product_id']},
                                                  'quantity': {$order_product_query->rows[$i]['quantity']},
                                                  'price': {$product_price},
                                                  'variation_code': ''
                                                  }";
                      break;
                  }
                  $data['saveOrder'] .= "{
                                              'id': {$order_product_query->rows[$i]['product_id']},
                                              'quantity': {$order_product_query->rows[$i]['quantity']},
                                              'price': {$product_price},
                                              'variation_code': ''
                                              },";
              }
              $data['saveOrder'] .= "];";
              /* -------------------------------------- */

              $data['saveOrder'] .= "
                                          if( _ra.ready !== undefined ) {
                                              _ra.saveOrder(_ra.saveOrderInfo, _ra.saveOrderProducts);
                                          }";
              $data['js_output'] .= $data['saveOrder'];
              
              /*
              * REST API Save Order
              */

              $apiKey = $this->config->get('retargeting_apikey');
              $apiKey = $this->config->get('retargeting_token');

              if($apiKey && $apiKey != ''){
                  $orderInfo = array(
                      'order_no' => $order_no,
                      'lastname' => $lastname,
                      'firstname' => $firstname,
                      'email' => $email,
                      'phone' => $phone,
                      'state' => $state,
                      'city' => $city,
                      'address' => $address,
                      'discount_code' => $discount_code,
                      'discount' => $total_discount_value,
                      'shipping' => $shipping_value,
                      'rebates'   =>  0,
                      'fees'      =>  0,
                      'total' => $total_order_value
                  );

                  $orderProducts = array();

                  foreach($order_product_query->rows as $orderedProduct) {
                      $orderProducts[] = array(
                          'id' => $orderedProduct['product_id'],
                          'quantity'=> $orderedProduct['quantity'],
                          'price'=> $orderedProduct['price'],
                          'variation_code'=> ''
                      );
                  }

                  $orderClient = new Retargeting_REST_API_Client($apiKey);
                  $orderClient->setResponseFormat("json");
                  $orderClient->setDecoding(false);
                  $response = $orderClient->order->save($orderInfo,$orderProducts);
              }
              
              unset($this->session->data['retargeting_post_order_add']);
          }
        }  

        /* ---------------------------------------------------------------------------------------------------------------------
         * Set the template path for our module & load the View
         * ---------------------------------------------------------------------------------------------------------------------
         */
        if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/module/retargeting.tpl')) {
            return $this->load->view($this->config->get('config_template') . '/module/retargeting.tpl', $data);
        } else {
            return $this->load->view('/module/retargeting.tpl', $data);
        }
    }


    
    /* ---------------------------------------------------------------------------------------------------------------------
     * Event: pre.order.add
     * 
     * Called: After the order has been launched && before unset($data)
     * Used for: saveOrder js
     * Returns: (array)$data
     * ---------------------------------------------------------------------------------------------------------------------
     */
    
    public function pre_order_add($data) {
        $this->session->data['retargeting_pre_order_add'] = $data;
    }



    /* ---------------------------------------------------------------------------------------------------------------------
     * Event: post.order.add
     * 
     * Called: After the order has been launched
     * Returns: (int) $order_id
     * Used for: saveOrder js
     * ---------------------------------------------------------------------------------------------------------------------
     */
    public function post_order_add($route, $output, $order_id, $order_status_id) {
        $this->session->data['retargeting_post_order_add'] = $order_id;
    }
}
