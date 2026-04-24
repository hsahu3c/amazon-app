<?php
namespace App\Shopifyhome\Components\Parser;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use App\Shopifyhome\Components\Parser\ChunkReadFilter;

class ReadProductCsv{

    public function readCsv($filepath,$filter){
        ini_set('memory_limit', '-1');
        if(!isset($filter['next'])){
            $startRow=0;
        }else{
            $startRow=$filter['next'];
        }

        $chunk=$filter['limit'];
        $inputFileType = 'Csv';
        $inputFileName = $filepath;
        $reader = IOFactory::createReader($inputFileType);
        $worksheetData = $reader->listWorksheetInfo($inputFileName);
        $totalrows=$worksheetData[0]['totalRows'];

        /**  Create a new Instance of our Read Filter  **/
        $chunkFilter = new ChunkReadFilter();


        $reader->setReadFilter($chunkFilter)
            ->setContiguous(true);

        /**  Instantiate a new Spreadsheet object manually  **/
        $spreadsheet = new Spreadsheet();

        /**  Set a sheet index  **/
        $sheet = 0;

        $chunkFilter->setRows($startRow,$chunk);

        $reader->setSheetIndex($sheet);

        $reader->loadIntoExisting($inputFileName,$spreadsheet);

        $spreadsheet->getActiveSheet()->setTitle('Country Data #'.(++$sheet));

        $sheetData = $spreadsheet->getActiveSheet()->toArray();

        if (!empty($sheetData)) {
            $responsearray=[];
            for ($i=1; $i<count($sheetData); $i++) {
                $res=[];
                for($j=0; $j<count($sheetData[$i]); $j++){
                    $res[$sheetData[0][$j]] = $sheetData[$i][$j];
                }

                array_push($responsearray,$res);
            }

            $startRow=$startRow+$chunk;
            if($startRow>$totalrows){
                return [
                    'success'=>false,
                    'totalrows'=>$totalrows,
                    'data'=>$responsearray,
                    'next'=>""
                ];
            }
            return [
                'totalrows'=>$totalrows,
                'next'=>$startRow,
                'data'=>$responsearray
            ];
        }
    }

    public function CsvProductDataFormat($intermediateResponse){
        $totalproduct=[];
        $singleproductdata=[];
        foreach ($intermediateResponse as $productvariant){
           $handle=$productvariant['Handle'];
           if(isset($oldhandle)){
               if($handle==$oldhandle){
                   array_push($singleproductdata,$productvariant);
               }else{
                   array_push($totalproduct,$singleproductdata);
                   $singleproductdata=[];
                   array_push($singleproductdata,$productvariant);
               }

               $oldhandle=$handle;
           }else{
               $oldhandle=$handle;
               array_push($singleproductdata,$productvariant);
           }
        }

       $productsdata=$this->FormatSingleProducts($totalproduct);
       return [
           'success'=>true,
           'data'=>$productsdata
       ] ;
    }

    public function FormatSingleProducts($products){
       $allproducts['Product']=[];
       foreach ($products as $product){
            $coredata=$this->formatProductCoreData($product);
            $productvariantsdata['ProductVariant']=$this->formatProductVariantsData($product);
            $singleproduct=array_merge($coredata,$productvariantsdata);
           array_push($allproducts['Product'],$singleproduct);
       }

       return $allproducts;
    }

    public function formatProductCoreData($product){
        $coredata=[];
        $coredata['id']='gid://shopify/Product/'.random_int(0, mt_getrandmax());
        $coredata['title']=$product[0]['Title'];
        $coredata['vendor']=$product[0]['Vendor'];
        $coredata['productType']=$product[0]['Type'];
        $coredata['descriptionHtml']=$product[0]['Body (HTML)'];
        $coredata['handle']=$product[0]['Handle'];
        $coredata['tags']=[$product[0]['Tags']];
        $coredata['templateSuffix']="";
        $coredata['featuredImage']['id']='gid://shopify/ProductImage/'.random_int(0, mt_getrandmax());
        $coredata['featuredImage']['originalSrc']=$product[0]['Image Src'];
        $coredata['featuredImage']['transformedSrc']= $product[0]['Image Src'];
        $coredata['publishedAt']=date("Y-m-d H:i:s");
        $coredata['createdAt']=date("Y-m-d H:i:s");
        $coredata['updatedAt']=date("Y-m-d H:i:s");
        $coredata['ProductImage'][random_int(0, mt_getrandmax())]=$coredata['featuredImage'];
        return $coredata;
    }

