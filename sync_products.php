<?php
/*
    Syncroniza los productos, categorías, atributos e imagenes del catalogo completo.

    1- Busca atributos modificados y/o nuevos y ejecuta la sincronización. 
    Para atributos talle, color, precio.

    2- Busca categorías modificadas y/o nuevas y lo pasa al catalogo.

    3- Crea los productos simples, filtrando el excel por Talle = TU o sin Talle.

    4- Crea/Modifica los productos configurables por talle y/o color.

    5- Sincroniza las imagenes.
 */

define('ATTRIBUTES_DEFAULTS', array(
    'ftp-path' => 'ecommerce/linea_web/Urban',
));

class ftp 
{ 
    public $conn; 

    public function __construct($url){ 
        $this->conn = ftp_connect($url); 
    } 
    
    public function __call($func, $a){ 
        if(strstr($func, 'ftp_') !== false && function_exists($func))
        { 
            array_unshift($a, $this->conn); 
            return call_user_func_array($func, $a); 
        } 
        else
        { 
            // replace with your own error handler. 
            die("$func is not a valid FTP function"); 
        } 
    } 
} 

/**
 * Commands Utils for Magento
 **/
class CommandUtilMagento
{

    var $csv_array_header = [];
    var $csv_array_data = [];
    var $csv_grouped_array_data = [];

    var $_cached_category = []; // "category/subcategory" => ID
    var $_cached_attribute = []; // "attricube_code" => "attribute" => ID

    var $row_sku = 'sku';
    var $row_product_id = 'producto';
    var $row_name = 'descripcion'; 
    var $row_description = 'descripcion'; 

    var $row_attr_cod_color = 'cod_fam_col'; 
    var $row_attr_color = 'fam_color'; 
    var $row_attr_size = 'talle'; 
    var $row_attr_manufacture = 'marca';
    var $row_attr_source = 'origen'; 
    var $row_attr_season = 'temporada';
    var $row_attr_gender = 'genero'; 

    var $row_category = 'linea';
    var $row_subcategory = 'familia';
    var $row_price = 'precio_vtas';


    function __construct()
    {
        boostrap();
    }


    public function init()
    {
        $this->getMenu();
    }


    public function syncAttributes()
    {
        // Sincroniza los atributos.
        echo "syncAttributes";
    }


    public function syncCategories()
    {
        // Sincroniza las categorías.
        echo "syncCategories\r\n";

        $col_category = $this->row_category;
        $col_subcategory = $this->row_subcategory;

        if ( count($this->csv_array_header) === 2 or 
            ( !array_key_exists($this->row_category, $this->csv_array_header) and 
            !array_key_exists($this->row_subcategory, $this->csv_array_header) ) 
        ) {

            $col_category = 0;
            $col_subcategory = 1;
        }

        _log(var_export($this->csv_array_header, true ));

        foreach ($this->csv_array_data as $row) {
            $this->getOrCreateCategories(array($row[$col_category], $row[$col_subcategory]), null, $this->opt_commit);
        }

    }


    public function syncProducts()
    {
        // Ejecuta el proceso de mapeo para poductos

        // Checkea que todas las key requeridas existan. (ref: http://stackoverflow.com/questions/13169588/how-to-check-if-multiple-array-keys-exists)

        $required = array(
            $this->row_sku, 
            $this->row_product_id, 
            $this->row_description, 
            $this->row_category,
            $this->row_subcategory,
            $this->row_price);

        if (count(array_intersect($required, $this->csv_array_header)) !== count($required)) {
            _log("Error el archivo no corresponde al formato de columnas " . implode(', ', $required));

            _log("Requeridas: \r\n" . var_export($required, true));
            _log("Columnas del CSV: \r\n" . var_export($this->csv_array_header, true));

            exit(0);
        }

        $this->csv_grouped_array_data = $this->groupArray($this->csv_array_data, $this->row_product_id);

        _log("Hay " . count($this->csv_grouped_array_data) . " grupos de productos");

        $_total_config = 0;
        $_total_simple = 0;

        foreach ($this->csv_grouped_array_data as $key => $val) {
            if(count($val) > 1) $_total_config++;
            else $_total_simple++;
        }

        _log("Hay " . $_total_simple . " productos simples");
        _log("Hay " . $_total_config . " productos configurables");

    }

    public function syncSimpleProducts()
    {
        // Sincroniza solo los productos simples.
        echo "syncSimpleProducts\r\n";

        foreach( $this->csv_grouped_array_data as $key => $products ) {
            if(count($products) == 1) {
                $row = $products[0];

                _log("Preparando producto {sku} {descripcion}", $row);

                $this->createProduct(
                    $row[$this->row_sku], 
                    $row[$this->row_product_id], 
                    ucfirst(strtolower($row[$this->row_name])), 
                    ucfirst(strtolower($row[$this->row_description])), 
                    $row[$this->row_attr_cod_color], 
                    $row[$this->row_attr_color], 
                    $row[$this->row_attr_size], 
                    $row[$this->row_attr_manufacture], 
                    $row[$this->row_attr_source], 
                    $row[$this->row_attr_season], 
                    $row[$this->row_attr_gender], 
                    $row[$this->row_category],
                    $row[$this->row_subcategory], 
                    $row[$this->row_price],
                    ucfirst(strtolower($row[$this->row_category]))
                );
            }
        }

    }


