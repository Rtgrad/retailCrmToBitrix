<?php
require_once 'vendor/autoload.php';  //Подключяем библиотеку RetailCrm
require_once $_SERVER[ 'DOCUMENT_ROOT' ] . '/bitrix/modules/main/include/prolog_before.php';
ini_set ( 'display_errors' , 0 );
define ( 'NO_KEEP_STATISTIC' , true );
define ( 'NOT_CHECK_PERMISSIONS' , true );
\Bitrix\Main\Loader ::includeModule ( 'sale' );

/**
 * Обратная интеграция заказов с RetailCrm на Bitrix
 * Class retToBit
 */
class retToBit
{
    /**
     * @var \RetailCrm\ApiClient
     */
    private $client;

    /**
     * Подключение базы данных Битрикса
     * @var
     */
    private $connection;

    /**
     * ID заказа ,
     * который надо получить через триггер на создание заказа
     * @var
     */
    private $id;

    /**
     * Массив внешних Id товаров из RetailCrm
     * @var array
     */
    private $retail_items_externalId;

    /**
     * Массив внешних Id товаров из Битрикса
     * @var array
     */
    private $bx_items_externalID;

    /**
     * Настраиваем подключение retail и bitrix
     * и получаем заказ с обеих сторон для дальнешего сравнения
     * retToBit constructor.
     */
    public function __construct ( $site , $token , $id )
    {
        $this -> id = $id;

        //подключение RetailCrm
        $this -> client = new \RetailCrm\ApiClient(
            $site ,
            $token ,
            \RetailCrm\ApiClient::V5
        );

        //подключение базы данных Bitrix
        $this -> connection = Bitrix\Main\Application ::getConnection ();

        /**
         * Получаем  товары с Битрикс
         */
        $dbItemsInOrder = CSaleBasket ::GetList ( array ( "ID" => "ASC" ) , array ( "ORDER_ID" => $id ) ); //товар

        $bx_items_externalID = [];

        foreach ( $dbItemsInOrder -> arResult as $bxItem ) {
            $bx_items_externalID[] = $bxItem[ 'PRODUCT_ID' ];
        }


        /**
         * Получаем товар с RetailCrm
         */
        $get_order = $this -> client -> request -> ordersGet ( $id );
        $retail_items_externalId = [];
        foreach ( $get_order -> order[ 'items' ] as $item ) {
            $retail_items[] = $item;
            $retail_items_externalId[] = $item[ 'offer' ][ 'externalId' ];
        }
        $this -> retail_items_externalId = $retail_items_externalId;
        $this -> bx_items_externalID = $bx_items_externalID;
        $this -> retail_items = $retail_items;
    }

    /**
     * Сравниваем externalId и добавляем отличие
     * @param $id
     * @return $this
     */
    function changeProducts ( $id )
    {
        $connection = $this -> connection;
        $retail_items_externalId = $this -> retail_items_externalId;
        $bx_items_externalID = $this -> bx_items_externalID;
        $retail_items = $this -> retail_items;

        $new_item_externalId = array_diff ( $retail_items_externalId , $bx_items_externalID );
        if ( !empty( $new_item_externalId ) ) {
            foreach ( $retail_items as $item ) {
                foreach ( $new_item_externalId as $external ) {
                    if ( $external == $item[ 'offer' ][ 'externalId' ] ) {

                        $res = CIBlockElement ::GetList (
                            Array ( "ID" => "DESC" ) ,
                            Array ( "ID" => $item[ 'offer' ][ 'externalId' ] ) ,
                            false ,
                            Array () ,
                            Array ( "IBLOCK_ID" , "DETAIL_PAGE_URL" )
                        );
                        $detail_page_url = $res -> GetNext (); //Получаем DETAIL_PAGE_URL

                        if ( !in_array ( $item[ 'offer' ][ 'externalId' ] , $bx_items_externalID ) ) {
                            //добавляем через БД
                            $sql = "INSERT INTO b_sale_basket (ORDER_ID,PRODUCT_ID,PRICE,CURRENCY,QUANTITY,LID,DELAY,CAN_BUY,NAME,MODULE,DETAIL_PAGE_URL) VALUES (" . $id . ", " . $item[ 'offer' ][ 'externalId' ] . "," . $item[ 'initialPrice' ] . ",'RUB'," . $item[ 'quantity' ] . ",'s1','N','Y','" . $item[ 'offer' ][ 'displayName' ] . "','catalog','" . $detail_page_url[ 'DETAIL_PAGE_URL' ] . "')";
                            $connection -> query ( $sql );
                        }
                    }
                }
            }
        }

        //Вызываем метод удаления "delProds" только после добавления товаров
        $this -> delProds ( $id );
        return $this;
    }

    /**
     * Сравниваем externalId и удаляем отличие
     * (Метод вызывет в changeProducts)
     * @param $id
     * @return $this
     */
    function delProds ( $id )
    {
        $connection = $this -> connection;
        $retail_items_externalId = $this -> retail_items_externalId;

        /**
         * Страховка от Дублей
         * (берем все новые добавленные товары вместе с дублями и удаляем их)
         */
        $dbItemsInOrder_double = CSaleBasket ::GetList ( array ( "ID" => "ASC" ) , array ( "ORDER_ID" => $id ) ); //товар

        $bx_items_externalID_double = [];

        foreach ( $dbItemsInOrder_double -> arResult as $bxItem ) {
            $bx_items_externalID_double[] = $bxItem[ 'PRODUCT_ID' ];
        }

        $remove_item_externalId = array_diff ( $bx_items_externalID_double , $retail_items_externalId );
        if ( !empty( $remove_item_externalId ) ) {
            $remove_item_externalId = array_diff ( $bx_items_externalID_double , $retail_items_externalId );
            foreach ( $remove_item_externalId as $ext_id ) {
                $get_item_id = CSaleBasket ::GetList ( array ( "ID" => "ASC" ) , array ( "ORDER_ID" => $id , 'PRODUCT_ID' => $ext_id ) );

                //Удаляем через БД
                $sql = "DELETE FROM b_sale_basket WHERE ID=" . $get_item_id -> arResult[ 0 ][ 'ID' ];
                $connection -> query ( $sql );
            }
        }
        return $this;
    }