    public function formatProductVariantsData($product){
        $allproductvariantarr=[];
        $productvariantarr=[];
        $optionames=[];
        $count=0;
        foreach($product as $productvariant){
           $productvariantarr['id']='gid://shopify/ProductVariant/'.random_int(0, mt_getrandmax());
             if($count==0){
                 $productvariantarr['selectedOptions']=[];
                 $arr=[];
                 foreach ($productvariant as $key => $value) {
                     if (preg_match('/^Option/', (string) $key)) {
                         $pieces = explode(' ', (string) $key);
                         if($pieces[1]=="Name"){
                             $arr['name']=$value;
                             array_push($optionames,$value);
                         }

                         if($pieces[1]=="Value"){
                             $arr['value']=$value;
                         }

                         if(count($arr)==2){
                             if($arr['value']!="") {
                                 array_push($productvariantarr['selectedOptions'], $arr);
                                 $arr = [];
                             }
                         }
                     }
                 }
             }else{
                 $productvariantarr['selectedOptions']=[];
                 $arr=[];
                 $flag=0;
                 foreach ($productvariant as $key => $value) {
                     if (preg_match('/^Option/', (string) $key)) {
                         $pieces = explode(' ', (string) $key);
                         if($pieces[1]=="Name"){
                             $arr['name']=$optionames[$flag];
                             $flag+=1;
                         }

                         if($pieces[1]=="Value"){
                             $arr['value']=$value;
                         }

                         if(count($arr)==2){
                             if($arr['value']!=""){
                                 array_push($productvariantarr['selectedOptions'],$arr);
                                 $arr=[];
                             }
                         }
                     }
                 }
             }

            $count+=1;;
            $title="";
            foreach ($productvariantarr['selectedOptions'] as $v){
                if($v['value']!=""){
                    $title.=$v['value']." / ";
                }
            }

            $productvariantarr['title']=substr($title,0,(strlen($title)-2));
            $productvariantarr['position']=$count;
            $productvariantarr['sku']=$productvariant['Variant SKU'];
            $productvariantarr['price']=$productvariant['Variant Price'];
            $productvariantarr['compareAtPrice']=$productvariant['Variant Compare At Price'];
            $productvariantarr['inventoryQuantity']=$productvariant['Variant Inventory Qty'];
            $productvariantarr['weight']=$productvariant['Variant Grams'];
            $productvariantarr['weightUnit']=$productvariant['Variant Weight Unit'];
            $productvariantarr['barcode']=$productvariant['Variant Barcode'];
            $productvariantarr['inventoryPolicy']=$productvariant['Variant Inventory Policy'];
            $productvariantarr['taxable']=$productvariant['Variant Taxable'];
            $productvariantarr['createdAt']=date("Y-m-d H:i:s");
            $productvariantarr['updatedAt']=date("Y-m-d H:i:s");
            $productvariantarr['fulfillmentService']['handle']=$productvariant['Variant Fulfillment Service'];
            $productvariantarr['fulfillmentService']['serviceName']=$productvariant['Variant Fulfillment Service'];
            $productvariantarr['image']['id']='gid://shopify/ProductImage/'.random_int(0, mt_getrandmax());
            $productvariantarr['image']['originalSrc']=$productvariant['Variant Image'];
            $productvariantarr['image']['transformedSrc']= $productvariant['Variant Image'];
            $productvariantarr['inventoryItem']['id']= "gid://shopify/InventoryItem/".random_int(0, mt_getrandmax());
            $productvariantarr['inventoryItem']['tracked']= 1;
            $productvariantarr['inventoryItem']['requiresShipping']= $productvariant['Variant Requires Shipping'];
            array_push($allproductvariantarr,$productvariantarr);
        }

        return $allproductvariantarr;
    }

}