    public function syncConfigurableProducts()
    {
        // Sincroniza los productos configurables.
        echo "syncConfigurableProducts\r\n";


        // resuelve una sola vez los atributos posibles
        $array_attr = array('color', 'size', 'number');
        $array_attribues = [];
        foreach($array_attr as $code) {
            $attr = Mage::getModel('catalog/resource_eav_attribute')->loadByCode('catalog_product', $code);
            $array_attribues[$code] = $attr;
        }

        foreach( $this->csv_grouped_array_data as $key => $products ) {
            if(count($products) > 1) {

                $row = $products[0];

                // crea el primer producto como configurable
                _log("Crea el producto como configurable {producto} {talle} {color}", $row);

                $sku = "CONFIG-" . $row[$this->row_product_id];

                $configProduct = $this->createProduct(
                    $sku, // crea un SKU propio
                    $row[$this->row_product_id], 
                    ucfirst(strtolower($row[$this->row_name])), 
                    ucfirst(strtolower($row[$this->row_description])), 
                    $row[$this->row_attr_cod_color], 
                    $row[$this->row_attr_color], 
                    $row[$this->row_attr_size], 
                    $row[$this->row_attr_manufacture], 
                    $row[$this->row_attr_source], 
                    $row[$this->row_attr_season], 
                    $row[$this->row_attr_gender], 
                    $row[$this->row_category],
                    $row[$this->row_subcategory], 
                    $row[$this->row_price],
                    ucfirst(strtolower($row[$this->row_category])),
                    Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE,
                    Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH,
                    false); // NO COMMIT 


                // Configuracion de atributos
                // indumentaria -> set indumentaria (color, size)
                // calzado -> set calzado (color, number)


                if (strtolower($row[$this->row_category]) == 'indumentaria') {
                    $_attributes = array(
                        $array_attribues['color']->getId() => $array_attribues['color'], 
                        $array_attribues['size']->getId() => $array_attribues['size']
                    );
                } elseif (strtolower($row[$this->row_category]) == 'calzado') {
                    $_attributes = array(
                        $array_attribues['color']->getId() => $array_attribues['color'], 
                        $array_attribues['number']->getId() => $array_attribues['number']
                    );

                } else {
                    $_attributes = array(
                        $array_attribues['color']->getId() => $array_attribues['color']
                    );
                }
                
                $configurableProductsData = array();
                
                _log("Crea los " . (count($products) - 1) . " productos asociados al configurable"); 
                foreach(array_slice($products, 1) as $row) {
                    // Create product instances
                    $simpleProduct = $this->createProduct(
                        $row[$this->row_sku], 
                        $row[$this->row_product_id],
                        $row[$this->row_attr_size] . " - " . $row[$this->row_attr_color] . " - " . ucfirst(strtolower($row[$this->row_name])), // crea un titulo propio para identificarlo
                        ucfirst(strtolower($row[$this->row_description])), 
                        $row[$this->row_attr_cod_color], 
                        $row[$this->row_attr_color], 
                        $row[$this->row_attr_size], 
                        $row[$this->row_attr_manufacture], 
                        $row[$this->row_attr_source], 
                        $row[$this->row_attr_season], 
                        $row[$this->row_attr_gender], 
                        $row[$this->row_category],
                        $row[$this->row_subcategory], 
                        $row[$this->row_price],
                        ucfirst(strtolower($row[$this->row_category])),
                        Mage_Catalog_Model_Product_Type::TYPE_SIMPLE,
                        Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE,
                        true); 

                    $associatedArrayAttribues = [];
                    foreach($_attributes as $id => $_attribute) {
                        $associatedArrayAttribues[] = array(
                            'label'         => $_attribute->getLabel(),
                            'attribute_id'  => $id,
                            'value_index'   => (int) $simpleProduct->getColor(),
                            'is_percent'    => 0,
                            'pricing_value' => $simpleProduct->getPrice()
                        );
                    }

                    $configurableProductsData[$simpleProduct->getId()] = $associatedArrayAttribues;
                    
                    //['920'] = id of a simple product associated with this configurable                        
   
                    //array(
                    //    '0' => array(
                    //        'label'         => $simpleProduct->getAttributeText('color'),
                    //        'attribute_id'  => $_attributes[0],
                    //        'value_index'   => (int) $simpleProduct->getColor(),
                    //        'is_percent'    => 0,
                    //        'pricing_value' => $simpleProduct->getPrice()
                    //    ),
                    //    '1' => array(
                    //        'label'         => $simpleProduct->getAttributeText('size'),
                    //        'attribute_id'  => $_attributes[1],
                    //        'value_index'   => (int) $simpleProduct->getSize(),
                    //        'is_percent'    => 0,
                    //        'pricing_value' => $simpleProduct->getPrice()
                    //    )
                    //);

                }

                //_log("_attributes: " . var_export($_attributes, true));
                $configProduct->getTypeInstance()->setUsedProductAttributeIds(array_keys($_attributes)); //attribute ID of attribute 'color' in my store
                $configurableAttributesData = $configProduct->getTypeInstance()->getConfigurableAttributesAsArray();

                $configProduct->setConfigurableAttributesData($configurableAttributesData);

                $configProduct->setConfigurableProductsData($configurableProductsData);
                $configProduct->setCanSaveConfigurableAttributes(true);
                $configProduct->setCanSaveCustomOptions(true);

                _log("configurableAttributesData: " . var_export($configurableAttributesData, true));
                _log("configurableProductsData: " . var_export($configurableProductsData, true));

                try {
                    $configProduct->save();
                } catch(Exception $e) {
                    
                    _log("ERROR al guarar el producto configurable\n" . $e->getMessage());

                    try {
                        _log("Try with getResource -> save");
                        $configProduct->getResource()->save($configProduct);
                    }
                    catch(Exception $e) {
                        _log("ERROR al guarar el producto configurable desde el resocurce\n" . $e->getMessage());
                    }

                }

                _log("\033[32mProducto configurable creado " . $configProduct->getId() . "\033[0m");

            }
        }

    }


    public function syncImages()
    {
        // Sincroniza las imagenes que se asociarán a los productos.
        echo "syncImages";
        $ftp = new ftp($this->opt_ftp['server']);
        $ftp->ftp_login($this->opt_ftp['user'], $this->opt_ftp['pass']);
        $ftp_list = $ftp->ftp_nlist($this->opt_ftp['path']);

        _log(var_export($ftp_list, 1));

        // http://stackoverflow.com/questions/8456954/magento-programmatically-add-product-image?answertab=votes#tab-top

    }