    /**
     * Изменение количесто товров
     * @param $id
     * @return $this
     */
    function changeProductsQuantity ( $id )
    {
        $get_order = $this -> client -> request -> ordersGet ( $id );
        $connection = $this -> connection;

        foreach ( $get_order -> order[ 'items' ] as $item ) {
            $sql = "UPDATE b_sale_basket SET QUANTITY=" . $item[ 'quantity' ] . " WHERE ORDER_ID=" . $id . " AND PRODUCT_ID=" . $item[ 'offer' ][ 'externalId' ];
            $connection -> query ( $sql );
        }
        return $this;
    }

    /**
     * Изменение итоговой суммы товров
     * @param $id
     * @return $this
     */
    function changeTotalSum ( $id )
    {
        $connection = $this -> connection;
        $get_order = $this -> client -> request -> ordersGet ( $id );

        $totalSumm = $get_order -> order[ 'totalSumm' ];
        $sql = "UPDATE b_sale_order SET PRICE=" . $totalSumm . " WHERE ID=" . $id;
        $connection -> query ( $sql );
        return $this;
    }

    /**
     * Изменение способа доставки
     * @param $id
     * @param $delivery
     * @return $this
     */
    function сhangeDelivery ( $id , $delivery )
    {
        $connection = $this -> connection;

        $ret_order = $this -> client -> request -> ordersGet ( $id ) -> order;


        if ( !empty( $ret_order[ 'delivery' ][ 'code' ] ) ) {
            $sql = "UPDATE  b_sale_order_delivery SET DELIVERY_ID='" . $delivery[ 'id' ][ $ret_order[ 'delivery' ][ 'code' ] ] . "',"
                . "PRICE_DELIVERY=" . $ret_order[ 'delivery' ][ 'cost' ] . ",DELIVERY_NAME='" . $delivery[ 'name' ][ $ret_order[ 'delivery' ][ 'code' ] ] . "'"
                . " WHERE ACCOUNT_NUMBER='" . $id . "/2'";

            $connection -> query ( $sql );
        }
        return $this;
    }

    /**
     * Изменение Статуса Заказа
     * @param $id
     * @param $statuses
     * @return $this
     */
    function changeStatus ( $id , $statuses )
    {
        $ret_order = $this -> client -> request -> ordersGet ( $id ) -> order;
        $connection = $this -> connection;

        if ( array_key_exists ( $ret_order[ 'status' ] , $statuses ) ) {
            $sql = "UPDATE b_sale_order SET STATUS_ID='" . $statuses[ $ret_order[ 'status' ] ] . "'" .
                " WHERE ID=" . $id;
            $connection -> query ( $sql );
        }
        return $this;
    }

    /**
     * Изменение типа оплаты и статуса оплаты
     * @param $id
     * @param $pay_system
     * @return $this
     */
    function changePayment ( $id , $pay_system )
    {
        $connection = $this -> connection;
        $ret_order = $this -> client -> request -> ordersGet ( $id ) -> order;

        $totalSumm = $ret_order[ 'totalSumm' ];

        $sql = "UPDATE b_sale_order_payment SET SUM=" . floatval ( $totalSumm ) . ",PAY_SYSTEM_NAME='" . $pay_system[ 'name' ][ reset ( $ret_order[ 'payments' ] )[ 'type' ] ] . "',PAY_SYSTEM_ID=" . $pay_system[ 'code' ][ reset ( $ret_order[ 'payments' ] )[ 'type' ] ] . " ,PAID='" . $pay_system[ 'status' ][ reset ( $ret_order[ 'payments' ] )[ 'status' ] ] . "'" . " WHERE ACCOUNT_NUMBER=" . $id . "/1";
        $connection -> query ( $sql );
        return $this;
    }

    /**
     * Кастомная логика
     * @param $id
     * @return $this
     */
    function customLogic ( $id )
    {
        $ret_order = $this -> client -> request -> ordersGet ( $id ) -> order;
        $connection = $this -> connection;

        /**
         * Меняем стоимость доставки в заказе при Самовывозе (HardCode)
         */
        $delivery_cost[ 'externalId' ] = $id;
        if ( $ret_order[ 'delivery' ][ 'code' ] == 'self-delivery' ) {
            $delivery_cost[ 'delivery' ][ 'cost' ] = 0;
        } elseif ( $ret_order[ 'delivery' ][ 'code' ] == '22' ) {
            $delivery_cost[ 'delivery' ][ 'cost' ] = 305;
        } elseif ( $ret_order[ 'delivery' ][ 'code' ] == 'russian-post' ) {
            $delivery_cost[ 'delivery' ][ 'cost' ] = 155;
        }

        $this -> client -> request -> ordersEdit ( $delivery_cost );
        $sql = "UPDATE b_sale_order SET PRICE_DELIVERY=" . floatval ( $delivery_cost[ 'delivery' ][ 'cost' ] ) .
            " WHERE ID=" . $id;
        $connection -> query ( $sql );

        return $this;
    }

    /**
     * Очищаем кэш
     * (для моментального отображения изменений на Битриксе)
     * @return $this
     */
    function clearСache ()
    {
        $obCache = new CPHPCache();
        $obCache -> CleanDir ();
        return $this;
    }
}