    public function reindex()
    {
        // reindexa el catalogo
        _log("Reindexando catalogo de Productos...");
        
        /* @var $indexCollection Mage_Index_Model_Resource_Process_Collection */
        $indexCollection = Mage::getModel('index/process')->getCollection();
        foreach ($indexCollection as $index) {
            /* @var $index Mage_Index_Model_Process */
            $index->reindexAll();
        }
        
        _log("\033[32mReindexado completo\033[0m");
    }


    public function deleteAllProducts()
    {
        // borra todos los productos
        set_time_limit(3600);

        umask(0);
        Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);

        $products = Mage :: getResourceModel('catalog/product_collection')
            ->setStoreId(STORE_ID)->getAllIds();

        if(is_array($products))
        {
            foreach ($products as $key => $productId)
            {
                try
                {
                    $product = Mage::getModel('catalog/product')->load($productId)->delete();

                } 
                catch (Exception $e) 
                {
                    _log("Unable to delete product with ID: ". $productId);
                }
            }
        }
    }


    public function sync()
    {
        // Sync all products from CSV

        //var_export(prompt("Cargar todos los productos?"));

        $result = prompt("Cargar todos los productos, categorías y atributos?", array(
            "1" => array("Productos Simples", "simples"),
            "2" => array("Productos Configurables", "configurables"),
            "3" => array("Actualizar Imágenes de Productos", "imagenes"),
            "4" => array("Solo las Categorías", "categorias"),
            "5" => array("Solo los Atributos", "atributos"),
            "6" => array("Todos los productos (precaución experimental)", "todos"),
            "9" => array("BORRAR TODOS LOS PRODUCTOS !!!", "delete_all"),
        ));

        switch ($result)
        {
        case '1':
            $this->syncProducts();
            $this->syncSimpleProducts();
            $this->reindex();
            break;

        case '2':
            $this->syncProducts();
            $this->syncConfigurableProducts();
            $this->reindex();
            break;

        case '3':
            $this->syncImages();
            break;

        case '4':
            $this->syncCategories();
            break;

        case '5':
            $this->syncAttributes();
            break;

        case '6':
            $this->syncProducts();
            $this->syncSimpleProducts();
            echo "\r\n";
            $this->syncConfigurableProducts();
            $this->reindex();
            break;

        case '9':

            $result = prompt("SEGURO QUE QUERE BORRAR TODOS LOS PRODUCTOS?");
            if ( $result === true ) $this->deleteAllProducts();
            else echo "\r\nCAGON!\r\n";
            break;
        }

        echo "\r\n";

    }



    public function loadFileData($file_data, $flat = 0)
    {
        // Carga el archivo en un array de rows con key -> val (columna -> datos)

        //$csv = array_map("str_getcsv", file($file_data, "r")); 
        //$header = array_shift($csv); 

        //$col = array_search("Value", $header); 

        //echo var_export($col, True);

        //foreach ($csv as $row) {      
        //    $array[] = $row[$col]; 
        //}

        // ES un xlsx ?
        $extension = end(explode('.', $file_data));

        if ($extension == 'xls' || $extension == 'xlsx') 
        {
            require_once('.parse_xlsx.php');
            $array_data = parse_xlsx_as_array($file_data);
            $this->csv_array_header = array_map("mb_strtolower", array_keys($array_data[0]));
            $this->csv_array_data = $array_data;
        
        }
        elseif ($extension == 'cvs') 
        {
            $fila = 0;

            if (($gestor = fopen($file_data, "r")) !== false) {
                while (($row = fgetcsv($gestor, 1000, ";")) !== false) {
                    // la primer fila tiene los encabezados, la salto
                    if ( $fila == 0 ) {
                        $this->csv_array_header = array_map("mb_strtolower", $row);
                        $fila++;
                        continue;
                    }
                    if ($flat) {
                        $this->csv_array_data[] = $row;
                    } else {
                        $this->csv_array_data[] = array_combine($this->csv_array_header, $row);
                    }

                }

            }

        }

        _log("header:\r\n" . var_export($this->csv_array_header, true));
        _log(count($this->csv_array_data) . " Artículos en CSV"); 

    }

    public function groupArray($array, $arg)
    {
        // Agrupa un array por un key y devuelve un nuevo array
        // $array: el array
        // $arg: el key o val a buscar
        // @return array

        $grouparr = array();

        foreach ($array as $key => $val) {
            //_log("groupArray: " . $key . " => " . $val[$arg]);

            if (array_key_exists($val[$arg], $grouparr)) {
                // existe en el array ese key, asocia un nuevo item
                $grouparr[$val[$arg]][] = $val;
            } else {
                $grouparr[$val[$arg]] = array($val);
            }

        }

        return $grouparr;
    }


    // Methods

    public function createProduct($sku, $cod_product, $name, $description, 
        $cod_color, $color, $size, $manufacturer, $source, $season, $gender, 
        $category, $subcategory, $price, $attribute_set=null, 
        $product_type=null, $product_visibility=null, $commit=true) { 
        //
        // Create a new product
        //

        // if first argument is an array try to convert to Product Model Object
        $product_type = $product_type ? $product_type : DEFAULT_PRODUCT_TYPE;

        // add category if does not exist
        _log("\033[33mAdd category if does not exist\033[0m");
        $array_categories = $this->getOrCreateCategories( array($category, $subcategory) );

        $attr_color = '';
        $attr_size = '';
        $attr_size_l = '';

        if ($product_type !== Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {

            // add attributes
            _log("\033[33mAdd attribute color: " . $color . "\033[0m");
            $attr_color = $this->getOrCreateAttributes('color', $color, $color);

            // set size attributes
            if ( !is_numeric( $size ) && trim(strtolower($size)) != 'tu') {
                _log("\033[33mAdd attribute size_letter: " . $size . "\033[0m");
                $attr_size_l = $this->getOrCreateAttributes('size_letter', 'Size Letter', $size);
            } 
            else {
                _log("\033[33mAdd attribute size: " . $size . "\033[0m");
                $attr_size = $this->getOrCreateAttributes('size', 'Size', $size);
            }
        }

        _log("\033[33mAdd attribute cod_product: " .$cod_product . "\033[0m");
        $attr_cod_product = $this->getOrCreateAttributes('cod_product', 'cod_product', $cod_product, array(
            'frontend_input' => 'text',
        ));

        $product_model = Mage::getModel('catalog/product');

        $cost = null;

        if (!$attribute_set) $attribute_set = 'Default';
        if (! $attribute_set_id = $this->getAttributeSetByName($attribute_set) ) {
            $attribute_set_id = DEFAULT_ATTRIBUTES;
        } 

        _log("\033[33mAdd attribute manufacturer: " . $manufacturer . "\033[0m");
        $attr_manufacturer = $this->getOrCreateAttributes('manufacturer', $manufacturer, $manufacturer);

        //if ($product_type == Mage_Catalog_Model_Product_Type::TYPE_SIMPLE) {
        //    $product_visibility = DEFAULT_PRODUCT_VISIBILITY;
        //} elseif ($product_type == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {
        //    $product_visibility = DEFAULT_PRODUCT_VISIBILITY;
        //} else {
        //    $product_visibility = Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE;
        //}

        $product_visibility = $product_visibility === null ? DEFAULT_PRODUCT_VISIBILITY : $product_visibility;

        try {

            _log("Try to create a new product.\n"
                ."STORE_ID: {store_id}\n"
                ."SKU: {sku}\n"
                ."Name: {name}\n"
                ."Product type: {type}\n"
                ."Attribute Set: {attribute_set}\n"
                ."Attribute Set ID: {attribute_set_id}\n"
                ."Color ID: {color}\n"
                ."Manufacturer ID: {manufacturer}\n"
                ."Size: {size}\n"
                ."Size Letter: {size_l}\n"
                ,
                array(
                    "store_id" => STORE_ID,
                    "sku" => $sku, 
                    "name" => $name,
                    "type" => $product_type,
                    "attribute_set" => $attribute_set,
                    "attribute_set_id" => $attribute_set_id,
                    "color" => $attr_color, 
                    "manufacturer" => $attr_manufacturer, 
                    "size" => $attr_size, 
                    "size_l" => $attr_size_l, 
                )
            );

            //echo "color: " . $attr_color . "\n";
            //echo "manufacturer: " . $attr_manufacturer . "\n";

            $product_model
                ->setStoreId(STORE_ID)                      // you can set data in store scope
                ->setWebsiteIds(array(1))                   // website ID the product is assigned to, as an array
                ->setAttributeSetId($attribute_set_id)      // ID of a attribute set named 'default'
                ->setTypeId($product_type)                  // product type
                // ->setCreatedAt(strtotime('now'))         // product creation time
                //->setUpdatedAt(strtotime('now'))          // product update time

                ->setName($name)                            // product name
                ->setDescription($description)              // Long product description
                ->setShortDescription($description)         // Short product description
                ->setSku($sku)                              // SKU
                ->setWeight(0.0000)                         // weight
                ->setStatus(DEFAULT_PRODUCT_STATUS)         // product status (1 - enabled, 2 - disabled)
                ->setVisibility($product_visibility)        

                ->setPrice($price)                          // Price 2 decimal
                ->setTaxClassId(4)                          // tax class (0 - none, 1 - default, 2 - taxable, 4 - shipping)

                ->setStockData(
                    array(
                        'use_config_manage_stock' => 0,     // 'Use config settings' checkbox
                        'manage_stock' => 1,                // Manage stock
                        //'min_sale_qty' => 1,                // Minimum Qty Allowed in Shopping Cart
                        //'max_sale_qty' => 2,                // Maximum Qty Allowed in Shopping Cart
                        'is_in_stock' => 1,                 // Stock Availability
                        'qty' => 999                        // qty
                    )
                )

                ->setCategoryIds($array_categories)         // Assign product to categories

                ->setManufacturer($attr_manufacturer)       // Manufacturer id

                ->setCodProduct($attr_cod_product)          // Cod Product internal reference
                //->setNewsFromDate(strtotime('now'))         // Product set as new from
                //->setNewsToDate()                           // Product set as new to
                //->setCountryOfManufacture('AF')             // Country of manufacture (2-letter country code)

                ->setCost(( $cost ? $cost : $price ))                  // Cost 2 decimal
                //->setSpecialPrice($price)                   // Special price in form 11.22
                //->setSpecialFromDate(strtotime('now'))      // Special price from (MM-DD-YYYY)
                //->setSpecialToDate()                        // Special price to (MM-DD-YYYY)

                // VALIDATE?
                ->setMsrpEnabled(1)                         // Enable MAP
                ->setMsrpDisplayActualPriceType(1)          // Display actual price (1 - on gesture, 2 - in cart, 3 - before order confirmation, 4 - use config)
                ->setMsrp($price)                           // Manufacturer's Suggested Retail Price

                // Meta SEO title, keywords and description.
                ->setMetaTitle($name)                    // SEO Title
                ->setMetaKeyword($description)              // SEO Keywords  
                ->setMetaDescription($description)          // SEO Desacription

                //->setMediaGallery(
                //    array(
                //        'images' => array(), 
                //        'values' => array()
                //    )
                //)                                           // Media gallery initialization

                //->addImageToMediaGallery(
                //    'media/catalog/product/1/0/10243-1.png', 
                //    array(
                //        'image',
                //        'thumbnail',
                //        'small_image'
                //    ), false, false)                        // Assigning image, thumb and small image to media gallery

                ; // close product


            if ($product_type !== Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE) {

                $product_model->setColor($attr_color);            // Color
                if ($attr_cod_product) {
                    $product_model->setCodProduct($cod_product);
                }

                if ($attr_size) {
                    _log("try to add size " . $attr_size); 
                    $product_model->setSize($attr_size);
                } elseif ($attr_size_l) {
                    _log("try to add size_l " . $attr_size_l); 
                    $product_model->setSizeLetter($attr_size_l);
                }

            }

            if($commit) {
                try {
                    $product_model->save();
                } catch(Exception $e) {
                    _log("ERROR product_model\n" . $e->getMessage());
                    try {
                        _log("Try with getResource -> save");
                        $product_model->getResource()->save($product_model);
                    }
                    catch(Exception $e) {
                        _log("ERROR product_model resource\n" . $e->getMessage());
                    }
                }
            }

            _log("\033[32mProducto " . ($product_type == Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE ? 'Configurable' : 'Simple') . " creado " . $product_model->getId() . "\033[0m");
            return $product_model;
        } 
        catch(Exception $e) {
            _log("ERROR product_model\n" . $e->getMessage());
        }

    }


    public function getOrCreateAttributes($attr_code, $attr_label, $attr_value = '', $attr_options = -1) {
        //
        //  Get or create new attribute and options of attribute (if exists)
        //

        $attr_code = strtolower(trim($attr_code));
        $attr_label = ucfirst(strtolower(trim($attr_label)));

        if ( ! $attr_value == '' && ! is_array( $attr_value ) ) {
            $attr_value = array(ucfirst(strtolower(trim($attr_value))));
        }

        //$total_options = count($attr_value);
        $attr_model = Mage::getModel('catalog/resource_eav_attribute'); // load model

        // carga el attr

        // existe e cache?
        if ( array_key_exists($attr_code, $this->_cached_attribute) ) {
            $attribute = $this->_cached_attribute[$attr_code];
            _log("\033[37mLoad cached attribute\033[0m");
        } elseif ( $attr = $attr_model->loadByCode('catalog_product', $attr_code) ) {

            // Add new options for an exsiting attribute
            _log("El attributo con ese code {code} existe\n", array('code' => $attr_code));

            // Get all options of an attribute
            $attribute = Mage::getSingleton('eav/config')
                ->getAttribute(Mage_Catalog_Model_Product::ENTITY, $attr_code);
            
            $this->_cached_attribute[$attr_code] = $attribute;
        }
        else {
            _log("!!\tEl attributo con code \"{code}\" no existe\n", array('code' => $attr_code));
            $id = $this->createAttribute($attr_code, $attr_label, $attr_options, -1, -1, $attr_value);
            return $id;
        }

        $_options = array();
        if ($attribute->usesSource()) {
            $_options = $attribute->getSource()->getAllOptions(false);
        } 
        else {
            _log("No tiene opciones, usesSource Code: {code}, ID: {id}\n", array('code'=>$attr_code, 'id'=> $attribute->getID()));
            // Crea si no existe el valor para el attr.
            $id = $this->createAttribute($attr_code, $attr_label, $attr_options, -1, -1, $attr_value);
        }

        $total_options = count($_options);
        
        _log("\033[37mItera sobre las opciones " . $total_options . " buscando para " . $attr_value[0] . "\033[0m");

        if ( array_key_exists($attr_code . "-" . $attr_value[0], $this->_cached_attribute) ) {
            $id = $this->_cached_attribute[$attr_code . "-" . $attr_value[0]];
        }
        elseif ($index_key = array_search($attr_value[0], array_column($_options, 'label'))) {
            //_log("Attribute value exists, assign it to the product: " . $index_key . " -> " . var_export($_options[$index_key], true));
            $id = $_options[$index_key]['value'];
            $this->_cached_attribute[$attr_code . "-" . $attr_value[0]] = $id;
        } else {
            $id = $this->createAttribute($attr_code, $attr_label, $attr_options, -1, -1, $attr_value);
            $this->_cached_attribute[$attr_code . "-" . $attr_value[0]] = $id;
        }

        //for($i = 0; $i < $total_options; $i++) {
        //    
        //    _log("\033[37m " . $i . " -> " . $_options[$i]['label'] . "\033[0m");

        //    if ( $_options[$i]['label'] == $attr_value[0] ) {
        //        _log("Attribute value exists, assign it to the product:\n" . $attr_value[0] . ": " . $_options[$i]['value']);
        //        $id = $_options[$i]['value'];
        //        break;
        //    } 
        //    else {
        //        $id = $this->createAttribute($attr_code, $attr_label, $attr_options, -1, -1, $attr_value);
        //    }
        //}

        return $id;

    }


    public function getAttributeSetByName($attributeSetName)
    {
        // 
        // Get an Attribute Set by name and return the ID
        //
        // @return int|false
        //

        $entityTypeId = Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId();
        $attributeSetId = Mage::getModel('eav/entity_attribute_set')
            ->getCollection()
            ->setEntityTypeFilter($entityTypeId)
            ->addFieldToFilter('attribute_set_name', $attributeSetName)
            ->getFirstItem()
            ->getAttributeSetId();

        return $attributeSetId;

    }


    public function createAttribute($attributeCode, $labelText = '', $values = -1, $productTypes = -1, $setInfo = -1, $options = -1) {
        //
        // Create an attribute.
        //
        // For reference, see Mage_Adminhtml_Catalog_Product_AttributeController::saveAction().
        //
        // @return int|false
        //  

        $attributeCode = trim($attributeCode);
        $labelText = trim($labelText);

        if($labelText == '' || $attributeCode == '')
        {
            _log("Can't import the attribute with an empty label or code.  LABEL=[$labelText]  CODE=[$attributeCode]");
            return false;
        }

        if($values === -1)
            $values = array();

        if($productTypes === -1)
            $productTypes = array();

        if($setInfo !== -1 && (isset($setInfo['SetID']) == false || isset($setInfo['GroupID']) == false))
        {
            _log("Please provide both the set-ID and the group-ID of the attribute-set if you'd like to subscribe to one.");
            return false;
        }

        //echo "Creating attribute [$labelText] with code [$attributeCode]."."\n";

        //>>>> Build the data structure that will define the attribute. See
        //     Mage_Adminhtml_Catalog_Product_AttributeController::saveAction().

        $data = array(
            'is_global'                     => '1',
            'frontend_input'                => 'select',
            'default_value_text'            => '',
            'default_value_yesno'           => '0',
            'default_value_date'            => '',
            'default_value_textarea'        => '',
            'is_unique'                     => '0',
            'is_required'                   => '0',
            'frontend_class'                => '',
            'is_searchable'                 => '1',
            'is_visible_in_advanced_search' => '1',
            'is_comparable'                 => '1',
            'is_used_for_promo_rules'       => '0',
            'is_html_allowed_on_front'      => '1',
            'is_visible_on_front'           => '0',
            'used_in_product_listing'       => '0',
            'used_for_sort_by'              => '0',
            'is_configurable'               => '0',
            'is_filterable'                 => '1',
            'is_filterable_in_search'       => '1',
            'backend_type'                  => 'varchar',
            'default_value'                 => '',
            'is_user_defined'               => '0',
            'is_visible'                    => '1',
            'is_used_for_price_rules'       => '0',
            'position'                      => '0',
            'is_wysiwyg_enabled'            => '0',
            'backend_model'                 => '',
            'attribute_model'               => '',
            'backend_table'                 => '',
            'frontend_model'                => '',
            'source_model'                  => '',
            'note'                          => '',
            'frontend_input_renderer'       => '',                      
        );

        // Now, overlay the incoming values on to the defaults.
        foreach($values as $key => $newValue)
            if(isset($data[$key]) == false)
            {
                _log("Attribute feature [$key] is not valid.");
                return false;
            }

            else
                $data[$key] = $newValue;

        // Valid product types: simple, grouped, configurable, virtual, bundle, downloadable, giftcard
        $data['apply_to']       = $productTypes;
        $data['attribute_code'] = $attributeCode;
        $data['frontend_label'] = $labelText;

        // Build the model.
        $model = Mage::getModel('catalog/resource_eav_attribute');

        $model->addData($data);

        if($setInfo !== -1)
        {
            $model->setAttributeSetId($setInfo['SetID']);
            $model->setAttributeGroupId($setInfo['GroupID']);
        }

        $entityTypeID = Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId();
        $model->setEntityTypeId($entityTypeID);
        $model->setIsUserDefined(1);

        // Save
        $_value_id = false;
        try
        {
            $model->save();
        }
        catch(Exception $ex)
        {
            //_log($ex->getMessage());
            if($ex->getMessage() == "Attribute with the same code already exists.") {
                if(is_array($options)){
                    foreach($options as $_opt){
                        $_value_id = $this->addAttributeValue($attributeCode, $_opt);
                    }

                } else {
                    _log("Attribute [$labelText] could not be saved: " . $ex->getMessage());
                    return false;
                }
            }
        }

        if(is_array($options)){
            foreach($options as $_opt){
                $_value_id = $this->addAttributeValue($attributeCode, $_opt);
            }
        }

        //try {
        //    // hack label
        //    echo "Add AttributeLabel (" . $model->getAttributeLabel() . ") -> " . $labelText . "\n";
        //    $model->setAttributeLabel($labelText);
        //    $model->save();
        //}
        //catch(Exception $ex) {
        //    echo $ex->getMessage()."\n";
        //}

        $id = $model->getId();

        //echo "Attribute [$labelText] has been saved as ID ($id).\n";

        // Asssign to attribute set.
        $eav_model = Mage::getModel('eav/entity_setup','core_setup');
        $eav_model->addAttributeToSet(
            'catalog_product', 'Default', 'General', $attributeCode
        ); //Default = attribute set, General = attribute group

        $_id = $_value_id ? $_value_id : $id;

        //_log("Attr ID: {id}\n", array('id' => $_id)); 
        return $_id;
    }


    public function addAttributeValue($arg_attribute, $arg_value) {
        $attribute_model        = Mage::getModel('eav/entity_attribute');

        $attribute_code         = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
        $attribute              = $attribute_model->load($attribute_code);

        if(!$this->attributeValueExists($arg_attribute, $arg_value))
        {
            $value['option'] = array($arg_value, $arg_value);
            $result = array('value' => $value);
            $attribute->setData('option',$result);
            $attribute->save();
        }

        $attribute_options_model= Mage::getModel('eav/entity_attribute_source_table') ;
        $attribute_table        = $attribute_options_model->setAttribute($attribute);
        $options                = $attribute_options_model->getAllOptions(false);

        foreach($options as $option)
        {
            if ($option['label'] == $arg_value)
            {
                return $option['value'];
            }
        }
        return false;
    }

    public function attributeValueExists($arg_attribute, $arg_value) {
        $attribute_model        = Mage::getModel('eav/entity_attribute');
        $attribute_options_model= Mage::getModel('eav/entity_attribute_source_table') ;

        $attribute_code         = $attribute_model->getIdByCode('catalog_product', $arg_attribute);
        $attribute              = $attribute_model->load($attribute_code);

        $attribute_table        = $attribute_options_model->setAttribute($attribute);
        $options                = $attribute_options_model->getAllOptions(false);

        foreach($options as $option)
        {
            if ($option['label'] == $arg_value)
            {
                return $option['value'];
            }
        }

        return false;
    }

    public function getOrCreateCategories($stringId, $parentId = null, $commit = true) {
        //
        //  Resolve categories from a string based categories splited by slash "/"
        //  if category does not exists try to create it.
        //

        //global PARENT_ID;

        $parentId = $parentId ? $parentId : PARENT_ID;

        if( ! is_array($stringId) ) {
            $_stringIds = split("/", $stringId);
        } 
        else {
            $_stringIds = $stringId;
        }

        $_arrayIds = array();

        for($i = 0; $i < count($_stringIds); $i++) {

            if($i == 0 || !$commit) {
                $_parentId = $parentId;
            }
            else {
                $_parentId = $_arrayIds[$i-1];
            }

            $_str_category = ucfirst(strtolower($_stringIds[$i]));

            // chequea en cache si no existe así no hace hit en la DB
            if ( ! array_key_exists($_parentId . "-" . $_str_category, $this->_cached_category) ) {

                if ( ! ( $_category = $this->_categoryExists($_str_category, $_parentId) ) ) {

                    _log("Category \"" . $_str_category . "\" does not exists, try to ceate it");

                    if ($commit) $_category = $this->_createCategory($_str_category, slugify($_str_category), $_parentId);

                } else {
                    _log("Category \"" . $_str_category . "\" exists, SKIP");
                }

                if ($commit) $_arrayIds[$i] = $_category->getId();

                // guarda en cache
                $this->_cached_category[$_parentId . "-" . $_str_category] = $_category->getId();

            } else {
                $_arrayIds[$i] = $this->_cached_category[$_parentId . "-" . $_str_category]; 
            }
        }

        return $_arrayIds;
    }

    public function _categoryExists($name, $parentId) {
        //
        // Check if category exists
        //

        $parentCategory = Mage::getModel('catalog/category')->load($parentId);
        $childCategory = Mage::getModel('catalog/category')->getCollection()
            ->addAttributeToFilter('is_active', true)
            ->addIdFilter($parentCategory->getChildren())
            ->addAttributeToFilter('name', $name)
            ->getFirstItem()    // Assuming your category names are unique ??
            ;

        if (null !== $childCategory->getId()) {
            _log("[SKIP] Category: " . $childCategory->getData('name') . " exists");
            return $childCategory;
        } else {
            _log("Category not found");
            return false;
        }

        return false;
    }

    public function _createCategory($name, $url, $parentId) {
        //
        //  Try to create a new Category
        //

        try {
            $category = Mage::getModel('catalog/category');
            $category->setName($name);
            $category->setUrlKey($url);
            $category->setIsActive(1);
            $category->setDisplayMode('PRODUCTS');
            $category->setIsAnchor(1); //for active anchor
            $category->setStoreId(Mage::app()->getStore()->getId());
            $parentCategory = Mage::getModel('catalog/category')->load($parentId);
            $category->setPath($parentCategory->getPath());
            $category->save();
            return $category;
        } catch(Exception $e) {
            print_r($e);
        }

        return false;
    }

    public function getMenu() 
    {
        // Options for command line
        $shortopts  = "";
        $shortopts .= "f:";     // path to csv file
        $shortopts .= "i::";    // path for images
        $shortopts .= "c";      // create product
        $shortopts .= "a";      // add category if not exists
        $shortopts .= "t";      // add attribute selector
        $shortopts .= "h";      // help

        $longopts  = array(
            "file:",            // path to csv file
            "images-path::",    // path for images
            "use-ftp::",        // use ftp
            "ftp-server:",      // ftp server
            "ftp-user:",        // ftp user
            "ftp-pass:",        // ftp pass
            "ftp-path:",        // ftp path
            "commit",           // create product
            "add-category",     // add category if not exists
            "add-attribute",    // add attribute selector
            "attribute-code",   // attribute code
            "attribute-label",  // attribute labels
            "attribute-values", // attribute values
            "help",             // help
        );

        $options = getopt($shortopts, $longopts);

        if (!$options || array_key_exists("h", $options) || array_key_exists("help", $options)) { 

            print("
Usage:

php sync_products.php [options] -f file.csv

    -h, --help                              This help
    -c, --commit                            Commit make changes permanent.
    -i, --images-path=path/to/images        Path for images
    
    --use-ftp,
        --ftp-server=server.com
        --ftp-user=user
        --ftp-pass=mypass
        --ftp-path=/route/to/path/
");
//    -a, --add-category                      Create [sub]category if not exists.
//    
//    -t, --add-attribute                     Create attribute
//        --attribute-code                    Code for new attribute (or update values)
//        --attribute-label                   Label for attribute
//        --attribute-options                 Options for attribute
//

            exit(1);

        } 

        // Prevalidate options

        $this->opt_ftp = array();

        $this->opt_commit = ( 
            getattr($options['c'], null) == null ? 
            getattr($options['commit'], false) : getattr($options['c'], false) 
        ); 

        $this->opt_images_path = ( 
            getattr($options['i'], null) == null ? 
            getattr($options['images-path'], '') : getattr($options['i'], '') 
        ); 


        if ($file_data = getattr($options['f'], false)) 
        {
            _log($file_data);
            $this->loadFileData($file_data);
        }
        elseif ($file_data = getattr($options['file'], false)) 
        {
            _log($file_data);
            $this->loadFileData($file_data);
        }
        elseif (array_key_exists('use-ftp', $options))
        { 
            $this->opt_ftp = array(
                'server'    => getattr($options['ftp-server']),
                'user'      => getattr($options['ftp-user']),
                'pass'      => getattr($options['ftp-pass']),
                'path'      => getattr($options['ftp-path'], DEFAULT_ATTRIBUTES['ftp-path']),
            );
        }
        else 
        {
            echo "El archivo " . $file_data . " no se ha encontrado o no se puede acceder\r\n";
            exit(0);
        }

        $this->sync();

        exit(0);
    }

}

// UTILS

function getattr(&$var, $default=null)
{
    return isset($var) ? $var : $default;
}

function pprint($str, $args=array())
{
    $_str = $str;
    foreach($args as $key => $val) {
        $_str = preg_replace('[{'.$key.'}]', $val, $_str);
    }
    return $_str;
}

function slugify($text)
{
    // replace non letter or digits by -
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    // transliterate
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    // remove unwanted characters
    $text = preg_replace('~[^-\w]+~', '', $text);
    // trim
    $text = trim($text, '-');
    // remove duplicate -
    $text = preg_replace('~-+~', '-', $text);
    // lowercase
    $text = strtolower($text);
    if (empty($text)) {
        return $text;
    }
    return $text;
}

function _log($message, $args=array(), $stdout = true)
{
    $message = pprint($message, $args);

    if ($stdout) {
        echo "[DEBUG] " . $message . "\r\n";
    } else {
        Mage::log($message, null, 'sync_products.log');
    }
}

function prompt($message, $choices = null)
{
    $handle = fopen ("php://stdin","r");

    if (!$choices) {

        echo "\033[33m" . $message . "\033[0m: ";
        $line = trim(fgets($handle));
        $choices = array('/^([Yy]|[Yy]es|[Ss]|[Ss]i)$/' => true, '/^([Nn]|[Nn]o)$/' => false);

        foreach($choices as $reg => $val) {
            if(preg_match($reg, trim($line))) return $val;
        }

        return null;

    } else {
        // array key -> (title, function)

        echo "\033[33m" . $message . "\033[0m: \r\n\r\n";

        foreach($choices as $key => $opt) {
            echo "\t" . $key . ") " . $opt[0] . "\r\n";
        }

        echo "\r\nIngrese opción: ";
        $line = trim(fgets($handle));

        //_log("\r\n". $line);

        if (array_key_exists($line, $choices)) {
            return $line; //$choices[$line];
        } else return prompt($message, $choices);

    }

    fclose($handle);

}

function boostrap()
{
    // init requires
    try {
        // script en base /
        if (file_exists('app/Mage.php' )) 
        {
            require_once("app/Mage.php");
        }
        // script en script/ 
        elseif (file_exists('../app/Mage.php' )) 
        {
            require_once("../app/Mage.php");
        } 
        elseif (file_exists('/var/www/magento/app/Mage.php' )) 
        {
            require_once('/var/www/magento/app/Mage.php');
        }
        else 
        {
            throw new Exception ('[/var/www/magento/]app/Mage.php does not exist');
        }
    }
    catch(Exception $e) {    
        echo "\r\nMessage : " . $e->getMessage();
        echo "\r\nCode : " . $e->getCode();
        echo "\r\n";
        exit(1);
    }

    //$mageFilename = 'app/Mage.php';
    //require_once $mageFilename;

    Mage::setIsDeveloperMode(true);
    ini_set('display_errors', 1);
    umask(0);
    Mage::app('admin');
    Mage::register('isSecureArea', 1);
    Mage::app()->setCurrentStore(Mage_Core_Model_App::ADMIN_STORE_ID);

    $urbanconnection_store_id = 1;
    $oneillstore_store_id = 3;
    define('STORE_ID', $urbanconnection_store_id); // 1

    $urbanconnection_parent_id = 2;
    $oneillstore_parent_id = 3;
    define('PARENT_ID', $urbanconnection_parent_id);

    define('DEFAULT_ATTRIBUTES', 4); // default
    define('DEFAULT_PRODUCT_TYPE', Mage_Catalog_Model_Product_Type::TYPE_SIMPLE); // default product type
    define('DEFAULT_PRODUCT_STATUS', 1); // product status (1 - enabled, 2 - disabled)
    define('DEFAULT_PRODUCT_VISIBILITY', Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH); // Catalog and Search visibility

}




// Start

$commands = new CommandUtilMagento;
$commands->init();

//$cProduct = Mage::getModel('catalog/product');
////set configurable product base data
//$cProduct->setTypeId(Mage_Catalog_Model_Product_Type::TYPE_CONFIGURABLE)
//            ->setWebsiteIds(array(1)) //Website Ids
//            ->setStatus(Mage_Catalog_Model_Product_Status::STATUS_DISABLED)
//            ->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)
//            ->setTaxClassId(2)
//            ->setAttributeSetId(1) // Attribute Set Id
//            ->setSku($sku)
//            ->setName($name)
//            ->setWeight($weight)
//            ->setShortDescription($short_description)
//            ->setDescription($description)
//            ->setPrice(sprintf("%0.2f", $price));
//$superAttributeIds = array('21'); //'21' attribute ids of super attributes
//$cProduct->getTypeInstance()->setUsedProductAttributeIds($superAttributeIds); //set super attribute for configurable product
//
///** assigning associated product to configurable */
//$associatedProductData = array();
//$option1 = array(
//     'label'            => 'option label',
//     'default_label     => 'option default label',
//     'store_label'      => 'option store label',
//     'attribute_id'     => '21',//'Super Attribute Id'
//     'value_index'      => '178', // attribute option Id,
//     'is_percent'       => '0', // 0 not percent, 1 is percent
//     'pricing_value'    => '20',
//     'use_default_value'=> '1'
//);
//
//$associatedProductData[93][] = $option; // 93 - simple product id
//
//$option2 = array(
//     'label'            => 'option label',
//     'default_label     => 'option default label',
//     'store_label'      => 'option store label',
//     'attribute_id'     => '21',//'Super Attribute Id'
//     'value_index'      => '180', // attribute option Id,
//     'is_percent'       => '0', // 0 not percent, 1 is percent
//     'pricing_value'    => '10',
//     'use_default_value'=> '1'
//);
//
//$associatedProductData[95][] = $option2; // 93 - simple product id
//
//$cProduct->setConfigurableProductsData($configurableProductsData);
//
//$configurableAttributesData[]['values'] = array($option1, $option2);
//$cProduct->setConfigurableAttributesData($configurableAttributesData);
//
//$cProduct->setCanSaveConfigurableAttributes(true);
//
//try{
//        $cProduct->save();
//     }catch( Exception $e) {
//        echo $e->getMessage();
//     }
//
//
